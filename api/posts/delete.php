<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/../auth/db.php";

try {
   
    $input   = json_decode(file_get_contents("php://input"), true);
    $email   = trim($input["email"]   ?? "");
    $post_id = (int)($input["post_id"] ?? 0);

    if ($email === "" || !$post_id) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["error" => "Usuário não encontrado."]);
        exit;
    }

   
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$post_id, $user["id"]]);
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
