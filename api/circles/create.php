<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data        = json_decode(file_get_contents("php://input"), true);
$name        = trim((string)($data["name"] ?? ""));
$description = trim((string)($data["description"] ?? ""));

if ($name === "") {
    echo json_encode(["error" => "Nome do círculo é obrigatório."]);
    exit;
}

// Limites das colunas em banco.sql.
if (mb_strlen($name) > 100) {
    echo json_encode(["error" => "Nome do círculo é longo demais (máx. 100 caracteres)."]);
    exit;
}

if (mb_strlen($description) > 255) {
    echo json_encode(["error" => "Descrição é longa demais (máx. 255 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO circles (owner_id, name, description) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $name, $description !== "" ? $description : null]);

    $circleId = (int)$pdo->lastInsertId();
    $circle   = circles_load_for_user($pdo, $circleId, $userId);

    // Círculo recém-criado ainda não tem membros além do dono, que não
    // entra em circle_members.
    echo json_encode([
        "ok"     => true,
        "circle" => circles_circle_row($circle, 0)
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao criar círculo."]);
}
