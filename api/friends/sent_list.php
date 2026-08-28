<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Pedidos que EU enviei e ainda estão pendentes.
    $sql = "SELECT u.id AS user_id, u.name, u.email, u.avatar, f.created_at AS requested_at
            FROM friends f
            JOIN users u ON u.id = f.friend_id
            WHERE f.user_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC, f.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);

    $sent = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = friends_user_row($row);
        $item["requested_at"] = $row["requested_at"];
        $sent[] = $item;
    }

    echo json_encode(["ok" => true, "sent" => $sent]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar solicitações enviadas."]);
}
