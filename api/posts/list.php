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

    // Filtro por etiqueta (`?tag=php`, com ou sem o `#`) e pela lista de
    // salvos do proprio usuario (`?saved=1`).
    $tag       = mb_strtolower(ltrim(trim((string)($_GET["tag"] ?? "")), "#"));
    $apenasSalvos = ($_GET["saved"] ?? "") === "1";

    // `scope=friends`: so os meus posts e os de quem e meu amigo. E o
    // que o Inicio mostra — a sua roda, nao a rede inteira.
    $apenasAmigos = ($_GET["scope"] ?? "") === "friends";

    // `sort=top`: ordem por engajamento na janela recente, em vez de por
    // data. E o que o Explorar mostra — o que a rede achou interessante,
    // nao o que acabou de ser escrito.
    $porEngajamento = ($_GET["sort"] ?? "") === "top";
    $dias = (int)($_GET["days"] ?? 7);

    if ($dias < 1 || $dias > 90) {
        $dias = 7;
    }

    // O cursor e o id do ultimo post da pagina, o que so faz sentido
    // quando a ordem e por id. Ordenado por engajamento, o id nao diz
    // nada sobre a posicao na lista — entao este modo devolve uma pagina
    // unica, e `has_more` vem sempre false.
    if ($porEngajamento) {
        $beforeId = 0;
    }

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
                (SELECT COUNT(*) FROM post_likes pl2 WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me,
                (SELECT COUNT(*) FROM post_saves psv WHERE psv.post_id = p.id AND psv.user_id = :uid2) AS saved_by_me
                -- Peso do engajamento: comentario e compartilhamento
                -- valem mais que curtida porque custam mais que um
                -- clique.
                , ((SELECT COUNT(*) FROM post_likes pl3 WHERE pl3.post_id = p.id)
                   + 2 * (SELECT COUNT(*) FROM comments c2 WHERE c2.post_id = p.id)
                   + 2 * (SELECT COUNT(*) FROM post_shares ps2 WHERE ps2.post_id = p.id)) AS score
            FROM posts p
            JOIN users u ON u.id = p.user_id"
         . ($tag !== "" ? " JOIN post_hashtags ph ON ph.post_id = p.id
                            JOIN hashtags h ON h.id = ph.hashtag_id AND h.tag = :tag" : "")
         // A lista de salvos e sempre a minha: o filtro nao aceita dono
         // vindo do cliente.
         . ($apenasSalvos ? " JOIN post_saves sv ON sv.post_id = p.id AND sv.user_id = :saver" : "")
         . " WHERE 1 = 1"
         . ($beforeId > 0 ? " AND p.id < :before" : "")
         . ($authorId > 0 ? " AND p.user_id = :author" : "")
         . ($apenasAmigos
             ? " AND (p.user_id = :me OR EXISTS (
                     SELECT 1 FROM friends f
                     WHERE f.status = 'accepted'
                       AND ((f.user_id = :me2 AND f.friend_id = p.user_id)
                         OR (f.friend_id = :me3 AND f.user_id = p.user_id))))"
             : "")
         // A janela de dias so existe no modo engajamento: no feed
         // cronologico, post antigo ainda e post.
         . ($porEngajamento ? " AND p.created_at >= (NOW() - INTERVAL :dias DAY)" : "")
         // Empate de score desempata pelo mais novo, para a lista nao
         // ficar parada num post velho com duas curtidas.
         . ($porEngajamento ? " ORDER BY score DESC, p.id DESC" : " ORDER BY p.id DESC")
         . " LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("uid", $userId, PDO::PARAM_INT);
    $stmt->bindValue("uid2", $userId, PDO::PARAM_INT);

    if ($beforeId > 0) {
        $stmt->bindValue("before", $beforeId, PDO::PARAM_INT);
    }

    if ($authorId > 0) {
        $stmt->bindValue("author", $authorId, PDO::PARAM_INT);
    }

    if ($tag !== "") {
        $stmt->bindValue("tag", $tag);
    }

    if ($apenasSalvos) {
        $stmt->bindValue("saver", $userId, PDO::PARAM_INT);
    }

    if ($apenasAmigos) {
        $stmt->bindValue("me",  $userId, PDO::PARAM_INT);
        $stmt->bindValue("me2", $userId, PDO::PARAM_INT);
        $stmt->bindValue("me3", $userId, PDO::PARAM_INT);
    }

    if ($porEngajamento) {
        $stmt->bindValue("dias", $dias, PDO::PARAM_INT);
    }

    // Pede um a mais que o pedido: se vier, existe proxima pagina.
    $stmt->bindValue("lim", $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit && !$porEngajamento;
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
