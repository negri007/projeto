<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/db.php';

$email = $_GET['email'] ?? '';

if (!$email) {
    echo json_encode(['ok' => false, 'error' => 'Email não fornecido.']);
    exit;
}

try {
    // pega usuário + campos de perfil
    $stmt = $pdo->prepare("SELECT id, name, email, bio, avatar FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'Usuário não encontrado.']);
        exit;
    }

    // estatísticas básicas
    $statsQuery = "
        SELECT
            (SELECT COUNT(*) FROM posts      WHERE user_id   = ?) AS posts,
            (SELECT COUNT(*) FROM post_likes WHERE user_id   = ?) AS likes,
            (SELECT COUNT(*) FROM friends    WHERE friend_id = ?) AS followers,
            (SELECT COUNT(*) FROM friends    WHERE user_id   = ?) AS following
    ";
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'posts'     => 0,
        'likes'     => 0,
        'followers' => 0,
        'following' => 0,
    ];

    echo json_encode([
        'ok'   => true,
        'user' => array_merge($user, $stats),
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Erro ao buscar dados: ' . $e->getMessage()]);
}
