<?php
/**
 * Módulo 5 do seed — agente pessoal de cada usuário e as memórias dele.
 * Ver docs/plans/seed-echo.md, "Módulo 5".
 *
 * Idempotente por `user_agents.user_id` (UNIQUE) e, nas memórias, pela
 * contagem por usuário.
 *
 * As memórias saem do que a conta JÁ FEZ no banco — os posts do módulo 2
 * e as curtidas que ela deu —, no mesmo formato que os ganchos de
 * `posts/create.php` e `posts/like.php` gravam em produção: tipo `post`
 * guarda o texto do próprio post, tipo `curtida` guarda o TEXTO do post
 * curtido, nunca o id (ver o comentário em api/posts/like.php:53). É o
 * que faz o agente já conhecer o dono no primeiro acesso, sem inventar
 * memória de coisa que não aconteceu.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";
require_once __DIR__ . "/../user_agent/helpers.php";

/**
 * Distribuição de autonomia do plano: 8 em "só observa", 7 em "sugere",
 * 4 em "age sozinho" e 1 em "autônomo total". A ordem é a da lista de
 * pessoas, então o mesmo seed dá sempre o mesmo desenho.
 */
const SEED_AUTONOMIAS = [0, 1, 0, 1, 0, 1, 0, 2, 1, 0, 1, 2, 0, 1, 2, 0, 1, 0, 2, 3];

/** Quantas memórias por usuário, no máximo (o plano pede 10 a 20). */
const SEED_MEMORIAS_MIN = 10;
const SEED_MEMORIAS_MAX = 20;

seed_titulo("agentes pessoais e memórias");

$pdo      = seed_pdo();
$usuarios = seed_usuarios_do_seed($pdo);

if (!$usuarios) {
    seed_diz("  nenhum usuário do seed no banco — rode seed_usuarios.php antes.");
    return;
}

$buscaAgente = $pdo->prepare("SELECT id, personalidade FROM user_agents WHERE user_id = ?");
$criaAgente  = $pdo->prepare(
    "INSERT INTO user_agents (user_id, nome, personalidade, autonomia, ativo, created_at)
     VALUES (?, ?, ?, ?, 1, ?)"
);
$poePersonalidade = $pdo->prepare(
    "UPDATE user_agents SET personalidade = ?, autonomia = ? WHERE user_id = ?"
);

$contaMemoria = $pdo->prepare("SELECT COUNT(*) FROM user_agent_memoria WHERE user_id = ?");
$criaMemoria  = $pdo->prepare(
    "INSERT INTO user_agent_memoria (user_id, tipo, conteudo, peso, created_at) VALUES (?, ?, ?, ?, ?)"
);

$meusPosts = $pdo->prepare("SELECT content FROM posts WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$meusLikes = $pdo->prepare(
    "SELECT p.content
       FROM post_likes pl
       JOIN posts p ON p.id = pl.post_id
      WHERE pl.user_id = ?
      ORDER BY pl.id DESC
      LIMIT 20"
);

foreach ($usuarios as $i => $u) {
    $userId    = (int)$u["id"];
    $autonomia = SEED_AUTONOMIAS[$i] ?? 0;

    // "Echo do Lucas", e não "Echo do Lucas Oliveira": o primeiro nome é
    // como o dono chamaria o próprio agente.
    $primeiro = explode(" ", trim((string)$u["name"]))[0];
    $nome     = mb_substr("Echo do " . $primeiro, 0, 100);

    $buscaAgente->execute([$userId]);
    $agente = $buscaAgente->fetch(PDO::FETCH_ASSOC);

    if ($agente && trim((string)$agente["personalidade"]) !== "") {
        seed_diz("  {$u['name']} já tem agente configurado.");
    } else {
        seed_diz("  configurando {$nome} (autonomia {$autonomia})...");

        $personalidade = seed_personalidade($pdo, (string)$u["name"], (string)$u["bio"]);

        if ($agente) {
            /* Agente já existe sem personalidade: é o que `me.php` cria
               sozinho na primeira visita de qualquer conta, em autonomia
               0. Completar é o certo — apagar e recriar perderia a data
               de criação e qualquer memória já ligada a ele. */
            $poePersonalidade->execute([$personalidade, $autonomia, $userId]);
        } else {
            $criaAgente->execute([$userId, $nome, $personalidade, $autonomia, seed_data_passada(60)]);
        }

        seed_conta("agentes_pessoais");
    }

    /* ------------------------------------------------------------------
       MEMÓRIAS
       ------------------------------------------------------------------ */

    $contaMemoria->execute([$userId]);

    if ((int)$contaMemoria->fetchColumn() >= SEED_MEMORIAS_MIN) {
        continue;
    }

    $meusPosts->execute([$userId]);
    $posts = $meusPosts->fetchAll(PDO::FETCH_COLUMN);

    $meusLikes->execute([$userId]);
    $curtidos = $meusLikes->fetchAll(PDO::FETCH_COLUMN);

    $quantas = mt_rand(SEED_MEMORIAS_MIN, SEED_MEMORIAS_MAX);
    $gravou  = 0;

    // Post do próprio dono pesa mais que curtida: é ele escrevendo, não
    // ele concordando com alguém. Mesmo peso que o gancho usa por padrão
    // para post (1) — o 2 aqui é só para o post ficar na frente da
    // curtida quando a poda entrar.
    foreach ($posts as $texto) {
        if ($gravou >= $quantas) {
            break;
        }

        $texto = trim((string)$texto);

        if ($texto === "") {
            continue;
        }

        $criaMemoria->execute([
            $userId,
            "post",
            mb_substr($texto, 0, USER_AGENT_MEMORIA_MAX_CHARS),
            2,
            seed_data_passada(40),
        ]);

        $gravou++;
        seed_conta("memorias");
    }

    foreach ($curtidos as $texto) {
        if ($gravou >= $quantas) {
            break;
        }

        $texto = trim((string)$texto);

        if ($texto === "") {
            continue;
        }

        $criaMemoria->execute([
            $userId,
            "curtida",
            mb_substr($texto, 0, USER_AGENT_MEMORIA_MAX_CHARS),
            1,
            seed_data_passada(35),
        ]);

        $gravou++;
        seed_conta("memorias");
    }

    seed_diz("    {$gravou} memórias gravadas.");
}

$placar = seed_placar();

seed_diz(sprintf(
    "  %d agentes pessoais, %d memórias",
    $placar["agentes_pessoais"] ?? 0,
    $placar["memorias"] ?? 0
));

/* ======================================================================
   GERAÇÃO
   ====================================================================== */

/**
 * Como esta pessoa escreve. Uma chamada por usuário; sem API, um texto
 * derivado da própria bio — genérico, mas verdadeiro sobre a conta.
 */
function seed_personalidade(PDO $pdo, string $nome, string $bio): string
{
    $system = "Com base nesta bio de usuário de rede social: \"{$bio}\"\n"
        . "Descreva em poucas linhas como esta pessoa escreve nas redes: tom (formal ou informal),\n"
        . "uso de emoji, tamanho dos textos e assuntos preferidos.\n"
        . "Máximo 100 palavras, em português do Brasil. Responda APENAS com o texto, sem JSON e sem aspas.";

    $texto = seed_ai_chamar($pdo, $system, "Pessoa: {$nome}. Bio: {$bio}", 400, 1200);

    if ($texto !== null && trim($texto) !== "") {
        return mb_substr(trim($texto), 0, 2000);
    }

    $bioLimpa = trim($bio) !== "" ? rtrim(trim($bio), ".") : "assuntos do dia a dia";

    return "Escreve de forma natural e direta, em textos curtos. Usa emoji de vez em quando, "
         . "não é formal e comenta o que vive. Assuntos preferidos: {$bioLimpa}.";
}
