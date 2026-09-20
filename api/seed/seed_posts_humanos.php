<?php
/**
 * Módulo 2 do seed — posts, etiquetas, curtidas e comentários no feed
 * humano. Ver docs/plans/seed-echo.md, "Módulo 2".
 *
 * Idempotente pela contagem: quem já tem posts do seed é pulado. A conta
 * é por usuário e não global — se a rodada anterior morreu no meio, os
 * que ficaram sem post são atendidos agora.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";

/** A partir de quantos posts um usuário é considerado "já semeado". */
const SEED_POSTS_MIN = 6;

/**
 * Fallback por grupo de interesse, usado quando não há chave de API ou
 * quando a chamada falha. Texto fixo mesmo: o seed nunca para por causa
 * da API, e post genérico é melhor que feed vazio.
 *
 * As etiquetas estão no texto de propósito — é delas que sai a aba de
 * tendências, via posts_sync_hashtags().
 */
const SEED_POSTS_FALLBACK = [
    "tech" => [
        "Passei a tarde refatorando uma função de 200 linhas. Agora são 40 e eu finalmente entendo o que ela faz. #tecnologia",
        "Dica de quem já perdeu um dia com isso: leia a mensagem de erro inteira. Ela costuma dizer exatamente o que está errado.",
        "Alguém mais acha que metade do trabalho de programar é escolher nome de variável? #devlife",
        "Subi meu primeiro projeto open source esse fim de semana. Duas estrelas e uma delas é minha. #opensource",
        "O café de hoje foi sustentado por três xícaras e um bug que só acontecia em produção. ☕",
        "Aprendi hoje que 'funciona na minha máquina' não é argumento em code review.",
        "Comecei a estudar IA de verdade, não só usar. A matemática por trás é linda e assustadora na mesma medida. #tecnologia",
        "Terminal aberto, música no fone, notificação desligada. É assim que eu rendo. #foco",
        "Quem começou hoje no código: vai dar certo. Todo mundo que você admira já não soube nada disso aqui.",
        "Atualizei uma dependência e quebrou tudo. Clássico de sexta-feira.",
    ],
    "criativo" => [
        "Gastei duas horas ajustando um espaçamento que ninguém vai notar. Mas eu ia notar. #design",
        "A melhor ideia do projeto apareceu no ônibus, sem caderno por perto. Sempre é assim.",
        "Luz da tarde é de graça e resolve 80% de uma foto. #fotografia",
        "Tem coisa que não é falta de talento, é falta de repetição. Fiz a mesma capa nove vezes até gostar.",
        "Descobri uma paleta de cor num prato de comida hoje. Inspiração não avisa a hora. 🎨",
        "Comecei a desenhar de novo depois de meses. Está feio e eu estou feliz.",
        "Regra que eu sigo: se precisa explicar o layout, o layout não está pronto. #design",
        "Passei o domingo inteiro ouvindo o mesmo disco e escrevendo. Valeu cada hora. #musica",
        "Alguém indica um lugar bom pra fotografar de madrugada na cidade?",
        "Nada substitui papel e caneta na primeira versão de uma ideia.",
    ],
    "saude" => [
        "Lembrete do dia: beber água conta como cuidar de si. Simples assim. #saude",
        "Treino não é castigo por ter comido. É investimento em continuar podendo fazer coisas. #movimento",
        "Paciente me disse hoje que voltou a subir escada sem cansar. Esse é o placar que importa.",
        "Dormir mal desmonta qualquer plano alimentar. Sono é parte do tratamento, não detalhe. #saude",
        "Comida de verdade, na maior parte das vezes. O resto é ajuste fino.",
        "Terceiro cachorro resgatado da casa completou um ano comigo hoje. 🐕",
        "Cuidar da cabeça também é saúde. Pedir ajuda é atitude de quem está bem. #saudemental",
        "Semana puxada de plantão. Café, respiro fundo e segue. ☕",
        "Alguém mais acha que caminhada é a atividade mais subestimada que existe?",
        "Progresso não é linear. Duas semanas paradas não apagam seis meses de trabalho.",
    ],
    "letras" => [
        "Terminei um livro de 400 páginas em três dias e agora não sei o que fazer da vida. 📚",
        "Corrigindo prova e rindo sozinha das respostas criativas. Ensinar é isso também.",
        "Palavra escolhida errada muda o sentido de um texto inteiro. Por isso a gente revisa. #escrita",
        "Aula boa é a que o aluno pergunta algo que eu não sei responder na hora.",
        "Café, caderno e uma hora sem celular. Melhor ritual de escrita que já testei. ☕",
        "Alguém tem indicação de livro brasileiro contemporâneo? Quero sair da zona de conforto.",
        "Ler notícia com calma e ler notícia rápido são duas atividades completamente diferentes. #jornalismo",
        "Levei o dia inteiro pra escrever um parágrafo que presta. Valeu.",
        "Grifar livro é um ato de conversa com o autor, muda minha ideia.",
        "A parte mais difícil de um texto é cortar o que a gente gostou de escrever.",
    ],
    "estilo" => [
        "Testei uma receita nova hoje e deu certo de primeira. Isso quase nunca acontece. 🍳",
        "Casa arrumada muda o humor. Não é papo de decorador, é verdade.",
        "Moda boa é a que te deixa esquecer que está vestindo aquilo. #moda",
        "Comprei uma peça de brechó por 30 reais e é a minha favorita do armário.",
        "Cozinhar pra quem a gente gosta é uma das formas mais honestas de dizer 'te quero bem'.",
        "Planta em casa não é enfeite, é companhia. Já são onze. 🌿",
        "Dica: uma luminária bem posicionada resolve mais que reforma. #decoracao",
        "Domingo de feira, cheiro de fruta madura e sacola pesada. O melhor programa da semana.",
        "Alguém mais separa a roupa da semana no domingo ou sou só eu?",
        "Prato bonito come melhor. É psicológico e eu aceito.",
    ],
    "negocios" => [
        "Planilha organizada no começo do ano economiza uma dor de cabeça enorme em abril. #financas",
        "A pergunta certa não é 'quanto eu ganho', é 'quanto sobra'.",
        "Comecei a anotar todo gasto por 30 dias. O resultado foi constrangedor e útil.",
        "Investimento chato é o que funciona. O empolgante costuma ser caro. #investimentos",
        "Cliente me perguntou como economizar imposto. Respondi: com organização, não com atalho.",
        "Reserva de emergência antes de qualquer outra coisa. Sempre.",
        "Juros compostos são a única mágica real que eu conheço.",
        "Fechamento de mês feito. Agora sim, fim de semana. ☕",
        "Dinheiro é assunto de conversa em casa, não tabu. Quanto antes começar, melhor.",
        "Anotar as metas muda a chance de cumprir. Parece bobo, funciona.",
    ],
];

/** Comentários de fallback — servem para qualquer post. */
const SEED_COMENTARIOS_FALLBACK = [
    "Isso é a mais pura verdade.",
    "Passei por exatamente isso semana passada 😅",
    "Boa! Vou tentar aqui também.",
    "Concordo demais.",
    "Me identifiquei com cada palavra.",
    "Conta mais sobre isso depois!",
    "Já salvei pra ler com calma.",
    "Precisava ler isso hoje, obrigado.",
    "Muito bom, gostei do ponto de vista.",
    "Mandou bem demais.",
    "Faz sentido, nunca tinha pensado assim.",
    "Top! Continua postando essas coisas.",
];

seed_titulo("posts do feed humano");

$pdo      = seed_pdo();
$usuarios = seed_usuarios_do_seed($pdo);

if (!$usuarios) {
    seed_diz("  nenhum usuário do seed no banco — rode seed_usuarios.php antes.");
    return;
}

/** grupo/foto de cada pessoa, pela lista central. */
$perfil = [];
foreach (SEED_PESSOAS as [$nome, $email, $bio, $queryFoto, $grupo]) {
    $perfil[$email] = ["foto" => $queryFoto, "grupo" => $grupo];
}

$conta     = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
$insere    = $pdo->prepare("INSERT INTO posts (user_id, content, image, created_at) VALUES (?, ?, ?, ?)");
$postsIds  = [];   // post_id => user_id (para curtidas e comentários)

foreach ($usuarios as $u) {
    $conta->execute([$u["id"]]);

    if ((int)$conta->fetchColumn() >= SEED_POSTS_MIN) {
        seed_diz("  {$u['name']} já tem posts, pulando.");
        continue;
    }

    $grupo  = $perfil[$u["email"]]["grupo"] ?? "tech";
    $query  = $perfil[$u["email"]]["foto"]  ?? "lifestyle";
    $quanto = mt_rand(8, 12);

    seed_diz("  criando {$quanto} posts de {$u['name']}...");

    $textos = seed_gerar_posts($pdo, $u["name"], (string)$u["bio"], $quanto, $grupo);

    foreach ($textos as $texto) {
        // 30% com imagem, como o plano pede.
        $imagem = mt_rand(1, 100) <= 30 ? seed_pexels_imagem($query) : null;

        $insere->execute([$u["id"], $texto, $imagem, seed_data_passada(45)]);

        $postId = (int)$pdo->lastInsertId();
        $postsIds[$postId] = (int)$u["id"];

        // Mesma função que api/posts/create.php usa: a etiqueta do seed
        // entra na tendência pelo mesmo caminho da etiqueta de gente.
        posts_sync_hashtags($pdo, $postId, $texto);

        seed_conta("posts_humanos");
    }
}

/* ----------------------------------------------------------------------
   CURTIDAS E COMENTÁRIOS

   Rodam sobre TODOS os posts das contas do seed, e não só sobre os
   criados agora: numa segunda rodada os posts já existem e continuariam
   sem interação nenhuma.
   ---------------------------------------------------------------------- */

$idsUsuarios = array_map(fn($u) => (int)$u["id"], $usuarios);
$marcadores  = implode(",", array_fill(0, count($idsUsuarios), "?"));

$todosPosts = $pdo->prepare(
    "SELECT p.id, p.user_id, p.content,
            (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS curtidas,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comentarios
       FROM posts p
      WHERE p.user_id IN ($marcadores)"
);
$todosPosts->execute($idsUsuarios);
$posts = $todosPosts->fetchAll(PDO::FETCH_ASSOC);

seed_diz("  curtindo e comentando " . count($posts) . " posts...");

// Amizades, para o sorteio dar preferência a quem é amigo.
$amigos = [];
$stmtAmigos = $pdo->prepare(
    "SELECT user_id, friend_id FROM friends WHERE status = 'accepted' AND user_id IN ($marcadores)"
);
$stmtAmigos->execute($idsUsuarios);

foreach ($stmtAmigos->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $amigos[(int)$l["user_id"]][] = (int)$l["friend_id"];
}

$curte    = $pdo->prepare("INSERT IGNORE INTO post_likes (user_id, post_id, created_at) VALUES (?, ?, ?)");
$comenta  = $pdo->prepare("INSERT INTO comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, ?)");

foreach ($posts as $p) {
    $postId = (int)$p["id"];
    $dono   = (int)$p["user_id"];

    /* Quem pode curtir: os amigos do dono primeiro, o resto depois. O
       peso sai da repetição na lista — amigo entra duas vezes no sorteio,
       o que aproxima o feed de "quem curte é quem te conhece". */
    $candidatos = array_values(array_filter($idsUsuarios, fn($x) => $x !== $dono));
    $pool       = array_merge($candidatos, $amigos[$dono] ?? [], $amigos[$dono] ?? []);

    /* Post que já tem curtida foi atendido numa rodada anterior. Sem
       este corte, cada execução sorteia um alvo novo entre 2 e 15 e vai
       empurrando todo post para 15 — o seed ficaria "somando" a cada
       rodada em vez de deixar a base do mesmo tamanho. */
    $alvo = (int)$p["curtidas"] > 0 ? 0 : mt_rand(2, 15);

    if ($alvo > 0) {
        foreach (array_unique(seed_amostra($pool, $alvo)) as $quem) {
            if ($quem === $dono) {
                continue;
            }

            $curte->execute([$quem, $postId, seed_data_passada(40)]);

            if ($curte->rowCount() > 0) {
                seed_conta("curtidas");
            }
        }
    }

    /* 40% dos posts recebem de 1 a 4 comentários — escolhidos pelo id, e
       não por sorteio: com `mt_rand`, os 60% que passaram batidos nesta
       rodada seriam sorteados de novo na próxima, e depois de algumas
       execuções todo post teria comentário. Pelo id, é sempre o mesmo
       conjunto. */
    if ((int)$p["comentarios"] === 0 && $postId % 10 < 4) {
        $quantos = mt_rand(1, 4);
        $textos  = seed_gerar_comentarios($pdo, (string)$p["content"], $quantos);

        foreach ($textos as $i => $texto) {
            $quem = $pool[array_rand($pool)];

            if ($quem === $dono) {
                continue;
            }

            $comenta->execute([$postId, $quem, $texto, seed_data_passada(35)]);
            seed_conta("comentarios");
        }
    }
}

$placar = seed_placar();

seed_diz(sprintf(
    "  %d posts, %d curtidas, %d comentários",
    $placar["posts_humanos"] ?? 0,
    $placar["curtidas"] ?? 0,
    $placar["comentarios"] ?? 0
));

/* ======================================================================
   GERAÇÃO
   ====================================================================== */

/**
 * Posts de uma pessoa. Uma chamada de API por pessoa (lote), como o
 * plano pede. Falhou ou não há chave: cai no banco fixo do grupo, sem
 * repetir texto dentro da mesma pessoa.
 */
function seed_gerar_posts(PDO $pdo, string $nome, string $bio, int $quantos, string $grupo): array
{
    $system = "Você é {$nome}. Bio: \"{$bio}\".\n"
        . "Gere {$quantos} posts para uma rede social brasileira.\n"
        . "Cada post soa natural e pessoal, como alguém realmente escreveria — nada de texto publicitário.\n"
        . "Varie entre opinião, experiência do dia, pergunta para os seguidores, humor e reflexão.\n"
        . "Alguns com hashtag relevante ao assunto. Tamanhos variados: alguns de uma linha, outros de três ou quatro.\n"
        . "Responda APENAS com JSON: {\"posts\": [\"texto 1\", \"texto 2\"]}";

    $bruto = seed_ai_chamar($pdo, $system, "Gere os {$quantos} posts agora.", 2000, 12000);
    $dados = seed_json($bruto);
    $posts = $dados["posts"] ?? null;

    if (is_array($posts) && count($posts) >= 3) {
        $limpos = [];

        foreach ($posts as $p) {
            $p = trim((string)$p);

            if ($p !== "") {
                $limpos[] = mb_substr($p, 0, 1000);
            }
        }

        if ($limpos) {
            return array_slice($limpos, 0, $quantos);
        }
    }

    // Fallback: o banco do grupo, embaralhado e completado se faltar.
    $banco = SEED_POSTS_FALLBACK[$grupo] ?? SEED_POSTS_FALLBACK["tech"];
    shuffle($banco);

    while (count($banco) < $quantos) {
        $banco = array_merge($banco, $banco);
    }

    return array_slice($banco, 0, $quantos);
}

/**
 * Comentários de um post. Chamada de API só de vez em quando (1 em 4
 * postagens comentadas): o plano prevê ~15 chamadas para comentário no
 * total, e uma por post estouraria isso muitas vezes.
 */
function seed_gerar_comentarios(PDO $pdo, string $post, int $quantos): array
{
    if (mt_rand(1, 4) === 1) {
        $system = "Gere {$quantos} comentários curtos e naturais de pessoas diferentes para o post de rede social abaixo.\n"
            . "Português do Brasil, informal, no máximo duas linhas cada. Nada de emoji em todos.\n"
            . "Responda APENAS com JSON: {\"comentarios\": [\"...\"]}";

        $dados = seed_json(seed_ai_chamar($pdo, $system, $post, 600, 3000));
        $lista = $dados["comentarios"] ?? null;

        if (is_array($lista) && $lista) {
            $limpos = [];

            foreach ($lista as $c) {
                $c = trim((string)$c);

                if ($c !== "") {
                    $limpos[] = mb_substr($c, 0, 500);
                }
            }

            if ($limpos) {
                return array_slice($limpos, 0, $quantos);
            }
        }
    }

    return seed_amostra(SEED_COMENTARIOS_FALLBACK, $quantos);
}
