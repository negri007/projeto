<?php
header("Content-Type: application/json; charset=utf-8");

require "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$email    = trim($data["email"] ?? "");
$password = trim($data["password"] ?? "");

if (!$email || !$password) {
    echo json_encode(["error" => "Informe email e senha."]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user["password_hash"])) {
    echo json_encode(["error" => "Email ou senha incorretos."]);
    exit;
}

echo json_encode([
    "success" => true,
    "user" => [
        "id"    => $user["id"],
        "name"  => $user["name"],
        "email" => $user["email"]
    ]
]);
