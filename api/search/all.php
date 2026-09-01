<?php
/**
 * Busca global — o campo "Buscar no ECHO" do cabeçalho.
 *
 * Uma chamada devolve as quatro coisas que o sistema tem para procurar:
 * pessoas, publicações, etiquetas e círculos. É uma chamada só porque o
 * campo é um só: quatro requisições a cada tecla digitada seria um
 * desperdício visível.
 *
 * Regra de visibilidade: publicação e perfil são públicos dentro do
 * sistema (é o que `posts/list.php` e `profile/get.php` já fazem), mas
 * **círculo não é** — a busca só devolve os círculos de que o usuário da
 * sessão participa, senão ela viraria um índice dos grupos alheios.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../friends/helpers.php";
require_once __DIR__ . "/../posts/helpers.php";

$userId = require_login();

$q     = trim((string)($_GET["q"] ?? ""));
$limit = (int)($_GET["limit"] ?? 8);

if ($limit < 1 || $limit > 30) {
    $limit = 8;
}

// Busca vazia não é erro: é o estado inicial do campo.
if ($q === "") {
    echo json_encode([
        "ok"       => true,
        "query"    => "",
        "users"    => [],
        "posts"    => [],
        "hashtags" => [],
        "circles"  => [],
    ]);
    exit;
}

try {
    $like = "%" . friends_like_escape($q) . "%";

    // `#php` e `php` procuram a mesma etiqueta; o `#` é só a marca que a
    // pessoa digitou.
    $tagLike = "%" . friends_like_escape(mb_strtolower(ltrim($q, "#"))) . "%";

    /* ---------------------------------------------------------------
       Pessoas — mesmo objeto e mesmo `status` de friends/search.php,
       para o front reaproveitar os botões de amizade que já existem.
       --------------------------------------------------------------- */
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.name, u.email, u.avatar,
                f.user_id AS rel_from, f.status AS rel_status
         FROM users u
         LEFT JOIN friends f
           ON (f.user_id = :me1 AND f.friend_id = u.id)
           OR (f.friend_id = :me2 AND f.user_id = u.id)
         WHERE u.id <> :me3
           AND (u.name LIKE :like1 ESCAPE '!' OR u.email LIKE :like2 ESCAPE '!')
         ORDER BY u.name ASC
         LIMIT :lim"
    );
    $stmt->bindValue("me1", $userId, PDO::PARAM_INT);
    $stmt->bindValue("me2", $userId, PDO::PARAM_INT);
    $stmt->bindValue("me3", $userId, PDO::PARAM_INT);
    $stmt->bindValue("like1", $like);
    $stmt->bindValue("like2", $like);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $users = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $user = friends_user_row($row);
        $user["status"] = friends_rel_status($row["rel_status"], $row["rel_from"], $userId);
        $users[] = $user;
    }

    /* ---------------------------------------------------------------
       Publicações — mesmo formato de posts/list.php, para o front
       desenhar o resultado com o mesmo renderizador do feed.
       --------------------------------------------------------------- */
    $stmt = $pdo->prepare(
        "SELECT p.id, p.user_id, p.content, p.image, p.created_at, p.edited_at,
                u.name, u.email, u.avatar,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                (SELECT COUNT(*) FROM post_likes pl2 WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me,
                (SELECT COUNT(*) FROM post_saves psv WHERE psv.post_id = p.id AND psv.user_id = :uid2) AS saved_by_me
         FROM posts p
         JOIN users u ON u.id = p.user_id
         WHERE p.content LIKE :like ESCAPE '!'
         ORDER BY p.id DESC
         LIMIT :lim"
    );
    $stmt->bindValue("uid", $userId, PDO::PARAM_INT);
    $stmt->bindValue("uid2", $userId, PDO::PARAM_INT);
    $stmt->bindValue("like", $like);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $posts = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $posts[] = posts_post_row($row);
    }

    /* ---------------------------------------------------------------
       Etiquetas — com o número de posts, para a lista dizer o tamanho
       do assunto e não só o nome.
       --------------------------------------------------------------- */
    $stmt = $pdo->prepare(
        "SELECT h.tag, COUNT(DISTINCT ph.post_id) AS post_count
         FROM hashtags h
         LEFT JOIN post_hashtags ph ON ph.hashtag_id = h.id
         WHERE h.tag LIKE :like ESCAPE '!'
         GROUP BY h.id, h.tag
         ORDER BY post_count DESC, h.tag ASC
         LIMIT :lim"
    );
    $stmt->bindValue("like", $tagLike);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $hashtags = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hashtags[] = [
            "tag"        => $row["tag"],
            "post_count" => (int)$row["post_count"],
        ];
    }

    /* ---------------------------------------------------------------
       Círculos — só os meus (dono ou membro). Ver o comentário do
       cabeçalho do arquivo.
       --------------------------------------------------------------- */
    $stmt = $pdo->prepare(
        "SELECT c.id, c.owner_id, c.name, c.description, c.created_at,
                (SELECT COUNT(*) FROM circle_members cm2 WHERE cm2.circle_id = c.id) AS member_count
         FROM circles c
         LEFT JOIN circle_members cm ON cm.circle_id = c.id AND cm.user_id = :me1
         WHERE (c.owner_id = :me2 OR cm.user_id IS NOT NULL)
           AND (c.name LIKE :like1 ESCAPE '!' OR c.description LIKE :like2 ESCAPE '!')
         ORDER BY c.name ASC
         LIMIT :lim"
    );
    $stmt->bindValue("me1", $userId, PDO::PARAM_INT);
    $stmt->bindValue("me2", $userId, PDO::PARAM_INT);
    $stmt->bindValue("like1", $like);
    $stmt->bindValue("like2", $like);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $circles = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $circles[] = [
            "id"           => (int)$row["id"],
            "user_id"      => (int)$row["owner_id"],
            "name"         => $row["name"],
            "description"  => $row["description"] !== null && $row["description"] !== "" ? $row["description"] : null,
            "created_at"   => $row["created_at"],
            "member_count" => (int)$row["member_count"],
            "is_owner"     => (int)$row["owner_id"] === $userId,
        ];
    }

    echo json_encode([
        "ok"       => true,
        "query"    => $q,
        "users"    => $users,
        "posts"    => $posts,
        "hashtags" => $hashtags,
        "circles"  => $circles,
        "total"    => count($users) + count($posts) + count($hashtags) + count($circles),
    ]);

} catch (Exception $e) {
    error_log("search/all: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao buscar."]);
}
