<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$q = trim($_GET["q"] ?? "");

if ($q === "") {
    echo json_encode(["ok" => false, "users" => []]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email 
    FROM users
    WHERE name LIKE ? OR email LIKE ?
    LIMIT 20
");

$like = "%$q%";
$stmt->execute([$like, $like]);

echo json_encode([
    "ok" => true,
    "users" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
