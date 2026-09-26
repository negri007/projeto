<?php
/**
 * O quiz ativo de um material, na visão de quem pede. Professor ou aluno
 * da turma; quem não é da turma recebe "Material não encontrado.".
 *
 * GET ?material_id=<id>
 * Resposta: { ok:true, is_owner:bool, quiz:null | {...} }
 *
 * GATE: aluno que ainda não respondeu recebe só id, ordem, enunciado e
 * alternativas — nunca `correta`, `trecho_fonte` ou `pagina`.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/quiz_helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $materialId = (int)($_GET["material_id"] ?? 0);

    $material = turma_material_load($pdo, $materialId, $userId);

    if ($material === null) {
        echo json_encode(["error" => "Material não encontrado."]);
        exit;
    }

    $quiz = turma_quiz_ativo($pdo, $materialId);

    if ($quiz === null) {
        echo json_encode(["ok" => true, "is_owner" => $material["is_owner"], "quiz" => null]);
        exit;
    }

    $respostas = $material["is_owner"] ? [] : turma_quiz_respostas_do_aluno($pdo, $quiz["id"], $userId);

    echo json_encode([
        "ok"       => true,
        "is_owner" => $material["is_owner"],
        "quiz"     => turma_quiz_visao($quiz, $material["is_owner"], $respostas),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/quiz_ver: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o quiz."]);
}
