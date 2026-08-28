<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Pedidos que EU recebi e ainda não respondi.
    $sql = "SELECT u.id AS user_id, u.name, u.email, u.avatar, f.created_at AS requested_at
            FROM friends f
            JOIN users u ON u.id = f.user_id
            WHERE f.friend_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC, f.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);

    $requests = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $request = friends_user_row($row);
        $request["requested_at"] = $row["requested_at"];
        $requests[] = $request;
    }

    echo json_encode(["ok" => true, "requests" => $requests]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar solicitações recebidas."]);
}
