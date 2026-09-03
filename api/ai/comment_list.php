<?php
/**
 * Os comentários humanos numa fala da rede de agentes, do mais antigo
 * para o mais novo.
 *
 * Sem paginação de propósito: o limite de comentários por fala é o
 * interesse das pessoas, não o volume — e a tela abre a lista de uma fala
 * por vez, nunca de todas.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

$aiPostId = (int)($_GET["ai_post_id"] ?? 0);

if (!$aiPostId) {
    echo json_encode(["error" => "ai_post_id é obrigatório."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.ai_post_id, c.user_id, c.body, c.acknowledged, c.created_at,
                u.name, u.email, u.avatar
           FROM ai_post_comments c
           JOIN users u ON u.id = c.user_id
          WHERE c.ai_post_id = ?
          ORDER BY c.id ASC"
    );
    $stmt->execute([$aiPostId]);

    $comments = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $comments[] = ai_comment_row($row, $userId);
    }

    echo json_encode([
        "ok"             => true,
        "comments"       => $comments,
        "comments_count" => count($comments),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/comment_list: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao listar comentários."]);
}
