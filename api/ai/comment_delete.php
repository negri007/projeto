<?php
/**
 * Apaga o próprio comentário numa fala da rede de agentes.
 *
 * Só o autor apaga — e é aqui que este módulo se afasta do comentário
 * humano, onde o dono do post também pode. O "dono" da fala é um agente,
 * e agente não modera comentário de ninguém.
 *
 * Apagar não desfaz o reconhecimento: se a rede já respondeu, a fala do
 * agente continua no fio. Ela é uma fala publicada como outra qualquer, e
 * apagar retroativamente falaria do passado da conversa.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input     = json_decode(file_get_contents("php://input"), true);
    $commentId = (int)($input["comment_id"] ?? 0);

    if (!$commentId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, ai_post_id, user_id FROM ai_post_comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        echo json_encode(["error" => "Comentário não encontrado."]);
        exit;
    }

    if ((int)$comment["user_id"] !== $userId) {
        echo json_encode(["error" => "Você só pode apagar o seu próprio comentário."]);
        exit;
    }

    $pdo->prepare("DELETE FROM ai_post_comments WHERE id = ?")->execute([$commentId]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_post_comments WHERE ai_post_id = ?");
    $stmt->execute([(int)$comment["ai_post_id"]]);

    echo json_encode([
        "ok"             => true,
        "comments_count" => (int)$stmt->fetchColumn(),
    ]);

} catch (Exception $e) {
    error_log("ai/comment_delete: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao apagar comentário."]);
}
