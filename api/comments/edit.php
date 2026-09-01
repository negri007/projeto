<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data      = json_decode(file_get_contents("php://input"), true);
$commentId = (int)($data["comment_id"] ?? 0);
$body      = trim((string)($data["body"] ?? ""));

if ($commentId <= 0) {
    echo json_encode(["error" => "Dados inválidos."]);
    exit;
}

if ($body === "") {
    echo json_encode(["error" => "O comentário não pode ficar vazio."]);
    exit;
}

if (mb_strlen($body) > 2000) {
    echo json_encode(["error" => "Comentário é longo demais (máx. 2000 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.user_id, c.post_id, p.user_id AS post_owner_id
         FROM comments c
         JOIN posts p ON p.id = c.post_id
         WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    // Diferente de delete.php: aqui só o autor passa. O dono do post
    // pode apagar um comentário do seu post, mas não reescrevê-lo —
    // editar a fala de outra pessoa mantendo o nome dela embaixo seria
    // pôr palavras na boca de alguém.
    if (!$comment || (int)$comment["user_id"] !== $userId) {
        echo json_encode(["error" => "Comentário não encontrado ou não é seu."]);
        exit;
    }

    $pdo->prepare("UPDATE comments SET body = ?, edited_at = NOW() WHERE id = ?")
        ->execute([$body, $commentId]);

    // Menção que entrou na edição avisa quem foi citado agora.
    notify_mentions($pdo, $body, $userId, (int)$comment["post_id"]);

    $stmt = $pdo->prepare(
        "SELECT c.id, c.post_id, c.user_id, c.body, c.created_at, c.edited_at,
                u.name, u.email, u.avatar
         FROM comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);

    echo json_encode([
        "ok"      => true,
        "comment" => comments_comment_row(
            $stmt->fetch(PDO::FETCH_ASSOC),
            $userId,
            (int)$comment["post_owner_id"]
        ),
    ]);

} catch (Exception $e) {
    error_log("comments/edit: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao editar comentário."]);
}
