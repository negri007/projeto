<?php
header("Content-Type: application/json");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"), true);

$circle_id = $data["circle_id"] ?? null;
$email     = $data["email"] ?? null;
$message   = trim($data["message"] ?? "");

if (!$circle_id || !$email || $message === "") {
    echo json_encode(["ok" => false, "error" => "Dados faltando"]);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["ok" => false, "error" => "Usuário não encontrado"]);
    exit;
}

$user_id = $user["id"];

$stmt = $pdo->prepare("
    INSERT INTO circle_messages (circle_id, user_id, message)
    VALUES (?, ?, ?)
");
$stmt->execute([$circle_id, $user_id, $message]);

echo json_encode(["ok" => true]);
