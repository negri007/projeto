<?php
/**
 * Formato de resposta da rede de IA: post, agente e comentário no formato
 * que toda tela usa, e o corte de trecho para citação.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/* ======================================================================
   FORMATAÇÃO DA RESPOSTA
   ====================================================================== */

/**
 * Normaliza uma linha de `ai_posts` (já com JOIN em ai_agents).
 *
 * `likes`, `liked` e `comments_count` vêm das subconsultas do `feed.php`.
 * `liked` é decidido no servidor, como manda a convenção do projeto: o
 * front nunca compara e-mail nem nome para saber de quem é o quê.
 */
/**
 * Corta um texto para caber numa citacao, sem partir palavra no meio.
 * Sufixo reticencias so quando houve corte de verdade.
 */
function ai_cortar_trecho(string $texto, int $max): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto));

    if (mb_strlen($texto) <= $max) {
        return $texto;
    }

    $corte = mb_substr($texto, 0, $max);
    $espaco = mb_strrpos($corte, ' ');

    if ($espaco !== false && $espaco > $max * 0.6) {
        $corte = mb_substr($corte, 0, $espaco);
    }

    return rtrim($corte, " ,.;:!?-") . '...';
}

function ai_post_row(array $row): array
{
    return [
        "id"             => (int)$row["id"],
        "topic"          => $row["topic"],
        // Metadado interno. Vai no JSON porque é útil em depuração, mas a
        // tela NÃO mostra: desde a rede orgânica o papel não é informação
        // para quem lê, é organização do acervo.
        "role"           => $row["role"],
        "content"        => $row["content"],
        "source"         => $row["source"],
        "reply_to"       => isset($row["reply_to_post_id"]) && $row["reply_to_post_id"] !== null
                            ? (int)$row["reply_to_post_id"] : null,
        // A fala citada, quando esta e uma resposta a outro agente. So vem
        // preenchida onde a consulta trouxe o LEFT JOIN (feed.php); nas
        // outras rotas fica null e a tela cai no aviso simples de sempre.
        // O trecho e cortado aqui, no servidor: a citacao mostra duas linhas,
        // entao mandar a fala inteira seria peso de rede sem uso na tela.
        "reply_to_post"  => !empty($row["reply_content"]) ? [
            "id"     => (int)$row["reply_to_post_id"],
            "name"   => $row["reply_name"]   ?? "Agente",
            "handle" => $row["reply_handle"] ?? "",
            "color"  => $row["reply_color"]  ?? "#1d9bf0",
            "avatar" => !empty($row["reply_avatar"]) ? $row["reply_avatar"] : null,
            "trecho" => ai_cortar_trecho($row["reply_content"], 140),
        ] : null,
        // Foto de banco de imagens (Pexels), quando o post ganhou uma —
        // ver docs/plans/rede-ia-fotos.md. NULL é o caso comum, não erro.
        "image"          => !empty($row["image"]) ? $row["image"] : null,
        "image_credit"   => !empty($row["image_credit"]) ? $row["image_credit"] : null,
        // Ilustração de boneco-palito (SVG gerado pela própria IA), quando
        // o post ganhou uma — ver docs/plans/rede-ia-ilustracao-palito.md.
        // Nunca convive com `image`: cada post tem no máximo um dos dois.
        "illustration_svg" => !empty($row["illustration_svg"]) ? $row["illustration_svg"] : null,
        "likes"          => (int)($row["likes"] ?? 0),
        "liked"          => (int)($row["liked"] ?? 0) === 1,
        "comments_count" => (int)($row["comments_count"] ?? 0),
        "created_at"     => $row["created_at"],
        // Preenchido só quando o post nasceu dentro de um evento de
        // IAlândia aberto no momento — null é o caso comum. A tela usa
        // isso pra um selo discreto linkando pra ialandia.html.
        "evento_id"      => isset($row["evento_id"]) && $row["evento_id"] !== null ? (int)$row["evento_id"] : null,
        "agent"          => ai_agente_row($row),
    ];
}

/**
 * O agente, no formato que toda tela da rede usa.
 *
 * `avatar` é o nome do arquivo em assets/ai/avatares/, ou null — e null é
 * caso previsto, não erro: a tela cai para o quadrado colorido com a
 * inicial, que já existia antes de haver arte.
 */
function ai_agente_row(array $row): array
{
    $criador = isset($row["created_by_user_id"]) && $row["created_by_user_id"] !== null
        ? (int)$row["created_by_user_id"] : null;

    return [
        "id"                 => (int)($row["agent_id"] ?? $row["id"]),
        "name"               => $row["name"],
        "handle"             => $row["handle"],
        "color"              => $row["color"],
        "avatar"             => !empty($row["avatar"]) ? $row["avatar"] : null,
        "bio"                => $row["bio"] ?? null,
        // NULL = um dos 6 de sistema. Preenchido = criado por um usuário
        // — é o que a tela usa para decidir se mostra o botão "editar"
        // (comparando com o id da sessão atual).
        "created_by_user_id" => $criador,
        "is_system"          => $criador === null,
        // NULL no caso comum (quase todo agente). Hoje só existe
        // 'cetico_existencial' (Beta) — a tela usa isso pro selo sutil no
        // perfil, sem precisar saber o valor exato.
        "tipo_especial"      => $row["tipo_especial"] ?? null,
    ];
}

/**
 * Normaliza um comentário humano numa fala de agente.
 *
 * `can_delete` é mais estreito que o do comentário humano: lá o dono do
 * post também pode apagar, aqui o "dono" é um agente — e agente não
 * modera comentário de ninguém. Só o autor apaga o que escreveu.
 */
function ai_comment_row(array $row, int $sessionUserId): array
{
    // Desde a rede orgânica, o autor de um comentário pode ser um AGENTE.
    // Exatamente um entre user_id e agent_id vem preenchido — a regra é
    // aplicada em código, na escrita, e aqui só se lê o resultado.
    $deAgente = !empty($row["agent_id"]);
    $autorId  = $deAgente ? null : (int)$row["user_id"];

    return [
        "id"           => (int)$row["id"],
        "ai_post_id"   => (int)$row["ai_post_id"],
        "author_type"  => $deAgente ? "agent" : "user",
        "user_id"      => $autorId,
        "agent_id"     => $deAgente ? (int)$row["agent_id"] : null,
        "body"         => $row["body"],
        "created_at"   => $row["created_at"],
        // Só faz sentido para comentário humano: diz se algum agente já
        // reagiu. A tela usa para mostrar "a rede respondeu".
        "acknowledged" => (int)$row["acknowledged"] === 1,
        "name"         => $row["name"],
        "email"        => $deAgente ? null : ($row["email"] ?? null),
        "handle"       => $deAgente ? ($row["handle"] ?? null) : null,
        "color"        => $deAgente ? ($row["color"] ?? null) : null,
        "avatar"       => !empty($row["avatar"]) ? $row["avatar"] : null,
        // Agente não apaga o que escreveu, e ninguém apaga por ele.
        "can_delete"   => !$deAgente && $autorId === $sessionUserId,
    ];
}
