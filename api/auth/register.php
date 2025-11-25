<?php
require "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$name = trim($data["name"] ?? "");
$email = trim($data["email"] ?? "");
$password = trim($data["password"] ?? "");

if (!$name || !$email || !$password) {
    echo json_encode(["error" => "Preencha todos os campos."]);
    exit;
}

$check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$check->execute([$email]);

if ($check->rowCount() > 0) {
    echo json_encode(["error" => "Este email já está cadastrado."]);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
$stmt->execute([$name, $email, $hash]);

echo json_encode(["success" => true, "message" => "Conta criada com sucesso!"]);
