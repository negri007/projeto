<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require __DIR__ . "/db.php";
require_once __DIR__ . "/rate_limit.php";

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data["email"] ?? "");
// A senha não passa por trim: espaço no começo ou no fim é parte dela,
// e precisa bater com o que register.php gravou.
$password = (string)($data["password"] ?? "");

if (!$email || !$password) {
    echo json_encode(["error" => "Informe email e senha."]);
    exit;
}

// Freio de força bruta antes de qualquer consulta ao usuário.
$espera = login_bloqueado_por($pdo, $email);

if ($espera > 0) {
    http_response_code(429);
    echo json_encode([
        "error" => "Muitas tentativas de login. Tente de novo em "
                 . login_tempo_legivel($espera) . "."
    ]);
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
    login_registrar_tentativa($pdo, $email, false);
    echo json_encode(["error" => "Email ou senha incorretos."]);
    exit;
}

login_registrar_tentativa($pdo, $email, true);
start_user_session((int)$user["id"], $user["name"]);

echo json_encode([
    "success" => true,
    "user" => [
        "id"    => (int)$user["id"],
        "name"  => $user["name"],
        "email" => $user["email"]
    ]
]);
