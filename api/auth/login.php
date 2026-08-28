<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require __DIR__ . "/db.php";

$data = json_decode(file_get_contents("php://input"), true);

$email    = trim($data["email"] ?? "");
$password = trim($data["password"] ?? "");

if (!$email || !$password) {
    echo json_encode(["error" => "Informe email e senha."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Erro ao autenticar."]);
    exit;
}

if (!$user || !password_verify($password, $user["password_hash"])) {
    echo json_encode(["error" => "Email ou senha incorretos."]);
    exit;
}

start_user_session((int)$user["id"], $user["name"]);

echo json_encode([
    "success" => true,
    "user" => [
        "id"    => (int)$user["id"],
        "name"  => $user["name"],
        "email" => $user["email"]
    ]
]);
