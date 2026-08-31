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

// O remetente é sempre o usuário da sessão; do corpo só vem o
// destinatário (`user_id` ou `friend_email`) e o texto.
$targetId = messages_target_id($pdo, $data, true);
$body     = trim((string)($data["body"] ?? ""));

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

if ($targetId === $userId) {
    echo json_encode(["error" => "Não é possível conversar consigo mesmo."]);
    exit;
}

$friend = messages_load_friend($pdo, $targetId, $userId);

if ($friend === null) {
    echo json_encode(["error" => "Só é possível conversar com amigos."]);
    exit;
}

if ($body === "") {
    echo json_encode(["error" => "Mensagem é obrigatória."]);
    exit;
}

if (mb_strlen($body) > 5000) {
    echo json_encode(["error" => "Mensagem é longa demais (máx. 5000 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $targetId, $body]);

    $messageId = (int)$pdo->lastInsertId();

    // Devolve a mensagem pronta para o front renderizar sem esperar o
    // próximo ciclo do poller.
    $stmt = $pdo->prepare(
        "SELECT m.id, m.sender_id, m.receiver_id, m.body, m.created_at,
                u.name, u.email
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.id = ?"
    );
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    notify($pdo, $targetId, $userId, "message", $userId);

    echo json_encode([
        "ok"      => true,
        "message" => messages_message_row($row),
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao enviar mensagem."]);
}
