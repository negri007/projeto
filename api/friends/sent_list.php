<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$email = $_GET["email"] ?? "";
if (!$email) {
    echo json_encode(["ok" => false, "error" => "email ausente"]);
    exit;
}

// pegar ID do usuário
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["ok" => false, "sent" => []]);
    exit;
}

$userId = $user["id"];

// solicitações enviadas por mim
$stmt = $pdo->prepare("
    SELECT f.friend_id AS receiver_id, u.name, u.email
    FROM friends f
    JOIN users u ON u.id = f.friend_id
    WHERE f.user_id = ? AND f.status = 'pending'
");
$stmt->execute([$userId]);

echo json_encode([
    "ok" => true,
    "sent" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
?>
