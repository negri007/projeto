<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data    = json_decode(file_get_contents("php://input"), true);
$postId  = (int)($data["post_id"] ?? 0);
$content = trim((string)($data["content"] ?? ""));

if ($postId <= 0) {
    echo json_encode(["error" => "Dados inválidos."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, user_id, image FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    // Post inexistente e post de outra pessoa devolvem a mesma coisa:
    // quem não é dono não descobre nada sobre o post.
    if (!$post || (int)$post["user_id"] !== $userId) {
        echo json_encode(["error" => "Post não encontrado ou não é seu."]);
        exit;
    }

    // Só a imagem segurando o post: esvaziar o texto deixaria um post
    // sem conteúdo nenhum.
    if ($content === "" && empty($post["image"])) {
        echo json_encode(["error" => "O post não pode ficar vazio."]);
        exit;
    }

    if (mb_strlen($content) > 5000) {
        echo json_encode(["error" => "Post é longo demais (máx. 5000 caracteres)."]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE posts SET content = ?, edited_at = NOW() WHERE id = ?");
    $stmt->execute([$content, $postId]);

    // O texto novo manda nas etiquetas: as que saíram na edição são
    // desligadas, as que entraram passam a valer para a tendência.
    posts_sync_hashtags($pdo, $postId, $content);

    // Quem foi citado só agora recebe o aviso; quem já tinha sido citado
    // neste post não recebe de novo (notify_mentions não repete).
    notify_mentions($pdo, $content, $userId, $postId);

    echo json_encode(["ok" => true, "post" => posts_load($pdo, $postId, $userId)]);

} catch (Exception $e) {
    error_log("posts/edit: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao editar post."]);
}
