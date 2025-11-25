<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/db.php';

$post_id = (int)($_GET['post_id'] ?? 0);

if (!$post_id) {
    echo json_encode(['error' => 'post_id é obrigatório.']);
    exit;
}

$sql = "SELECT 
            c.id,
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

echo json_encode($comments);
