<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";

$userId = current_user_id();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

require __DIR__ . "/db.php";

try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Erro ao carregar usuário."]);
    exit;
}

// Sessão apontando para um usuário que não existe mais: derruba a sessão.
if (!$user) {
    destroy_user_session();
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

echo json_encode([
    "authenticated" => true,
    "user" => [
        "id"    => (int)$user["id"],
        "name"  => $user["name"],
        "email" => $user["email"]
    ]
]);
