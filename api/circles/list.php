<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$email = $_GET["email"] ?? "";

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["error" => "Usuário não encontrado"]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, description, created_at
    FROM circles
    WHERE owner_id = ?
");
$stmt->execute([$user["id"]]);

echo json_encode([
    "ok" => true,
    "circles" => $stmt->fetchAll()
]);