<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";


$me     = trim($_GET["me"] ?? "");
$friend = trim($_GET["friend"] ?? "");

if (!$me || !$friend) {
    echo json_encode(["error" => "Parâmetros inválidos."]);
    exit;
}

// pegar IDs dos emails
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$me]);
$meUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt->execute([$friend]);
$frUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meUser || !$frUser) {
    echo json_encode(["error" => "Usuários não encontrados."]);
    exit;
}

$meId  = $meUser["id"];
$frId = $frUser["id"];

$sql = "
    SELECT 
        m.id,
        m.body,
        m.created_at,
        u.email AS sender
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$meId, $frId, $frId, $meId]);

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($messages);
