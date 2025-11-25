<?php
header("Content-Type: application/json");
require_once "../auth/db.php";


$email = $_GET["email"] ?? "";

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["ok"=>true,"requests"=>[]]);
    exit;
}

$userId = $user["id"];

$stmt = $pdo->prepare("
    SELECT f.user_id AS sender_id, u.name, u.email
    FROM friends f
    JOIN users u ON u.id = f.user_id
    WHERE f.friend_id = ? AND f.status = 'pending'
");
$stmt->execute([$userId]);

echo json_encode(["ok"=>true,"requests"=>$stmt->fetchAll()]);
