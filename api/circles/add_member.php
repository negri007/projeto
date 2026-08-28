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
    echo json_encode(["error" => "Apenas o dono do círculo pode gerenciar membros."]);
    exit;
}

$targetId = circles_target_user_id($pdo, $data);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

if ($targetId === $circle["owner_id"]) {
    echo json_encode(["error" => "O dono já faz parte do círculo."]);
    exit;
}

// O círculo é montado a partir da lista de amigos do dono; quem não é
// amigo não entra.
if (!circles_are_friends($pdo, $userId, $targetId)) {
    echo json_encode(["error" => "Só é possível adicionar amigos ao círculo."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO circle_members (circle_id, user_id) VALUES (?, ?)"
    );
    $stmt->execute([$circle["id"], $targetId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Esse usuário já está no círculo."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.name, u.email, u.avatar, cm.joined_at
         FROM circle_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.circle_id = ? AND cm.user_id = ?"
    );
    $stmt->execute([$circle["id"], $targetId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(["ok" => true, "member" => circles_member_row($member)]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao adicionar membro."]);
}
