<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";

$userId = require_login();

try {
    // Janela da tendência, em dias: o card lateral mostra o que está
    // acontecendo agora, não o acumulado da vida do sistema.
    $days  = (int)($_GET["days"] ?? 7);
    $limit = (int)($_GET["limit"] ?? 5);

    if ($days < 1 || $days > 90)   $days = 7;
    if ($limit < 1 || $limit > 20) $limit = 5;

    // `post_count` conta posts distintos, e não linhas de post_hashtags:
    // o mesmo post citando "#php" duas vezes já entra uma vez só na
    // tabela, mas contar posts deixa isso explícito.
    $sql = "SELECT h.tag,
                   COUNT(DISTINCT ph.post_id) AS post_count,
                   COUNT(DISTINCT p.user_id)  AS people_count,
                   MAX(p.created_at)          AS last_post_at
            FROM hashtags h
            JOIN post_hashtags ph ON ph.hashtag_id = h.id
            JOIN posts p          ON p.id = ph.post_id
            WHERE p.created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY h.id, h.tag
            ORDER BY post_count DESC, last_post_at DESC
            LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("days", $days, PDO::PARAM_INT);
    $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $tags = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tags[] = [
            "tag"          => $row["tag"],
            "post_count"   => (int)$row["post_count"],
            "people_count" => (int)$row["people_count"],
            "last_post_at" => $row["last_post_at"],
        ];
    }

    echo json_encode(["ok" => true, "days" => $days, "hashtags" => $tags]);

} catch (Exception $e) {
    error_log("hashtags/trending: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar tendências."]);
}
