<?php
header("Content-Type: application/json");
require_once "../auth/db.php";

$email = $_GET["email"] ?? "";

if (!$email) {
    echo json_encode(["ok" => false, "friends" => []]);
    exit;
}

// pega ID do usuário principal
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["ok" => false, "friends" => []]);
    exit;
}

$userId = $user["id"];

// lista de amigos ACEITOS
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email
    FROM friends f
    JOIN users u ON u.id = f.friend_id
    WHERE f.user_id = ? AND f.status = 'accepted'

    UNION

    SELECT u.id, u.name, u.email
    FROM friends f
    JOIN users u ON u.id = f.user_id
    WHERE f.friend_id = ? AND f.status = 'accepted'
");
$stmt->execute([$userId, $userId]);

$friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "ok" => true,
    "friends" => $friends
]);
