<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Uma linha por amigo: dá a lista lateral do chat inteira em uma
    // consulta só, com a última mensagem e o número de não lidas.
    // Parte dos amigos (não das mensagens) para que amigo sem conversa
    // também apareça, pronto para receber a primeira mensagem.
    $sql = "SELECT
                amigo.id      AS user_id,
                amigo.name,
                amigo.email,
                amigo.avatar,
                (SELECT m.body FROM messages m
                  WHERE (m.sender_id = :me1 AND m.receiver_id = amigo.id)
                     OR (m.sender_id = amigo.id AND m.receiver_id = :me2)
                  ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT m.created_at FROM messages m
                  WHERE (m.sender_id = :me3 AND m.receiver_id = amigo.id)
                     OR (m.sender_id = amigo.id AND m.receiver_id = :me4)
                  ORDER BY m.id DESC LIMIT 1) AS last_at,
                (SELECT m.sender_id FROM messages m
                  WHERE (m.sender_id = :me5 AND m.receiver_id = amigo.id)
                     OR (m.sender_id = amigo.id AND m.receiver_id = :me6)
                  ORDER BY m.id DESC LIMIT 1) AS last_sender_id,
                (SELECT COUNT(*) FROM messages m
                  WHERE m.sender_id = amigo.id
                    AND m.receiver_id = :me7
                    AND m.read_at IS NULL) AS unread_count
            FROM (
                SELECT u.id, u.name, u.email, u.avatar
                FROM friends f
                JOIN users u ON u.id = f.friend_id
                WHERE f.user_id = :me8 AND f.status = 'accepted'

                UNION

                SELECT u.id, u.name, u.email, u.avatar
                FROM friends f
                JOIN users u ON u.id = f.user_id
                WHERE f.friend_id = :me9 AND f.status = 'accepted'
            ) AS amigo
            ORDER BY last_at IS NULL, last_at DESC, amigo.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "me1" => $userId, "me2" => $userId, "me3" => $userId,
        "me4" => $userId, "me5" => $userId, "me6" => $userId,
        "me7" => $userId, "me8" => $userId, "me9" => $userId,
    ]);

    $conversas   = [];
    $totalUnread = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unread       = (int)$row["unread_count"];
        $totalUnread += $unread;

        $conversas[] = [
            "user_id"        => (int)$row["user_id"],
            "name"           => $row["name"],
            "email"          => $row["email"],
            "avatar"         => !empty($row["avatar"]) ? $row["avatar"] : null,
            "last_body"      => $row["last_body"],
            "last_at"        => $row["last_at"],
            "last_sender_id" => $row["last_sender_id"] !== null ? (int)$row["last_sender_id"] : null,
            "last_is_mine"   => $row["last_sender_id"] !== null && (int)$row["last_sender_id"] === $userId,
            "unread_count"   => $unread,
        ];
    }

    echo json_encode([
        "ok"            => true,
        "unread_total"  => $totalUnread,
        "conversations" => $conversas,
    ]);

} catch (Exception $e) {
    error_log("messages/conversations: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao listar conversas."]);
}
