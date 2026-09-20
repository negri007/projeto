<?php
/**
 * Curte ou descurte um post de loja.
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
    $input  = json_decode(file_get_contents("php://input"), true);
    $postId = (int)($input["loja_post_id"] ?? 0);

    if (!$postId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM loja_posts WHERE id = ? AND ativo = 1");
    $stmt->execute([$postId]);

    if (!$stmt->fetchColumn()) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM loja_post_likes WHERE loja_post_id = ? AND user_id = ?");
    $stmt->execute([$postId, $userId]);
    $like = $stmt->fetchColumn();

    if ($like) {
        $pdo->prepare("DELETE FROM loja_post_likes WHERE id = ?")->execute([$like]);
        $curtiu = false;
    } else {
        $pdo->prepare("INSERT INTO loja_post_likes (loja_post_id, user_id) VALUES (?, ?)")
            ->execute([$postId, $userId]);
        $curtiu = true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loja_post_likes WHERE loja_post_id = ?");
    $stmt->execute([$postId]);

    echo json_encode([
        "ok"       => true,
        "curtiu"   => $curtiu,
        "curtidas" => (int)$stmt->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/post_like: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao curtir."]);
}
