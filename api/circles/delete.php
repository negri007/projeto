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

$data     = json_decode(file_get_contents("php://input"), true);
$circleId = (int)($data["circle_id"] ?? 0);

$circle = circles_load_for_user($pdo, $circleId, $userId);

if ($circle === null) {
    echo json_encode(["error" => "Círculo não encontrado."]);
    exit;
}

if (!$circle["is_owner"]) {
    echo json_encode(["error" => "Apenas o dono do círculo pode apagá-lo."]);
    exit;
}

try {
    // `circle_members` e `circle_messages` têm ON DELETE CASCADE, então
    // membros e conversa vão junto — é apagar mesmo, não arquivar.
    $pdo->prepare("DELETE FROM circles WHERE id = ?")->execute([$circle["id"]]);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    error_log("circles/delete: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao apagar círculo."]);
}
