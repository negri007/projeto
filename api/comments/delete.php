<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data      = json_decode(file_get_contents("php://input"), true);
$commentId = (int)($data["comment_id"] ?? 0);

if ($commentId <= 0) {
    echo json_encode(["error" => "Dados inválidos."]);
    exit;
}

try {
    // Apaga quem escreveu o comentário OU o dono do post — moderar a
    // própria publicação é esperado numa rede social.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.user_id, c.post_id, p.user_id AS post_owner_id
         FROM comments c
         JOIN posts p ON p.id = c.post_id
         WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        echo json_encode(["error" => "Comentário não encontrado."]);
        exit;
    }

    $autorId = (int)$comment["user_id"];
    $donoId  = (int)$comment["post_owner_id"];

    if ($autorId !== $userId && $donoId !== $userId) {
        echo json_encode(["error" => "Comentário não encontrado."]);
        exit;
    }

    $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$commentId]);

    // Se o comentário saiu, o aviso dele no sino do dono do post também
    // deixa de valer — desde que não reste outro comentário do mesmo
    // autor no mesmo post.
    $restam = $pdo->prepare(
        "SELECT id FROM comments WHERE post_id = ? AND user_id = ? LIMIT 1"
    );
    $restam->execute([(int)$comment["post_id"], $autorId]);

    if (!$restam->fetch(PDO::FETCH_ASSOC)) {
        notify_undo($pdo, $donoId, $autorId, "comment", (int)$comment["post_id"]);
    }

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    error_log("comments/delete: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao apagar comentário."]);
}
