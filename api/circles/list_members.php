<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId   = require_login();
$circleId = (int)($_GET["circle_id"] ?? 0);

$circle = circles_load_for_user($pdo, $circleId, $userId);

if ($circle === null) {
    echo json_encode(["error" => "Círculo não encontrado."]);
    exit;
}

try {
    // O dono não fica em circle_members; ele vai separado em `owner`.
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.name, u.email, u.avatar, cm.joined_at
         FROM circle_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.circle_id = ?
         ORDER BY u.name ASC"
    );
    $stmt->execute([$circle["id"]]);

    $members = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $members[] = circles_member_row($row);
    }

    $stmt = $pdo->prepare("SELECT id AS user_id, name, email, avatar FROM users WHERE id = ?");
    $stmt->execute([$circle["owner_id"]]);
    $ownerRow = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"      => true,
        "circle"  => circles_circle_row($circle, count($members)),
        "owner"   => [
            "user_id" => (int)$ownerRow["user_id"],
            "name"    => $ownerRow["name"],
            "email"   => $ownerRow["email"],
            "avatar"  => $ownerRow["avatar"] !== null && $ownerRow["avatar"] !== "" ? $ownerRow["avatar"] : null,
        ],
        "members" => $members
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar membros."]);
}
