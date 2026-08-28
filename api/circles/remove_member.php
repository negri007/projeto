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

$targetId = circles_target_user_id($pdo, $data);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

// O dono tira quem quiser; qualquer outro membro só pode tirar a si
// mesmo (sair do círculo).
if (!$circle["is_owner"] && $targetId !== $userId) {
    echo json_encode(["error" => "Apenas o dono do círculo pode gerenciar membros."]);
    exit;
}

if ($targetId === $circle["owner_id"]) {
    echo json_encode(["error" => "O dono não pode ser removido do círculo."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?"
    );
    $stmt->execute([$circle["id"], $targetId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Membro não encontrado no círculo."]);
        exit;
    }

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao remover membro."]);
}
