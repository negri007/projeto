<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Círculos que eu criei MAIS os círculos em que fui incluído.
    $sql = "SELECT c.id, c.owner_id, c.name, c.description, c.created_at,
                   (SELECT COUNT(*) FROM circle_members cm2 WHERE cm2.circle_id = c.id) AS member_count
            FROM circles c
            LEFT JOIN circle_members cm ON cm.circle_id = c.id AND cm.user_id = :me1
            WHERE c.owner_id = :me2 OR cm.user_id IS NOT NULL
            ORDER BY c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(["me1" => $userId, "me2" => $userId]);

    $circles = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $circles[] = circles_circle_row([
            "id"          => (int)$row["id"],
            "owner_id"    => (int)$row["owner_id"],
            "name"        => $row["name"],
            "description" => $row["description"] !== null && $row["description"] !== "" ? $row["description"] : null,
            "created_at"  => $row["created_at"],
            "is_owner"    => (int)$row["owner_id"] === $userId,
        ], (int)$row["member_count"]);
    }

    echo json_encode(["ok" => true, "circles" => $circles]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar círculos."]);
}
