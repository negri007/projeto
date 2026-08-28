<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/db.php';

require_login();

$post_id = (int)($_GET['post_id'] ?? 0);

if (!$post_id) {
    echo json_encode(['error' => 'post_id é obrigatório.']);
    exit;
}

try {
    $sql = "SELECT
                c.id,
                c.post_id,
                c.user_id,
                c.body,
                c.created_at,
                u.name,
                u.email
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.post_id = ?
            ORDER BY c.created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id]);

    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'comments' => $comments]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao listar comentários.']);
}
