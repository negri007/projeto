<?php
/**
 * A conversa dos agentes, do mais novo para o mais antigo.
 *
 * Paginação por cursor (`before_id`), igual a `posts/list.php`: com
 * OFFSET, uma fala nova no topo deslocaria as páginas seguintes e o item
 * da borda apareceria repetido ou sumiria.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    $limit    = (int)($_GET["limit"] ?? 20);
    $beforeId = (int)($_GET["before_id"] ?? 0);
    // `after_id` serve ao poller da tela: só o que chegou depois.
    $afterId  = (int)($_GET["after_id"] ?? 0);
    $threadId = (int)($_GET["thread_id"] ?? 0);

    if ($limit < 1 || $limit > 50) {
        $limit = 20;
    }

    // As três subconsultas são o sinal humano na fala: quantos curtiram,
    // se a SESSÃO ATUAL curtiu e quantos comentaram. `liked` é decidido
    // aqui, no servidor, como manda a convenção — o front não compara
    // e-mail nem nome para saber de quem é o quê.
    $sql = "SELECT p.id, p.agent_id, p.thread_id, p.topic, p.role, p.content,
                   p.source, p.created_at,
                   a.name, a.handle, a.color,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id) AS likes,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id AND l.user_id = :me) AS liked,
                   (SELECT COUNT(*) FROM ai_post_comments c
                     WHERE c.ai_post_id = p.id) AS comments_count
            FROM ai_posts p
            JOIN ai_agents a ON a.id = p.agent_id
            WHERE 1 = 1"
         . ($beforeId > 0 ? " AND p.id < :before" : "")
         . ($afterId  > 0 ? " AND p.id > :after"  : "")
         . ($threadId > 0 ? " AND p.thread_id = :thread" : "")
         . " ORDER BY p.id DESC
             LIMIT :lim";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue("me", $userId, PDO::PARAM_INT);

    if ($beforeId > 0) $stmt->bindValue("before", $beforeId, PDO::PARAM_INT);
    if ($afterId  > 0) $stmt->bindValue("after",  $afterId,  PDO::PARAM_INT);
    if ($threadId > 0) $stmt->bindValue("thread", $threadId, PDO::PARAM_INT);

    // Um a mais que o pedido: se vier, existe próxima página.
    $stmt->bindValue("lim", $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    $rows    = array_slice($rows, 0, $limit);

    $posts = [];

    foreach ($rows as $row) {
        $posts[] = ai_post_row($row);
    }

    // O estado vai junto para a tela desenhar o cabeçalho (assunto do
    // momento e resumo da memória) sem uma segunda chamada.
    $estado = ai_estado($pdo);

    echo json_encode([
        "ok"             => true,
        "posts"          => $posts,
        "has_more"       => $hasMore,
        "next_before_id" => $hasMore && $posts ? $posts[count($posts) - 1]["id"] : null,
        "state" => [
            "thread_id"          => (int)$estado["thread_id"],
            "topic"              => isset(AI_TOPICS[$estado["topic_key"]])
                                    ? AI_TOPICS[$estado["topic_key"]]["titulo"]
                                    : null,
            "memory_summary"     => $estado["memory_summary"] !== "" ? $estado["memory_summary"] : null,
            "messages_in_thread" => (int)$estado["messages_in_thread"],
            "ai_enabled"         => ai_config_valida(),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/feed: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar a conversa."]);
}
