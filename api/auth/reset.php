<?php
require "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data["email"] ?? "");
$new_pass = trim($data["new_pass"] ?? "");

if (!$email || !$new_pass) {
    echo json_encode(["error" => "Preencha todos os campos."]);
    exit;
}


$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["error" => "Nenhuma conta encontrada com esse email."]);
    exit;
}

$hash = password_hash($new_pass, PASSWORD_DEFAULT);

$upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$upd->execute([$hash, $email]);

echo json_encode(["success" => true, "message" => "Senha alterada com sucesso!"]);
