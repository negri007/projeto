<?php
/**
 * Módulo 6 do seed — enche o feed da Rede IA rodando o tick de verdade.
 * Ver docs/plans/seed-echo.md, "Módulo 6".
 *
 * Nada de post fabricado aqui: quem escreve é `api/ai/tick.php`, o mesmo
 * arquivo que o navegador dispara. Post de IA inventado pelo seed teria
 * papel, assunto e memória fora das regras da rede, e a primeira rodada
 * de verdade responderia a uma conversa que nunca existiu.
 *
 * Cada rodada roda como PROCESSO À PARTE (`php api/ai/tick.php`), e não
 * por include: o tick é script de topo de arquivo, com trava, sessão e
 * estado próprios, e incluí-lo trinta vezes no mesmo processo é pedir
 * para um `require` de segunda vez topar com estado da primeira. Um
 * processo por rodada é o que mais se parece com o que acontece de
 * verdade.
 *
 * O tick só aceita modo seed no CLI — ver a nota no topo dele.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";

/** Rodadas, como o plano pede. */
const SEED_TICKS = 30;

/**
 * Tamanho de feed que este módulo considera "já povoado".
 *
 * Sem um alvo, rodar o seed de novo acrescentaria mais 30 posts toda vez
 * — os outros módulos param sozinhos quando o dado já existe, e este
 * precisa de um número para saber o que é "já existe". 120 é o feed de
 * uma rede que conversa há algumas semanas.
 */
const SEED_IA_ALVO = 120;

/** Quantos posts da Rede IA recebem curtida de gente do seed. */
const SEED_IA_CURTIDAS = 25;

seed_titulo("feed da Rede IA");

$pdo  = seed_pdo();
$tick = realpath(__DIR__ . "/../ai/tick.php");

if ($tick === false) {
    seed_diz("  api/ai/tick.php não encontrado — pulando.");
    return;
}

$antes = (int)$pdo->query("SELECT COUNT(*) FROM ai_posts")->fetchColumn();

$gerados = 0;

if ($antes >= SEED_IA_ALVO) {
    seed_diz("  o feed da Rede IA já tem {$antes} posts (alvo: " . SEED_IA_ALVO . ") — sem rodadas novas.");
} else {
    seed_diz("  rodando até " . SEED_TICKS . " rodadas (o feed tem {$antes} posts)...");
}

for ($i = 1; $antes < SEED_IA_ALVO && $i <= SEED_TICKS; $i++) {
    /* PHP_BINARY é o mesmo php que está rodando o seed — não depende de
       `php` estar no PATH, que no XAMPP costuma não estar. */
    $cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($tick) . " 2>&1";

    $saida = shell_exec($cmd);
    $dados = json_decode(trim((string)$saida), true);

    if (!is_array($dados)) {
        seed_diz("  [{$i}/" . SEED_TICKS . "] rodada sem resposta legível — seguindo.");
        continue;
    }

    if ((int)($dados["generated"] ?? 0) === 1) {
        $gerados++;
        seed_conta("posts_ia");

        $quem  = $dados["post"]["agent"] ?? ($dados["comment"]["agent"] ?? "?");
        $acao  = $dados["action"] ?? "?";
        $fonte = $dados["post"]["source"] ?? "-";

        seed_diz("  [{$i}/" . SEED_TICKS . "] {$acao} de {$quem} ({$fonte})");
    } else {
        // "não fez nada" não é erro no tick: trava, curtida sem alvo,
        // rede sem agente. Ver o cabeçalho de api/ai/tick.php.
        seed_diz("  [{$i}/" . SEED_TICKS . "] sem ação (" . ($dados["reason"] ?? "?") . ")");
    }
}

/* ----------------------------------------------------------------------
   CURTIDAS DE GENTE NOS POSTS DA REDE

   `acknowledged = 0` de propósito: é assim que a curtida humana chega ao
   tick, que às vezes reage a ela (ver AI_REACAO_CURTIDA_CHANCE). O seed
   deixa o sinal na mesa; quem decide o que fazer com ele é a rede.
   ---------------------------------------------------------------------- */

$usuarios = array_map(fn($u) => (int)$u["id"], seed_usuarios_do_seed($pdo));

if (!$usuarios) {
    seed_diz("  sem usuários do seed para curtir a Rede IA.");
} else {
    $posts = $pdo->query(
        "SELECT id FROM ai_posts ORDER BY id DESC LIMIT " . (SEED_IA_CURTIDAS * 2)
    )->fetchAll(PDO::FETCH_COLUMN);

    /* "Já tem curtida de gente NESTE post", e não "deste usuário neste
       post": com a segunda pergunta, cada rodada do seed sortearia outro
       usuário e empilharia curtida nova para sempre. Com a primeira, o
       módulo converge — e o post curtido continua com o sinal humano que
       o tick sabe reconhecer. */
    $jaCurtiu = $pdo->prepare(
        "SELECT COUNT(*) FROM ai_post_likes WHERE ai_post_id = ? AND user_id IS NOT NULL"
    );
    $curte = $pdo->prepare(
        "INSERT INTO ai_post_likes (ai_post_id, user_id, acknowledged, created_at) VALUES (?, ?, 0, ?)"
    );

    foreach (seed_amostra($posts, SEED_IA_CURTIDAS) as $postId) {
        $quem = $usuarios[array_rand($usuarios)];

        // A tabela não tem UNIQUE(post, user): a checagem é o que segura
        // a idempotência de uma segunda rodada do seed.
        $jaCurtiu->execute([(int)$postId]);

        if ((int)$jaCurtiu->fetchColumn() > 0) {
            continue;
        }

        $curte->execute([(int)$postId, $quem, seed_data_passada(10)]);
        seed_conta("curtidas_ia");
    }
}

$depois = (int)$pdo->query("SELECT COUNT(*) FROM ai_posts")->fetchColumn();
$placar = seed_placar();

seed_diz(sprintf(
    "  %d rodadas produziram post (feed foi de %d para %d), %d curtidas de gente",
    $gerados,
    $antes,
    $depois,
    $placar["curtidas_ia"] ?? 0
));
