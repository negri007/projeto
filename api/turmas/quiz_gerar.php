<?php
/**
 * Gera (ou devolve do cache) o quiz de um material. SÓ o professor (dono
 * da turma) — gerar gasta API do Sonnet.
 *
 * POST JSON: { "material_id": <id>, "regerar": false }
 * Resposta: { ok:true, quiz:{... visão do professor, com gabarito ...}, do_cache:bool }
 *
 * Cache: com quiz ativo e sem `regerar`, devolve o existente sem chamar a
 * API. `regerar:true` desativa o quiz anterior e cria outro.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/quiz_helpers.php";

$userId = require_login();
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

// A geração com o Sonnet pode passar do max_execution_time padrão.
@set_time_limit(180);

try {
    $data       = json_decode(file_get_contents("php://input"), true);
    $materialId = (int)($data["material_id"] ?? 0);
    $regerar    = !empty($data["regerar"]);

    $material = turma_material_load($pdo, $materialId, $userId);

    if ($material === null) {
        echo json_encode(["error" => "Material não encontrado."]);
        exit;
    }

    if (!$material["is_owner"]) {
        echo json_encode(["error" => "Só o professor pode gerar o quiz."]);
        exit;
    }

    $ativo = turma_quiz_ativo($pdo, $materialId);

    if ($ativo !== null && !$regerar) {
        echo json_encode([
            "ok"       => true,
            "quiz"     => turma_quiz_visao($ativo, true, []),
            "do_cache" => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = turma_quiz_gerar($material);

    if (!$resultado["ok"]) {
        echo json_encode(["error" => $resultado["erro"]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Troca atômica: desativa o anterior e grava o novo numa transação,
    // pra nunca sobrar dois ativos nem um quiz sem questões.
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE turma_quizzes SET ativo = 0 WHERE material_id = ?")
        ->execute([$materialId]);

    $pdo->prepare(
        "INSERT INTO turma_quizzes (material_id, circle_id, criado_por, modelo, tokens_in, tokens_out)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $materialId, $material["circle_id"], $userId,
        $resultado["modelo"], $resultado["tokens_in"], $resultado["tokens_out"],
    ]);

    $quizId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare(
        "INSERT INTO turma_quiz_questoes (quiz_id, ordem, enunciado, alternativas, correta, assunto, trecho_fonte, pagina)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($resultado["questoes"] as $i => $q) {
        $ins->execute([
            $quizId, $i + 1, $q["enunciado"],
            json_encode($q["alternativas"], JSON_UNESCAPED_UNICODE),
            $q["correta"], $q["assunto"], $q["trecho_fonte"], $q["pagina"],
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "ok"       => true,
        "quiz"     => turma_quiz_visao(turma_quiz_ativo($pdo, $materialId), true, []),
        "do_cache" => false,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("turmas/quiz_gerar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar o quiz."]);
}
