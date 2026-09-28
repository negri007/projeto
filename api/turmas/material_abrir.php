<?php
/**
 * Abre um material: devolve o conteúdo para quem é da turma e, se for
 * aluno, registra a abertura (sinal "não abriu" do alerta de risco).
 *
 * POST JSON: { "material_id": <id> }
 * Resposta: { ok:true, material:{ id, titulo, tipo_arquivo, conteudo_texto,
 *             arquivo_url } }
 *
 * `arquivo_url` aponta para material_arquivo.php, que confere a turma de
 * novo a cada pedido — a URL sozinha não abre nada. Quem não é da turma
 * recebe o mesmo "Material não encontrado." de um id inexistente.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $data       = json_decode(file_get_contents("php://input"), true);
    $materialId = (int)($data["material_id"] ?? 0);

    $material = turma_material_load($pdo, $materialId, $userId);

    if ($material === null) {
        echo json_encode(["error" => "Material não encontrado."]);
        exit;
    }

    turma_registrar_view($pdo, $material, $userId);

    $texto = trim((string)($material["conteudo_texto"] ?? ""));

    echo json_encode([
        "ok"       => true,
        "material" => [
            "id"             => $material["id"],
            "titulo"         => $material["titulo"],
            "tipo_arquivo"   => $material["tipo_arquivo"],
            "conteudo_texto" => $texto !== "" ? $texto : null,
            "arquivo_url"    => turma_material_url($material),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/material_abrir: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao abrir o material."]);
}
