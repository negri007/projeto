<?php
/**
 * O aluno envia as respostas do quiz ativo — todas de uma vez, uma vez só.
 * O professor não responde. O PHP confere cada alternativa contra o
 * gabarito; o cliente nunca diz se acertou.
 *
 * POST JSON: { "quiz_id": <id>, "respostas": { "<questao_id>": <0-3>, ... } }
 * Resposta: { ok:true, quiz:{... visão de quem respondeu, com gabarito ...} }
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

try {
    $data      = json_decode(file_get_contents("php://input"), true);
    $quizId    = (int)($data["quiz_id"] ?? 0);
    $enviadas  = is_array($data["respostas"] ?? null) ? $data["respostas"] : [];

    // O quiz leva ao material, e o material à turma: acesso pela sessão.
    $stmt = $pdo->prepare("SELECT material_id FROM turma_quizzes WHERE id = ? AND ativo = 1");
    $stmt->execute([$quizId]);
    $materialId = (int)$stmt->fetchColumn();

    $material = $materialId > 0 ? turma_material_load($pdo, $materialId, $userId) : null;

    if ($material === null) {
        echo json_encode(["error" => "Quiz não encontrado."]);
        exit;
    }

    if ($material["is_owner"]) {
        echo json_encode(["error" => "O professor não responde o quiz."]);
        exit;
    }

    $quiz = turma_quiz_ativo($pdo, $materialId);

    if ($quiz === null || $quiz["id"] !== $quizId) {
        echo json_encode(["error" => "Quiz não encontrado."]);
        exit;
    }

    if (count(turma_quiz_respostas_do_aluno($pdo, $quizId, $userId)) > 0) {
        echo json_encode(["error" => "Você já respondeu este quiz."]);
        exit;
    }

    // Todas as questões respondidas, cada uma com índice 0-3.
    $linhas = [];

    foreach ($quiz["questoes"] as $q) {
        $v = $enviadas[(string)$q["id"]] ?? null;

        if (!is_int($v) || $v < 0 || $v > 3) {
            echo json_encode(["error" => "Responda todas as questões."]);
            exit;
        }

        $linhas[] = [$quizId, $q["id"], $userId, $v, $v === $q["correta"] ? 1 : 0];
    }

    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO turma_quiz_respostas (quiz_id, questao_id, user_id, escolhida, acertou)
         VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($linhas as $l) {
        $ins->execute($l);
    }

    $pdo->commit();

    echo json_encode([
        "ok"   => true,
        "quiz" => turma_quiz_visao($quiz, false, turma_quiz_respostas_do_aluno($pdo, $quizId, $userId)),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 23000 = UNIQUE (questao, aluno): dois envios ao mesmo tempo.
    if ($e->getCode() === "23000") {
        echo json_encode(["error" => "Você já respondeu este quiz."]);
        exit;
    }
    error_log("turmas/quiz_responder: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao enviar as respostas."]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("turmas/quiz_responder: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao enviar as respostas."]);
}
