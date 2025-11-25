<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";


$data = json_decode(file_get_contents("php://input"), true);

$sender   = trim($data["sender"] ?? "");
$receiver = trim($data["receiver"] ?? "");
$body     = trim($data["body"] ?? "");

if (!$sender || !$receiver || !$body) {
    echo json_encode(["error" => "Dados incompletos."]);
    exit;
}

// pegar IDs
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$sender]);
$senderRow = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt->execute([$receiver]);
$receiverRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$senderRow || !$receiverRow) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

$senderId   = $senderRow["id"];
$receiverId = $receiverRow["id"];

// inserir mensagem
$stmt = $pdo->prepare("
    INSERT INTO messages (sender_id, receiver_id, body)
    VALUES (?, ?, ?)
");

$ok = $stmt->execute([$senderId, $receiverId, $body]);

if ($ok) {
    echo json_encode(["ok" => true]);
} else {
    echo json_encode(["error" => "Erro ao enviar mensagem."]);
}
