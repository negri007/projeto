<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../notifications/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// `user_id` aqui é o destinatário do pedido que eu enviei.
$targetId = friends_target_id($pdo, $data);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "DELETE FROM friends
         WHERE user_id = ? AND friend_id = ? AND status = 'pending'"
    );
    $stmt->execute([$userId, $targetId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Solicitação não encontrada."]);
        exit;
    }

    // Cancelado por mim: o pedido some do sino do destinatário.
    notify_undo($pdo, $targetId, $userId, "friend_request", null);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao cancelar solicitação."]);
}
