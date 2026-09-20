<?php
/**
 * Módulo 4 do seed — posts no feed de comércio, com curtidas e
 * comentários. Ver docs/plans/seed-echo.md, "Módulo 4".
 *
 * Idempotente por loja: loja que já tem post é pulada.
 *
 * VÍDEO — o que NÃO foi feito, e por quê. O plano pede 20% dos posts em
 * vídeo, gravando `video:<url>` em `loja_posts.imagem` e dizendo que "o
 * front-end detecta e renderiza <video>". Ele não detecta: js/loja-feed.js
 * monta `<img src="uploads/${encodeURIComponent(p.imagem)}">` e não há
 * nenhuma referência a vídeo em lugar nenhum do front (conferido em
 * loja-feed.js, loja-perfil.js e echo-feed.js). Gravar esse valor hoje
 * daria um `<img>` apontando para `uploads/video%3Ahttps%3A%2F%2F...` —
 * imagem quebrada em um post a cada cinco. Os posts saem com foto ou só
 * texto; quando o `<video>` existir no front, é um `if` aqui e a query de
 * vídeo da Pexels para religar.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";

/** Quantos posts por loja, e a distribuição de tipos do plano. */
const SEED_TIPOS_POST = ["produto", "produto", "promocao", "novidade", "info"];

/** Fallback por tipo. `%s` é o nome da loja. */
const SEED_POSTS_LOJA_FALLBACK = [
    "produto" => [
        "Chegou reposição do queridinho da casa. Passa aqui ou pergunta no chat que eu te mostro. ",
        "O item mais pedido da semana está disponível de novo. Fala comigo que eu separo o seu.",
    ],
    "promocao" => [
        "Promoção da semana: condição especial para quem fechar até sexta. Chama no chat para saber o valor.",
        "Semana de desconto na %s. Pergunta aqui que eu te passo a condição de hoje.",
    ],
    "novidade" => [
        "Novidade na %s: acabou de entrar no catálogo. Dá uma olhada e me diz o que achou.",
        "Coisa nova chegando por aqui essa semana. Quem quiser ver antes, é só chamar.",
    ],
    "info" => [
        "Funcionamento da %s: de segunda a sábado, das 9h às 19h. Pedido pelo chat, entrega combinada no WhatsApp.",
        "Aceitamos pix, débito e crédito. Qualquer dúvida sobre entrega, é só perguntar aqui.",
    ],
];

const SEED_COMENTARIOS_LOJA = [
    "Quanto custa a entrega no centro?",
    "Adoro essa loja, atendimento sempre rápido.",
    "Vocês abrem no feriado?",
    "Comprei semana passada e recomendo.",
    "Tem esse em outro tamanho?",
    "Pedido chegou certinho, obrigado!",
    "Dá pra reservar e buscar depois?",
    "Preço justo pelo que entrega.",
];

seed_titulo("posts do feed de comércio");

$pdo = seed_pdo();

$lojas = $pdo->query(
    "SELECT l.id, l.nome, l.categoria, l.user_id,
            (SELECT COUNT(*) FROM loja_posts lp WHERE lp.loja_id = l.id) AS posts
       FROM lojas l
       JOIN users u ON u.id = l.user_id
      WHERE u.email LIKE '%@echo.local'
      ORDER BY l.id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$lojas) {
    seed_diz("  nenhuma loja do seed no banco — rode seed_lojas.php antes.");
    return;
}

/** Nicho e query de foto pela lista do módulo 3, casando pelo nome. */
require_once __DIR__ . "/seed_lojas_dados.php";

$insere = $pdo->prepare(
    "INSERT INTO loja_posts (loja_id, conteudo, imagem, tipo, preco, created_at) VALUES (?, ?, ?, ?, ?, ?)"
);

foreach ($lojas as $loja) {
    if ((int)$loja["posts"] > 0) {
        seed_diz("  {$loja['nome']} já tem posts, pulando.");
        continue;
    }

    $meta  = seed_meta_da_loja((string)$loja["nome"]);
    $nicho = $meta["nicho"];

    seed_diz("  escrevendo posts de {$loja['nome']}...");

    $posts = seed_gerar_posts_loja($pdo, (string)$loja["nome"], $nicho, SEED_TIPOS_POST);

    foreach ($posts as $p) {
        // 70% com foto — os 30% restantes ficam só texto (ver a nota
        // sobre vídeo no topo).
        $imagem = mt_rand(1, 100) <= 70
            ? seed_pexels_imagem($meta["foto"] . " " . $nicho)
            : null;

        $insere->execute([
            (int)$loja["id"],
            $p["conteudo"],
            $imagem,
            $p["tipo"],
            $p["preco"],
            seed_data_passada(30),
        ]);

        seed_conta("posts_comercio");
    }
}

/* ----------------------------------------------------------------------
   CURTIDAS E COMENTÁRIOS
   ---------------------------------------------------------------------- */

$usuarios = array_map(fn($u) => (int)$u["id"], seed_usuarios_do_seed($pdo));

if (!$usuarios) {
    seed_diz("  sem usuários do seed para curtir — rode seed_usuarios.php antes.");
    return;
}

$todos = $pdo->query(
    "SELECT lp.id, l.user_id AS dono,
            (SELECT COUNT(*) FROM loja_post_likes x WHERE x.loja_post_id = lp.id) AS curtidas,
            (SELECT COUNT(*) FROM loja_post_comments c WHERE c.loja_post_id = lp.id) AS comentarios
       FROM loja_posts lp
       JOIN lojas l ON l.id = lp.loja_id
       JOIN users u ON u.id = l.user_id
      WHERE u.email LIKE '%@echo.local'"
)->fetchAll(PDO::FETCH_ASSOC);

seed_diz("  curtindo e comentando " . count($todos) . " posts de loja...");

$curte   = $pdo->prepare(
    "INSERT IGNORE INTO loja_post_likes (loja_post_id, user_id, created_at) VALUES (?, ?, ?)"
);
$comenta = $pdo->prepare(
    "INSERT INTO loja_post_comments (loja_post_id, user_id, conteudo, created_at) VALUES (?, ?, ?, ?)"
);

foreach ($todos as $p) {
    $postId = (int)$p["id"];
    $dono   = (int)$p["dono"];

    // Mesma regra do feed humano: post já curtido não recebe mais nada
    // numa segunda rodada do seed.
    $alvo = (int)$p["curtidas"] > 0 ? 0 : mt_rand(3, 20);

    if ($alvo > 0) {
        foreach (array_unique(seed_amostra($usuarios, $alvo)) as $quem) {
            if ($quem === $dono) {
                continue;
            }

            $curte->execute([$postId, $quem, seed_data_passada(25)]);

            if ($curte->rowCount() > 0) {
                seed_conta("curtidas_comercio");
            }
        }
    }

    // 30% dos posts, escolhidos pelo id — ver a nota do feed humano.
    if ((int)$p["comentarios"] === 0 && $postId % 10 < 3) {
        foreach (seed_amostra(SEED_COMENTARIOS_LOJA, mt_rand(1, 3)) as $texto) {
            $quem = $usuarios[array_rand($usuarios)];

            if ($quem === $dono) {
                continue;
            }

            $comenta->execute([$postId, $quem, $texto, seed_data_passada(20)]);
            seed_conta("comentarios_comercio");
        }
    }
}

$placar = seed_placar();

seed_diz(sprintf(
    "  %d posts de comércio, %d curtidas, %d comentários",
    $placar["posts_comercio"] ?? 0,
    $placar["curtidas_comercio"] ?? 0,
    $placar["comentarios_comercio"] ?? 0
));

/* ======================================================================
   GERAÇÃO
   ====================================================================== */

/**
 * Os cinco posts de uma loja, numa chamada só. Devolve sempre a mesma
 * quantidade de itens de $tipos, na mesma ordem de tipo — o fallback
 * completa o que a API não tiver dado.
 */
function seed_gerar_posts_loja(PDO $pdo, string $loja, string $nicho, array $tipos): array
{
    $quantos = count($tipos);

    $system = "Você cuida da divulgação da {$loja}, uma {$nicho} brasileira, na rede social Echo.\n"
        . "Gere {$quantos} posts para o feed de comércio, um de cada tipo nesta ordem: " . implode(", ", $tipos) . ".\n"
        . "Cada post promove a loja de forma natural, curta e sem exagero publicitário. Português do Brasil.\n"
        . "Quando o tipo for 'produto' ou 'promocao', inclua o preço no campo preco (número); nos outros, use null.\n"
        . "Responda APENAS com JSON: {\"posts\": [{\"conteudo\": \"...\", \"tipo\": \"produto\", \"preco\": 0.00}]}";

    $dados = seed_json(seed_ai_chamar($pdo, $system, "Posts da {$loja}.", 1200, 8000));
    $lista = $dados["posts"] ?? [];

    $saida = [];

    foreach ($tipos as $i => $tipo) {
        $bruto     = is_array($lista) && isset($lista[$i]) && is_array($lista[$i]) ? $lista[$i] : [];
        $conteudo  = trim((string)($bruto["conteudo"] ?? ""));

        if ($conteudo === "") {
            $banco    = SEED_POSTS_LOJA_FALLBACK[$tipo];
            $conteudo = sprintf($banco[array_rand($banco)], $loja);
        }

        // O tipo é do seed, não do modelo: a coluna é ENUM e um valor
        // fora dela derruba o INSERT.
        $preco = seed_preco_post($bruto["preco"] ?? null);

        $saida[] = [
            "conteudo" => mb_substr($conteudo, 0, 3000),
            "tipo"     => $tipo,
            "preco"    => in_array($tipo, ["produto", "promocao"], true) ? $preco : null,
        ];
    }

    return $saida;
}

/** Preço vindo do modelo: número, string com vírgula ou nada. */
function seed_preco_post($bruto): ?float
{
    if ($bruto === null || $bruto === "") {
        return null;
    }

    if (is_numeric($bruto)) {
        return round((float)$bruto, 2);
    }

    $n = preg_replace('/[^\d,.]/', "", (string)$bruto);

    if (strpos($n, ",") !== false) {
        $n = str_replace(".", "", $n);
        $n = str_replace(",", ".", $n);
    }

    return is_numeric($n) ? round((float)$n, 2) : null;
}
