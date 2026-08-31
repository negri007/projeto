<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    // Paginacao por cursor: `before_id` e o menor id ja carregado pelo
    // front. Cursor em vez de OFFSET porque post novo no topo nao
    // desloca as paginas seguintes — com OFFSET, o item da borda
    // aparecia repetido ou sumia.
    $limit    = (int)($_GET["limit"] ?? 20);
    $beforeId = (int)($_GET["before_id"] ?? 0);

    if ($limit < 1 || $limit > 50) {
        $limit = 20;
    }

    // Apenas os posts de um autor (usado pelo perfil).
    $authorId = (int)($_GET["user_id"] ?? 0);

    $sql = "SELECT
                p.id,
                p.user_id,
                p.content,
                p.image,
                p.created_at,
                p.edited_at,
                u.name,
                u.email,
                u.avatar,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                (SELECT COUNT(*) FROM post_likes pl2 WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me
            FROM posts p
            JOIN users u ON u.id = p.user_id
            WHERE 1 = 1"
         . ($beforeId > 0 ? " AND p.id < :before" : "")
         . ($authorId > 0 ? " AND p.user_id = :author" : "")
         . " ORDER BY p.id DESC
             LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("uid", $userId, PDO::PARAM_INT);

    if ($beforeId > 0) {
        $stmt->bindValue("before", $beforeId, PDO::PARAM_INT);
    }

    if ($authorId > 0) {
        $stmt->bindValue("author", $authorId, PDO::PARAM_INT);
    }

    // Pede um a mais que o pedido: se vier, existe proxima pagina.
    $stmt->bindValue("lim", $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    $rows    = array_slice($rows, 0, $limit);

    $posts = [];

    foreach ($rows as $row) {
        $posts[] = posts_post_row($row);
    }

    echo json_encode([
        "ok"       => true,
        "posts"    => $posts,
        "has_more" => $hasMore,
        // Cursor para a proxima pagina; null quando acabou.
        "next_before_id" => $hasMore && $posts ? $posts[count($posts) - 1]["id"] : null,
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao listar posts."]);
}
