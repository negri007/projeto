<?php
header("Content-Type: application/json");
require_once "../auth/db.php";


$email = $_GET["email"] ?? "";

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["ok"=>true,"users"=>[]]);
    exit;
}

$userId = $user["id"];

// lista todos menos o próprio usuário e quem já tem relação
$stmt = $pdo->prepare("
    SELECT id, name, email FROM users
    WHERE id != ?
    AND id NOT IN (
        SELECT friend_id FROM friends WHERE user_id = ?
        UNION
        SELECT user_id FROM friends WHERE friend_id = ?
    )
    LIMIT 15
");
$stmt->execute([$userId,$userId,$userId]);

echo json_encode(["ok"=>true,"users"=>$stmt->fetchAll()]);
