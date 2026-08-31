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

// `user_id` aqui é quem MANDOU o pedido que estou aceitando.
$targetId = friends_target_id($pdo, $data);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE friends
         SET status = 'accepted'
         WHERE user_id = ? AND friend_id = ? AND status = 'pending'"
    );
    $stmt->execute([$targetId, $userId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Solicitação não encontrada."]);
        exit;
    }

    // Quem mandou o pedido é avisado do aceite; o pedido pendente
    // deixa de aparecer no meu sino.
    notify_undo($pdo, $userId, $targetId, "friend_request", null);
    notify($pdo, $targetId, $userId, "friend_accept", null);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao aceitar solicitação."]);
}
