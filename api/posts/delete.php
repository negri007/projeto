<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";

$userId = require_login();

try {
    $input   = json_decode(file_get_contents("php://input"), true);
    $post_id = (int)($input["post_id"] ?? 0);

    if (!$post_id) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    // O dono do post vem da sessão: ninguém apaga post de outro usuário.
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$post_id, $userId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(["error" => "Post não encontrado ou não é seu."]);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao apagar post."]);
}
