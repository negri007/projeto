<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // A amizade é uma linha só; ela pode ter sido criada em qualquer
    // uma das duas direções, por isso o UNION.
    $sql = "SELECT u.id AS user_id, u.name, u.email, u.avatar, f.created_at AS friends_since
            FROM friends f
            JOIN users u ON u.id = f.friend_id
            WHERE f.user_id = :me1 AND f.status = 'accepted'

            UNION

            SELECT u.id AS user_id, u.name, u.email, u.avatar, f.created_at AS friends_since
            FROM friends f
            JOIN users u ON u.id = f.user_id
            WHERE f.friend_id = :me2 AND f.status = 'accepted'

            ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(["me1" => $userId, "me2" => $userId]);

    $friends = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $friend = friends_user_row($row);
        $friend["friends_since"] = $row["friends_since"];
        $friends[] = $friend;
    }

    echo json_encode(["ok" => true, "friends" => $friends]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar amigos."]);
}
