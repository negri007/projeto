<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data["email"]) || !isset($data["friend_email"])) {
    echo json_encode(["error" => "Dados incompletos"]);
    exit;
}

$email = trim($data["email"]);
$friendEmail = trim($data["friend_email"]);

// pegar IDs
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

$userId = $user["id"];
$friendId = $friend["id"];

// cancelar só se status = pending e enviado por mim
$stmt = $pdo->prepare("
    DELETE FROM friends
    WHERE user_id = ? AND friend_id = ? AND status = 'pending'
");
$stmt->execute([$userId, $friendId]);

echo json_encode(["ok" => true]);
