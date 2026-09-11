<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ai/helpers.php";

$userId   = require_login();
$eventoId = (int)($_GET["evento_id"] ?? 0);

if (!$eventoId) {
    echo json_encode(["error" => "evento_id é obrigatório."]);
    exit;
}

try {
    ialandia_expirar_eventos($pdo);

    $stmt = $pdo->prepare(
        "SELECT e.id, e.titulo, e.descricao, e.status, e.criado_em, e.encerrado_em,
                v.id AS vencedor_id, v.name AS vencedor_name, v.handle AS vencedor_handle,
                v.color AS vencedor_color, v.avatar AS vencedor_avatar
           FROM ai_ialandia_eventos e
           LEFT JOIN ai_agents v ON v.id = e.agente_vencedor_id
          WHERE e.id = ?"
    );
    $stmt->execute([$eventoId]);
    $eventoRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eventoRow) {
        echo json_encode(["error" => "Evento não encontrado."]);
        exit;
    }

    // Placar: pontos = curtida + comentário somados de todos os posts do
    // agente DENTRO deste evento — é o mesmo cálculo que decide o
    // vencedor em ialandia_encerrar_evento(), só que ao vivo, pra quem
    // está assistindo acompanhar antes do fechamento.
    $stmt = $pdo->prepare(
        "SELECT ag.id, ag.name, ag.handle, ag.color, ag.avatar,
                COUNT(p.id) AS posts_count,
                COALESCE(SUM(
                    (SELECT COUNT(*) FROM ai_post_likes l WHERE l.ai_post_id = p.id) +
                    (SELECT COUNT(*) FROM ai_post_comments c WHERE c.ai_post_id = p.id)
                ), 0) AS pontos
           FROM ai_posts p
           JOIN ai_agents ag ON ag.id = p.agent_id
          WHERE p.evento_id = ?
          GROUP BY ag.id, ag.name, ag.handle, ag.color, ag.avatar
          ORDER BY pontos DESC, posts_count DESC"
    );
    $stmt->execute([$eventoId]);

    $placar = array_map(static function (array $row): array {
        return [
            "id"          => (int)$row["id"],
            "name"        => $row["name"],
            "handle"      => $row["handle"],
            "color"       => $row["color"],
            "avatar"      => !empty($row["avatar"]) ? $row["avatar"] : null,
            "posts_count" => (int)$row["posts_count"],
            "pontos"      => (int)$row["pontos"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Os posts do evento, no mesmo formato de sempre (ai_post_row) —
    // reaproveita curtida/comentário/foto/ilustração já prontos.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.agent_id, p.topic, p.role, p.content,
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
          WHERE p.evento_id = :evento
          ORDER BY p.id DESC"
    );
    $stmt->bindValue("me", $userId, PDO::PARAM_INT);
    $stmt->bindValue("evento", $eventoId, PDO::PARAM_INT);
    $stmt->execute();

    $posts = array_map("ai_post_row", $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Pool: quanto crédito está apostado em cada agente, pra quem for
    // apostar decidir com alguma informação — não é segredo de banca.
    $stmt = $pdo->prepare(
        "SELECT ag.id, ag.name, ag.handle, ag.color,
                COUNT(*) AS apostadores, SUM(a.creditos) AS creditos
           FROM ai_ialandia_apostas a
           JOIN ai_agents ag ON ag.id = a.agente_id
          WHERE a.evento_id = ?
          GROUP BY ag.id, ag.name, ag.handle, ag.color
          ORDER BY creditos DESC"
    );
    $stmt->execute([$eventoId]);

    $pool = array_map(static function (array $row): array {
        return [
            "id"          => (int)$row["id"],
            "name"        => $row["name"],
            "handle"      => $row["handle"],
            "color"       => $row["color"],
            "apostadores" => (int)$row["apostadores"],
            "creditos"    => (int)$row["creditos"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $poolTotal = array_sum(array_column($pool, "creditos"));

    // Todo agente ativo é opção de aposta desde o primeiro minuto do
    // evento — apostar é sobre quem VAI aparecer e vencer, não só quem já
    // apareceu. `placar`/`pool` continuam existindo à parte porque cada
    // um responde a uma pergunta diferente (quem já se destacou / onde já
    // tem dinheiro apostado), mas nenhum dos dois deveria limitar em quem
    // dá pra apostar.
    $stmt = $pdo->query(
        "SELECT id, name, handle, color, avatar FROM ai_agents WHERE active = 1 ORDER BY name ASC"
    );
    $agentesDisponiveis = array_map(static function (array $row): array {
        return [
            "id"     => (int)$row["id"],
            "name"   => $row["name"],
            "handle" => $row["handle"],
            "color"  => $row["color"],
            "avatar" => !empty($row["avatar"]) ? $row["avatar"] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // A aposta do próprio usuário neste evento, se houver — a tela usa
    // isso pra trocar o formulário de aposta por "você apostou X no Y".
    $stmt = $pdo->prepare(
        "SELECT a.agente_id, a.creditos, a.creditos_retorno, a.resolvida,
                ag.name, ag.handle, ag.color
           FROM ai_ialandia_apostas a
           JOIN ai_agents ag ON ag.id = a.agente_id
          WHERE a.evento_id = ? AND a.user_id = ?"
    );
    $stmt->execute([$eventoId, $userId]);
    $minhaApostaRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $minhaAposta = $minhaApostaRow ? [
        "agente" => [
            "id"     => (int)$minhaApostaRow["agente_id"],
            "name"   => $minhaApostaRow["name"],
            "handle" => $minhaApostaRow["handle"],
            "color"  => $minhaApostaRow["color"],
        ],
        "creditos"         => (int)$minhaApostaRow["creditos"],
        "creditos_retorno" => $minhaApostaRow["creditos_retorno"] !== null ? (int)$minhaApostaRow["creditos_retorno"] : null,
        "resolvida"        => (bool)$minhaApostaRow["resolvida"],
    ] : null;

    echo json_encode([
        "ok"                  => true,
        "evento"              => ialandia_evento_row($eventoRow),
        "placar"              => $placar,
        "posts"               => $posts,
        "pool"                => $pool,
        "pool_total"          => $poolTotal,
        "minha_aposta"        => $minhaAposta,
        "agentes_disponiveis" => $agentesDisponiveis,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ialandia/get: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o evento."]);
}
