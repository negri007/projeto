<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// `only_unread=1` serve para o polling barato do sino; sem ele vem a
// lista completa (limitada), que é o que o painel mostra.
$onlyUnread = ($_GET["only_unread"] ?? "") === "1";
$limit      = (int)($_GET["limit"] ?? 50);

if ($limit < 1 || $limit > 100) {
    $limit = 50;
}

try {
    $sql = "SELECT n.id, n.type, n.reference_id, n.is_read, n.created_at,
                   n.actor_id, u.name AS actor_name, u.avatar AS actor_avatar
            FROM notifications n
            JOIN users u ON u.id = n.actor_id
            WHERE n.user_id = :me"
         . ($onlyUnread ? " AND n.is_read = 0" : "")
         . " ORDER BY n.id DESC
             LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("me", $userId, PDO::PARAM_INT);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notifications[] = notifications_row($row);
    }

    // Contador do sino: sempre o total de não lidas, independente do
    // `limit` da listagem.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->execute([$userId]);

    echo json_encode([
        "ok"            => true,
        "unread_count"  => (int)$stmt->fetchColumn(),
        "notifications" => $notifications,
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar notificações."]);
}
