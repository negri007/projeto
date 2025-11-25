<?php
header("Content-Type: application/json");
require_once "../auth/db.php";

$circle_id = $_GET["circle_id"] ?? null;

if (!$circle_id) {
    echo json_encode(["ok" => false, "messages" => []]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT m.id, m.message, m.created_at,
           u.name, u.email
    FROM circle_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.circle_id = ?
    ORDER BY m.id ASC
");
$stmt->execute([$circle_id]);
$messages = $stmt->fetchAll();

echo json_encode(["ok" => true, "messages" => $messages]);
