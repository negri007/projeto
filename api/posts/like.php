<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";
require_once __DIR__ . "/../user_agent/helpers.php";

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

        /* So na curtida NOVA, nunca na descurtida: o que interessa ao
           agente e o que o dono gosta, e descurtir nao e um gosto ao
           contrario -- e quase sempre so um clique errado desfeito.

           Grava o TEXTO do post curtido, nao o id: o agente precisa
           saber sobre o que a pessoa gosta de ler, e um numero nao
           carrega assunto nenhum para dentro do prompt. */
        $stmt = $pdo->prepare("SELECT content FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $curtido = (string)$stmt->fetchColumn();

        user_agent_registrar_acao($pdo, $userId, 'curtida', $curtido);
    }

    echo json_encode(["ok" => true, "liked" => $liked]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao curtir post."]);
}
