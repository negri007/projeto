<?php
header("Content-Type: application/json");
require_once "../auth/db.php";

$circle_id = $_GET["circle_id"] ?? 0;

$stmt = $pdo->prepare("
    SELECT u.name, u.email 
    FROM circle_members cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.circle_id = ?
");
$stmt->execute([$circle_id]);

echo json_encode([
    "ok" => true,
    "members" => $stmt->fetchAll()
]);
