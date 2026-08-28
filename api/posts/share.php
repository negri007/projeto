<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";

$userId = require_login();

try {
    $input  = json_decode(file_get_contents("php://input"), true);
    $postId = (int)($input["post_id"] ?? 0);

    if (!$postId) {
        echo json_encode(["error" => "Post inválido."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    // Registra quem compartilhou (antes o INSERT gravava só o post_id).
    $stmt = $pdo->prepare("INSERT INTO post_shares (post_id, user_id) VALUES (?, ?)");
    $stmt->execute([$postId, $userId]);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao compartilhar post."]);
}
