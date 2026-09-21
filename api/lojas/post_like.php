<?php
/**
 * Curte ou descurte um post de loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../notifications/helpers.php";

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

    $stmt = $pdo->prepare(
        "SELECT l.id AS loja_id, l.user_id AS dono FROM loja_posts p
           JOIN lojas l ON l.id = p.loja_id
          WHERE p.id = ? AND p.ativo = 1"
    );
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM loja_post_likes WHERE loja_post_id = ? AND user_id = ?");
    $stmt->execute([$postId, $userId]);
    $like = $stmt->fetchColumn();

    if ($like) {
        $pdo->prepare("DELETE FROM loja_post_likes WHERE id = ?")->execute([$like]);
        $curtiu = false;
        notify_undo($pdo, (int)$post["dono"], $userId, "loja_like", (int)$post["loja_id"]);
    } else {
        $pdo->prepare("INSERT INTO loja_post_likes (loja_post_id, user_id) VALUES (?, ?)")
            ->execute([$postId, $userId]);
        $curtiu = true;
        // notify() ja ignora quando dono === ator: o lojista curtindo o
        // proprio post nao gera nada, igual ao feed humano.
        notify($pdo, (int)$post["dono"], $userId, "loja_like", (int)$post["loja_id"]);
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
