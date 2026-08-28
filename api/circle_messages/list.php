<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../circles/helpers.php";

$userId   = require_login();
$circleId = (int)($_GET["circle_id"] ?? 0);

// Mesma regra de acesso dos círculos: só dono ou membro lê o chat.
$circle = circles_load_for_user($pdo, $circleId, $userId);

if ($circle === null) {
    echo json_encode(["error" => "Círculo não encontrado."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT m.id, m.circle_id, m.user_id, m.message, m.created_at,
                u.name, u.email
         FROM circle_messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.circle_id = ?
         ORDER BY m.id ASC"
    );
    $stmt->execute([$circle["id"]]);

    $messages = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $messages[] = [
            "id"         => (int)$row["id"],
            "circle_id"  => (int)$row["circle_id"],
            "user_id"    => (int)$row["user_id"],
            "message"    => $row["message"],
            "created_at" => $row["created_at"],
            "name"       => $row["name"],
            "email"      => $row["email"],
        ];
    }

    echo json_encode(["ok" => true, "messages" => $messages]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar mensagens do círculo."]);
}
