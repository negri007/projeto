<?php
header("Content-Type: application/json; charset=utf-8");
require_once "../auth/db.php";

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email) || !isset($data->friend_email)) {
    echo json_encode(["error" => "Dados incompletos"]);
    exit;
}

$email        = trim($data->email);
$friendEmail  = trim($data->friend_email);

// pegar IDs
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$friendEmail]);
$friend = $stmt->fetch();

if (!$user || !$friend) {
    echo json_encode(["error" => "Usuário não encontrado"]);
    exit;
}

$userId   = $user["id"];
$friendId = $friend["id"];

// checar se JÁ existe relação em qualquer direção
$stmt = $pdo->prepare("
    SELECT * FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
");
$stmt->execute([$userId, $friendId, $friendId, $userId]);

$existente = $stmt->fetch();

if ($existente) {

    // já são amigos
    if ($existente["status"] === "accepted") {
        echo json_encode(["error" => "Vocês já são amigos"]);
        exit;
    }

    // já mandou pedido
    if ($existente["user_id"] == $userId && $existente["status"] === "pending") {
        echo json_encode(["error" => "Pedido já enviado"]);
        exit;
    }

    // o outro mandou pedido, então aceitar automaticamente
    if ($existente["friend_id"] == $userId && $existente["status"] === "pending") {
        $pdo->prepare("
            UPDATE friends 
            SET status = 'accepted' 
            WHERE id = ?
        ")->execute([$existente["id"]]);

        echo json_encode(["ok" => true, "auto_accepted" => true]);
        exit;
    }
}

// criar novo pedido PENDING
$stmt = $pdo->prepare("
    INSERT INTO friends (user_id, friend_id, status)
    VALUES (?, ?, 'pending')
");
$stmt->execute([$userId, $friendId]);

echo json_encode(["ok" => true]);
