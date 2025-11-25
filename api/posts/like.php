<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/../auth/db.php";

try {
    $input  = json_decode(file_get_contents("php://input"), true);
    $email  = trim($input["email"]   ?? "");
    $postId = (int)($input["post_id"] ?? 0);

    if ($email === "" || !$postId) {
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

    $userId = (int)$user["id"];

  
    $stmt = $pdo->prepare("SELECT id FROM post_likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    $like = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($like) {
      
        $stmt = $pdo->prepare("DELETE FROM post_likes WHERE id = ?");
        $stmt->execute([$like["id"]]);
        $liked = false;
    } else {
      
        $stmt = $pdo->prepare("INSERT INTO post_likes (user_id, post_id) VALUES (?, ?)");
        $stmt->execute([$userId, $postId]);
        $liked = true;
    }

    echo json_encode(["ok" => true, "liked" => $liked]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao curtir post."]);
}
