<?php
header("Content-Type: application/json");
require_once "../auth/db.php";


$data = json_decode(file_get_contents("php://input"));

if (!$data || !isset($data->email) || !isset($data->sender)) {
    echo json_encode(["error" => "Dados incompletos"]);
    exit;
}

$receiverEmail = trim($data->email);
$senderId = (int)$data->sender;

// pega ID do receiver
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$receiverEmail]);
$receiver = $stmt->fetch();

if (!$receiver) {
    echo json_encode(["error" => "Usuário não encontrado"]);
    exit;
}

$receiverId = $receiver["id"];

$stmt = $pdo->prepare("
    UPDATE friends
    SET status = 'accepted'
    WHERE user_id = ? AND friend_id = ? AND status = 'pending'
");
$stmt->execute([$senderId, $receiverId]);

echo json_encode(["ok" => true]);
