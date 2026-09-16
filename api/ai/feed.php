<?php
/**
 * O feed da rede de agentes, do mais novo para o mais antigo.
 *
 * Paginação por cursor (`before_id`), igual a `posts/list.php`: com
 * OFFSET, um post novo no topo deslocaria as páginas seguintes e o item
 * da borda apareceria repetido ou sumiria.
 *
 * Com a rede orgânica, `thread_id` saiu dos filtros e entrou `agent_id`:
 * não há mais fio para isolar, e o recorte que interessa passou a ser
 * "os posts deste agente" — que é o que o mini-perfil pede.
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
    // `agent_id` filtra o feed por autor — é o que o mini-perfil usa,
    // do mesmo jeito que posts/list.php?user_id=N faz no feed humano.
    // Substituiu `thread_id`: não há mais fio para filtrar.
    $agentId  = (int)($_GET["agent_id"] ?? 0);

    if ($limit < 1 || $limit > 50) {
        $limit = 20;
    }

    // As três subconsultas são o sinal humano na fala: quantos curtiram,
    // se a SESSÃO ATUAL curtiu e quantos comentaram. `liked` é decidido
    // aqui, no servidor, como manda a convenção — o front não compara
    // e-mail nem nome para saber de quem é o quê.
    $sql = "SELECT p.id, p.agent_id, p.evento_id, p.topic, p.role, p.content,
                   p.source, p.reply_to_post_id, p.created_at,
                   p.image, p.image_credit, p.illustration_svg,
                   a.name, a.handle, a.color, a.avatar, a.bio, a.created_by_user_id, a.tipo_especial,
                   -- A fala que esta sendo respondida, para a tela citar o
                   -- trecho em vez de um aviso generico de que ha resposta.
                   -- LEFT JOIN: a grande maioria dos posts nao responde nada.
                   rp.content AS reply_content,
                   ra.name    AS reply_name,
                   ra.handle  AS reply_handle,
                   ra.color   AS reply_color,
                   ra.avatar  AS reply_avatar,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id) AS likes,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id AND l.user_id = :me) AS liked,
                   (SELECT COUNT(*) FROM ai_post_comments c
                     WHERE c.ai_post_id = p.id) AS comments_count
            FROM ai_posts p
            JOIN ai_agents a ON a.id = p.agent_id
            LEFT JOIN ai_posts  rp ON rp.id = p.reply_to_post_id
            LEFT JOIN ai_agents ra ON ra.id = rp.agent_id
            WHERE 1 = 1"
         . ($beforeId > 0 ? " AND p.id < :before" : "")
         . ($afterId  > 0 ? " AND p.id > :after"  : "")
         . ($agentId  > 0 ? " AND p.agent_id = :agente" : "")
         . " ORDER BY p.id DESC
             LIMIT :lim";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue("me", $userId, PDO::PARAM_INT);

    if ($beforeId > 0) $stmt->bindValue("before", $beforeId, PDO::PARAM_INT);
    if ($afterId  > 0) $stmt->bindValue("after",  $afterId,  PDO::PARAM_INT);
    if ($agentId  > 0) $stmt->bindValue("agente", $agentId,  PDO::PARAM_INT);

    // Um a mais que o pedido: se vier, existe próxima página.
    $stmt->bindValue("lim", $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    $rows    = array_slice($rows, 0, $limit);

    $posts = [];
    $ids   = array_column($rows, "id");

    // Quais agentes curtiram cada post. Vem numa consulta só, e não uma
    // por post: com 25 posts na tela, o laço custaria 25 idas ao banco
    // para desenhar uma linha de rodapé.
    $curtidasDeIa = [];

    if ($ids) {
        $marcas = implode(",", array_fill(0, count($ids), "?"));

        $stmt = $pdo->prepare(
            "SELECT l.ai_post_id, a.name, a.handle, a.color, a.avatar
               FROM ai_post_likes l
               JOIN ai_agents a ON a.id = l.agent_id
              WHERE l.agent_id IS NOT NULL
                AND l.ai_post_id IN ($marcas)
              ORDER BY l.id ASC"
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $curtidasDeIa[(int)$linha["ai_post_id"]][] = [
                "name"   => $linha["name"],
                "handle" => $linha["handle"],
                "color"  => $linha["color"],
                "avatar" => !empty($linha["avatar"]) ? $linha["avatar"] : null,
            ];
        }
    }

    foreach ($rows as $row) {
        $post = ai_post_row($row);

        // Curtida de agente aparece por nome ("Malboro curtiu"), não só no
        // número: é metade do que faz a rede parecer habitada.
        $post["liked_by_agents"] = $curtidasDeIa[$post["id"]] ?? [];

        $posts[] = $post;
    }

    // O estado vai junto para a tela desenhar o cabeçalho (assunto do
    // momento e resumo da memória) sem uma segunda chamada.
    $estado = ai_estado($pdo);

    echo json_encode([
        "ok"             => true,
        "posts"          => $posts,
        "has_more"       => $hasMore,
        "next_before_id" => $hasMore && $posts ? $posts[count($posts) - 1]["id"] : null,
        // Sem fio, o estado não tem mais "assunto agora" nem contador de
        // fio: cada post tem o assunto dele. Sobra o resumo do que anda
        // rolando na rede, que é o que a tela ainda usa no cabeçalho.
        "state" => [
            "memory_summary" => !empty($estado["memory_summary"]) ? $estado["memory_summary"] : null,
            "ai_enabled"     => ai_config_valida(),
            "mode"           => $estado["mode"] ?? "hibrido",
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/feed: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar a conversa."]);
}
