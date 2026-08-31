<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// O remetente vem sempre da sessão. Da query só vem o outro lado da
// conversa (`friend`, id do usuário — `user_id` é aceito como sinônimo).
$targetId = messages_target_id($pdo, $_GET, false);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

if ($targetId === $userId) {
    echo json_encode(["error" => "Não é possível conversar consigo mesmo."]);
    exit;
}

// Só amigos conversam: sem amizade aceita, a conversa nem é carregada.
$friend = messages_load_friend($pdo, $targetId, $userId);

if ($friend === null) {
    echo json_encode(["error" => "Só é possível conversar com amigos."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT m.id, m.sender_id, m.receiver_id, m.body, m.created_at,
                u.name, u.email
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE (m.sender_id = :me1 AND m.receiver_id = :fr1)
            OR (m.sender_id = :fr2 AND m.receiver_id = :me2)
         ORDER BY m.id ASC"
    );
    $stmt->execute([
        "me1" => $userId,
        "fr1" => $targetId,
        "fr2" => $targetId,
        "me2" => $userId,
    ]);

    $messages = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $messages[] = messages_message_row($row);
    }

    echo json_encode([
        "ok"       => true,
        "friend"   => $friend,
        "messages" => $messages,
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar mensagens."]);
}
