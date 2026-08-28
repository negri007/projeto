<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// `user_id` aqui é o amigo que estou removendo.
$targetId = friends_target_id($pdo, $data);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

try {
    // A amizade é uma linha só, em qualquer uma das duas direções.
    $stmt = $pdo->prepare(
        "DELETE FROM friends
         WHERE status = 'accepted'
           AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))"
    );
    $stmt->execute([$userId, $targetId, $targetId, $userId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Amizade não encontrada."]);
        exit;
    }

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao desfazer amizade."]);
}
