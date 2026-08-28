<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Todo mundo menos eu e menos quem já tem qualquer relação comigo
    // (amizade aceita ou pedido pendente, nas duas direções).
    $sql = "SELECT u.id AS user_id, u.name, u.email, u.avatar
            FROM users u
            WHERE u.id <> :me1
              AND u.id NOT IN (
                    SELECT friend_id FROM friends WHERE user_id = :me2
                    UNION
                    SELECT user_id FROM friends WHERE friend_id = :me3
              )
            ORDER BY u.name ASC
            LIMIT 15";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(["me1" => $userId, "me2" => $userId, "me3" => $userId]);

    $users = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $users[] = friends_user_row($row);
    }

    echo json_encode(["ok" => true, "users" => $users]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao carregar sugestões."]);
}
