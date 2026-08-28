<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../circles/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data     = json_decode(file_get_contents("php://input"), true);
$circleId = (int)($data["circle_id"] ?? 0);
$message  = trim((string)($data["message"] ?? ""));

// Mesma regra de acesso dos círculos: só dono ou membro escreve.
$circle = circles_load_for_user($pdo, $circleId, $userId);

if ($circle === null) {
    echo json_encode(["error" => "Círculo não encontrado."]);
    exit;
}

if ($message === "") {
    echo json_encode(["error" => "Mensagem é obrigatória."]);
    exit;
}

if (mb_strlen($message) > 5000) {
    echo json_encode(["error" => "Mensagem é longa demais (máx. 5000 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO circle_messages (circle_id, user_id, message) VALUES (?, ?, ?)"
    );
    $stmt->execute([$circle["id"], $userId, $message]);

    $messageId = (int)$pdo->lastInsertId();

    // Devolve a mensagem pronta para o front renderizar sem esperar o
    // próximo ciclo do poller.
    $stmt = $pdo->prepare(
        "SELECT m.id, m.circle_id, m.user_id, m.message, m.created_at,
                u.name, u.email
         FROM circle_messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.id = ?"
    );
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"      => true,
        "message" => [
            "id"         => (int)$row["id"],
            "circle_id"  => (int)$row["circle_id"],
            "user_id"    => (int)$row["user_id"],
            "message"    => $row["message"],
            "created_at" => $row["created_at"],
            "name"       => $row["name"],
            "email"      => $row["email"],
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao enviar mensagem."]);
}
