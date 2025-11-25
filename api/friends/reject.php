<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email) || !isset($data->friend_email)) {
    echo json_encode(["error" => "Dados incompletos"]);
    exit;
}

$email        = trim($data->email);
$friendEmail  = trim($data->friend_email);

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$friendEmail]);
$friend = $stmt->fetch();

if (!$user || !$friend) {
    echo json_encode(["error" => "Usuário não encontrado"]);
    exit;
}

$userId   = $user["id"];
$friendId = $friend["id"];

$stmt = $pdo->prepare("
    DELETE FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
       AND status = 'pending'
");
$stmt->execute([$userId, $friendId, $friendId, $userId]);

echo json_encode(["ok" => true]);
