<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"));

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email) || !isset($data->name)) {
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
    INSERT INTO circles (owner_id, name, description)
    VALUES (?, ?, ?)
");

$stmt->execute([
    $user["id"],
    $data->name,
    $data->description ?? null
]);

echo json_encode(["ok" => true]);