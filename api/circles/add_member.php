<?php
header("Content-Type: application/json");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->circle_id) || !isset($data->email)) {
    echo json_encode(["error" => "Dados incompletos"]);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$data->email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["error" => "Usuário não encontrado"]);
    exit;
}

$stmt = $pdo->prepare("
    INSERT IGNORE INTO circle_members (circle_id, user_id)
    VALUES (?, ?)
");
$stmt->execute([$data->circle_id, $user["id"]]);

echo json_encode(["ok" => true]);