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
$targetId = friends_target_id($pdo, $data, true);

if ($targetId === null) {
    echo json_encode(["error" => "Usuário não encontrado."]);
    exit;
}

if ($targetId === $userId) {
    echo json_encode(["error" => "Você não pode adicionar a si mesmo."]);
    exit;
}

try {
    // Relação existente em qualquer direção.
    $stmt = $pdo->prepare(
        "SELECT id, user_id, friend_id, status
         FROM friends
         WHERE (user_id = ? AND friend_id = ?)
            OR (user_id = ? AND friend_id = ?)
         LIMIT 1"
    );
    $stmt->execute([$userId, $targetId, $targetId, $userId]);
    $relacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($relacao) {
        if ($relacao["status"] === "accepted") {
            echo json_encode(["error" => "Vocês já são amigos."]);
            exit;
        }

        // Pedido meu ainda pendente.
        if ((int)$relacao["user_id"] === $userId) {
            echo json_encode(["error" => "Pedido já enviado."]);
            exit;
        }

        // O outro já tinha pedido: vira amizade na hora.
        $stmt = $pdo->prepare("UPDATE friends SET status = 'accepted' WHERE id = ?");
        $stmt->execute([(int)$relacao["id"]]);

        echo json_encode([
            "ok"            => true,
            "status"        => "accepted",
            "auto_accepted" => true
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')"
    );
    $stmt->execute([$userId, $targetId]);

    echo json_encode([
        "ok"            => true,
        "status"        => "pending",
        "auto_accepted" => false
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao enviar solicitação."]);
}
