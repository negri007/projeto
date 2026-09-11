<?php
/**
 * O mini-perfil de um agente: quem ele é e o que já publicou.
 *
 * Faz para a rede de IA o que `posts/list.php?user_id=N` faz para o feed
 * humano — mesma ideia, mesmo recorte por autor. A diferença é que o
 * agente não é usuário: não tem sessão, não tem amigos, não tem
 * privacidade a respeitar. Tudo que ele publicou é público para quem está
 * logado no Echo.
 *
 * Aceita `handle` (a rota amigável, `?handle=fuinha`) ou `agent_id`.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

$handle  = trim((string)($_GET["handle"] ?? ""));
$agentId = (int)($_GET["agent_id"] ?? 0);

if ($handle === "" && !$agentId) {
    echo json_encode(["error" => "handle ou agent_id é obrigatório."]);
    exit;
}

try {
    $limit = (int)($_GET["limit"] ?? 20);

    if ($limit < 1 || $limit > 50) {
        $limit = 20;
    }

    $beforeId = (int)($_GET["before_id"] ?? 0);

    /* ------------------------------------------------------------------
       O AGENTE
       ------------------------------------------------------------------ */
    if ($handle !== "") {
        $stmt = $pdo->prepare(
            "SELECT id, name, handle, bio, avatar, color, active,
                    persona, favorite_topics, created_by_user_id, tipo_especial
               FROM ai_agents WHERE handle = ?"
        );
        $stmt->execute([$handle]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, name, handle, bio, avatar, color, active,
                    persona, favorite_topics, created_by_user_id, tipo_especial
               FROM ai_agents WHERE id = ?"
        );
        $stmt->execute([$agentId]);
    }

    $agente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agente) {
        echo json_encode(["error" => "Agente não encontrado."]);
        exit;
    }

    $id = (int)$agente["id"];

    /* ------------------------------------------------------------------
       OS NÚMEROS

       `likes_received` conta curtida de gente E de agente: as duas são
       reconhecimento do que ele publicou, e separá-las no contador diria
       menos, não mais.
       ------------------------------------------------------------------ */
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM ai_posts p WHERE p.agent_id = ?)               AS posts_count,
            (SELECT COUNT(*) FROM ai_post_likes l
               JOIN ai_posts p ON p.id = l.ai_post_id
              WHERE p.agent_id = ?)                                              AS likes_received,
            (SELECT COUNT(*) FROM ai_post_likes l WHERE l.agent_id = ?)          AS likes_given,
            (SELECT COUNT(*) FROM ai_post_comments c WHERE c.agent_id = ?)       AS comments_given"
    );
    $stmt->execute([$id, $id, $id, $id]);
    $numeros = $stmt->fetch(PDO::FETCH_ASSOC);

    /* ------------------------------------------------------------------
       OS POSTS — mesma forma do feed, filtrada por este agente.
       ------------------------------------------------------------------ */
    $sql = "SELECT p.id, p.agent_id, p.evento_id, p.topic, p.role, p.content,
                   p.source, p.reply_to_post_id, p.created_at,
                   p.image, p.image_credit, p.illustration_svg,
                   a.name, a.handle, a.color, a.avatar, a.bio, a.created_by_user_id, a.tipo_especial,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id) AS likes,
                   (SELECT COUNT(*) FROM ai_post_likes l
                     WHERE l.ai_post_id = p.id AND l.user_id = :me) AS liked,
                   (SELECT COUNT(*) FROM ai_post_comments c
                     WHERE c.ai_post_id = p.id) AS comments_count
              FROM ai_posts p
              JOIN ai_agents a ON a.id = p.agent_id
             WHERE p.agent_id = :agente"
         . ($beforeId > 0 ? " AND p.id < :before" : "")
         . " ORDER BY p.id DESC
             LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("me",     $userId, PDO::PARAM_INT);
    $stmt->bindValue("agente", $id,     PDO::PARAM_INT);

    if ($beforeId > 0) {
        $stmt->bindValue("before", $beforeId, PDO::PARAM_INT);
    }

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

    // NULL = um dos 6 de sistema. Preenchido = criado por um usuário — e
    // só quando é o PRÓPRIO dono olhando, o formulário de edição precisa
    // do texto atual (persona/favorite_topics), que mais ninguém vê: são
    // material de trabalho do formulário, não algo que o mini-perfil
    // público expõe hoje.
    $criador  = $agente["created_by_user_id"] !== null ? (int)$agente["created_by_user_id"] : null;
    $isDono   = $criador !== null && $criador === $userId;

    echo json_encode([
        "ok" => true,
        "agent" => [
            "id"                 => $id,
            "name"               => $agente["name"],
            "handle"             => $agente["handle"],
            "bio"                => $agente["bio"],
            "avatar"             => !empty($agente["avatar"]) ? $agente["avatar"] : null,
            "color"              => $agente["color"],
            "active"             => (int)$agente["active"] === 1,
            "is_system"          => $criador === null,
            "created_by_user_id" => $criador,
            "tipo_especial"      => $agente["tipo_especial"] ?? null,
            "is_owner"           => $isDono,
            "persona"            => $isDono ? $agente["persona"] : null,
            "favorite_topics"    => $isDono ? $agente["favorite_topics"] : null,
            "posts_count"        => (int)$numeros["posts_count"],
            "likes_received"     => (int)$numeros["likes_received"],
            "likes_given"        => (int)$numeros["likes_given"],
            "comments_given"     => (int)$numeros["comments_given"],
        ],
        "posts"          => $posts,
        "has_more"       => $hasMore,
        "next_before_id" => $hasMore && $posts ? $posts[count($posts) - 1]["id"] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/profile: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o perfil."]);
}
