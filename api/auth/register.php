<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$name     = trim((string)($data["name"] ?? ""));
$email    = trim((string)($data["email"] ?? ""));
// A senha não passa por trim: espaço no começo ou no fim é parte dela.
$password = (string)($data["password"] ?? "");

if ($name === "" || $email === "" || $password === "") {
    echo json_encode(["error" => "Preencha todos os campos."]);
    exit;
}

if (mb_strlen($name) > 100) {
    echo json_encode(["error" => "Nome é longo demais (máx. 100 caracteres)."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    echo json_encode(["error" => "E-mail inválido."]);
    exit;
}

if (mb_strlen($password) < 8) {
    echo json_encode(["error" => "A senha precisa ter pelo menos 8 caracteres."]);
    exit;
}

if (mb_strlen($password) > 72) {
    echo json_encode(["error" => "A senha é longa demais (máx. 72 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(["error" => "Este e-mail já está cadastrado."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)"
    );
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

    $userId = (int)$pdo->lastInsertId();

    // Já entra logado: a tela de cadastro não precisa mandar o usuário
    // digitar a mesma senha de novo no login.
    start_user_session($userId, $name);

    echo json_encode([
        "success" => true,
        "user"    => ["id" => $userId, "name" => $name, "email" => $email],
    ]);

} catch (PDOException $e) {
    // 23000 = violação de chave única. Cobre a corrida entre a checagem
    // acima e o INSERT, quando dois cadastros do mesmo e-mail chegam
    // juntos.
    if ($e->getCode() === "23000") {
        echo json_encode(["error" => "Este e-mail já está cadastrado."]);
        exit;
    }

    error_log("register: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao criar conta."]);

} catch (Exception $e) {
    error_log("register: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao criar conta."]);
}
