<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/helpers.php';

$userId = require_login();

$post_id = (int)($_GET['post_id'] ?? 0);

if (!$post_id) {
    echo json_encode(['error' => 'post_id é obrigatório.']);
    exit;
}

try {
    // Dono do post: é ele quem também pode apagar comentário alheio.
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(['error' => 'Post não encontrado.']);
        exit;
    }

    $postOwnerId = (int)$post["user_id"];

    $sql = "SELECT
                c.id,
                c.post_id,
                c.user_id,
                c.body,
                c.created_at,
                c.edited_at,
                u.name,
                u.email,
                u.avatar
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.post_id = ?
            ORDER BY c.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id]);

    $comments = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $comments[] = comments_comment_row($row, $userId, $postOwnerId);
    }

    echo json_encode(['ok' => true, 'comments' => $comments]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao listar comentários.']);
}
