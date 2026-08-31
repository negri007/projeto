<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../notifications/helpers.php';

$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método inválido.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$post_id = (int)($data['post_id'] ?? 0);
$body    = trim($data['body'] ?? '');

if (!$post_id || $body === '') {
    echo json_encode(['error' => 'post_id e comentário são obrigatórios.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, user_id FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(['error' => 'Post não encontrado.']);
        exit;
    }

    $sql = "INSERT INTO comments (post_id, user_id, body, created_at)
            VALUES (?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id, $userId, $body]);

    $commentId = (int)$pdo->lastInsertId();

    // Devolve o comentário já montado para o front renderizar sem
    // precisar recarregar a lista inteira.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.post_id, c.user_id, c.body, c.created_at, u.name, u.email
         FROM comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    // `reference_id` é o post, não o comentário: o front navega
    // para o post ao clicar na notificação.
    notify($pdo, (int)$post['user_id'], $userId, 'comment', $post_id);

    echo json_encode(['ok' => true, 'comment' => $comment]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao criar comentário.']);
}
