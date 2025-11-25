<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/../auth/db.php";

try {
    $email  = trim($_GET["email"] ?? "");
    $userId = null;

    // pegar ID do usuário logado
    if ($email !== "") {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $userId = (int)$u["id"];
        }
    }

    // consulta com contador de comentários incluído
    if ($userId !== null) {
        $sql = "SELECT 
                    p.id,
                    p.content,
                    p.image,
                    p.created_at,
                    u.name,
                    u.email,

                    -- 🔥 AQUI ESTÁ O CAMPO QUE FALTAVA:
                    (SELECT COUNT(*) 
                     FROM comments c 
                     WHERE c.post_id = p.id) AS comment_count,

                    (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                    (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                    (SELECT COUNT(*) FROM post_likes pl2 WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me

                FROM posts p
                JOIN users u ON u.id = p.user_id
                ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(["uid" => $userId]);
    
    } else {

        $sql = "SELECT 
                    p.id,
                    p.content,
                    p.image,
                    p.created_at,
                    u.name,
                    u.email,

                    -- 🔥 AQUI TAMBÉM:
                    (SELECT COUNT(*) 
                     FROM comments c 
                     WHERE c.post_id = p.id) AS comment_count,

                    (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                    (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                    0 AS liked_by_me

                FROM posts p
                JOIN users u ON u.id = p.user_id
                ORDER BY p.id DESC";

        $stmt = $pdo->query($sql);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["ok" => true, "posts" => $rows]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar posts."]);
}
