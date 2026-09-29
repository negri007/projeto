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
require_once __DIR__ . "/../ai/limite_uso.php";

$userId = require_login();

// A chamada de IA pode levar até 60 s (e a espera pela trava abaixo, até
// 90 s). Segurar o arquivo de sessão esse tempo todo trava todas as outras
// abas desta pessoa — ver liberar_sessao() em auth/session.php. Nada daqui
// para baixo escreve em $_SESSION.
liberar_sessao();

/** Quanto um pedido espera o outro terminar de gerar o mesmo resumo. */
const TURMA_RESUMO_ESPERA_S = 90;

// Espera pela trava + chamada de IA passam do limite padrão do PHP.
@set_time_limit(180);

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

    // Um resumo por vez por material. Sem isto, dois alunos abrindo o
    // material novo ao mesmo tempo (ou um duplo clique em regerar) pagavam
    // duas chamadas de API pelo mesmo texto. GET_LOCK é por conexão: se o
    // PHP morrer no meio, o MySQL solta a trava sozinho.
    $trava = "echo_turma_resumo_" . $materialId;
    $stmt  = $pdo->prepare("SELECT GET_LOCK(?, ?)");
    $stmt->execute([$trava, TURMA_RESUMO_ESPERA_S]);

    if ((int)$stmt->fetchColumn() !== 1) {
        echo json_encode([
            "error" => "O resumo deste material está sendo gerado. Tente de novo em instantes.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Quem esperou relê: se o outro pedido gravou o resumo enquanto
        // esta trava estava com ele, é esse resumo que vale — sem API e
        // sem gastar cota. `resumo_em` diferente do lido lá em cima também
        // conta, para o duplo clique em regerar não gerar duas vezes.
        $stmt = $pdo->prepare("SELECT resumo, resumo_em FROM turma_materiais WHERE id = ?");
        $stmt->execute([$materialId]);
        $agora = $stmt->fetch(PDO::FETCH_ASSOC) ?: ["resumo" => null, "resumo_em" => null];

        $resumoAgora = trim((string)($agora["resumo"] ?? ""));
        $outroGerou  = $resumoAgora !== "" && ($agora["resumo_em"] ?? null) !== ($material["resumo_em"] ?? null);

        if ($resumoAgora !== "" && (!$regerar || $outroGerou)) {
            echo json_encode(["ok" => true, "resumo" => $resumoAgora, "do_cache" => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Daqui em diante é chamada de verdade à API (o cache já saiu
        // acima). Paga a vaga quem pediu — aluno abrindo o primeiro resumo
        // ou professor regerando —, conferida e registrada pelo cliente da
        // API dentro de turma_resumir_material(). Material só de imagem
        // não vai à API.
        $resultado = turma_resumir_material($material, $pdo, $userId);

        if (!$resultado["ok"]) {
            if (isset($resultado["espera"])) {
                http_response_code(429);
            }
            echo json_encode(["error" => $resultado["erro"]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE turma_materiais SET resumo = ?, resumo_em = NOW() WHERE id = ?"
        );
        $stmt->execute([$resultado["resumo"], $materialId]);

        echo json_encode(["ok" => true, "resumo" => $resultado["resumo"], "do_cache" => false], JSON_UNESCAPED_UNICODE);
    } finally {
        // Solta já no caminho normal e no de exceção. Os `exit` acima (o
        // 429 de cota incluído) NÃO passam por este finally — PHP não
        // executa finally em exit —, mas neles a trava cai do mesmo jeito:
        // é da conexão, e a conexão (não persistente) fecha no fim da
        // requisição.
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$trava]);
    }

} catch (Throwable $e) {
    error_log("turmas/material_resumir: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar o resumo."]);
}
