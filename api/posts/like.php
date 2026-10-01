<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";

$userId = require_login();

try {
    $input  = json_decode(file_get_contents("php://input"), true);
    $postId = (int)($input["post_id"] ?? 0);

    if (!$postId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    $authorId = (int)$post["user_id"];

    $stmt = $pdo->prepare("SELECT id FROM post_likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    $like = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($like) {
        $stmt = $pdo->prepare("DELETE FROM post_likes WHERE id = ?");
        $stmt->execute([$like["id"]]);
        $liked = false;

        // Descurtiu: o aviso no sino do autor deixa de valer.
        notify_undo($pdo, $authorId, $userId, "like", $postId);
    } else {
        $stmt = $pdo->prepare("INSERT INTO post_likes (user_id, post_id) VALUES (?, ?)");
        $stmt->execute([$userId, $postId]);
        $liked = true;

        notify($pdo, $authorId, $userId, "like", $postId);
    }

    echo json_encode(["ok" => true, "liked" => $liked]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao curtir post."]);
}
