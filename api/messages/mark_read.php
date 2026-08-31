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

$data     = json_decode(file_get_contents("php://input"), true);
$targetId = messages_target_id($pdo, $data, true);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

try {
    // Marca como lidas só as mensagens que ELE me mandou. O `receiver_id
    // = $userId` é o que impede marcar a conversa dos outros.
    $stmt = $pdo->prepare(
        "UPDATE messages SET read_at = NOW()
         WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL"
    );
    $stmt->execute([$targetId, $userId]);

    $marcadas = $stmt->rowCount();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL"
    );
    $stmt->execute([$userId]);

    echo json_encode([
        "ok"           => true,
        "marked"       => $marcadas,
        "unread_total" => (int)$stmt->fetchColumn(),
    ]);

} catch (Exception $e) {
    error_log("messages/mark_read: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao marcar mensagens como lidas."]);
}
