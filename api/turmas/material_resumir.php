<?php
/**
 * Gera (ou devolve do cache) o resumo de um material. Dono ou membro da
 * turma podem pedir — o resumo é o mesmo para todos, gerado uma vez.
 *
 * POST JSON: { "material_id": <id>, "regerar": false }
 * Resposta: { ok:true, resumo:"...", do_cache:bool }
 *
 * O resumo fica cacheado em turma_materiais.resumo: gera na primeira vez,
 * serve das próximas sem gastar API de novo. `regerar:true` força de novo
 * (só o dono da turma pode).
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $data       = json_decode(file_get_contents("php://input"), true);
    $materialId = (int)($data["material_id"] ?? 0);
    $regerar    = !empty($data["regerar"]);

    $material = turma_material_load($pdo, $materialId, $userId);

    if ($material === null) {
        echo json_encode(["error" => "Material não encontrado."]);
        exit;
    }

    // Aluno abrindo o resumo conta como "abriu o material" (alerta de risco).
    turma_registrar_view($pdo, $material, $userId);

    // Cache: se já há resumo e não é pedido de regerar, devolve na hora.
    $cache = trim((string)($material["resumo"] ?? ""));

    if ($cache !== "" && !$regerar) {
        echo json_encode(["ok" => true, "resumo" => $cache, "do_cache" => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Regerar gasta API: só o dono da turma.
    if ($regerar && !$material["is_owner"]) {
        echo json_encode(["error" => "Só o professor pode gerar o resumo de novo."]);
        exit;
    }

    $resultado = turma_resumir_material($material);

    if (!$resultado["ok"]) {
        echo json_encode(["error" => $resultado["erro"]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE turma_materiais SET resumo = ?, resumo_em = NOW() WHERE id = ?"
    );
    $stmt->execute([$resultado["resumo"], $materialId]);

    echo json_encode(["ok" => true, "resumo" => $resultado["resumo"], "do_cache" => false], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/material_resumir: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar o resumo."]);
}
