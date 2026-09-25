<?php
/**
 * Seed de DEMO: vídeos de marketing no feed + 1 semana de histórico.
 *
 *     C:\xampp\php\php.exe api/seed/seed_demo_videos.php
 *
 * Complementa o seed principal (seed_completo.php), que de propósito NÃO
 * botou vídeo no feed de comércio porque na época o front não renderizava
 * <video> (ver api/seed/README.md, nota 2). O motor e o loja-feed.js já
 * existem, então aqui a gente:
 *   1. dá logo/capa às lojas que estão sem (25..28);
 *   2. publica os vídeos do motor (videos_gerados 'pronto') como loja_posts
 *      no formato que o feed lê: imagem = "video:<arquivo_local>" — eles
 *      tocam sozinhos no comércio (IntersectionObserver do loja-feed.js);
 *   3. copia alguns .mp4 já renderizados para outras lojas boas, pra o
 *      "explorer" de comércio ficar variado;
 *   4. cria ~1 semana de histórico (chat cliente↔loja com perguntas, e
 *      comentários nos posts) pro agente da loja ter o que ler.
 *
 * SÓ CLI (mesma guarda do resto de api/seed/): não pede sessão e escreve
 * dezenas de linhas.
 *
 * Idempotente: rodar duas vezes não duplica — cada passo confere antes.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";

$RAIZ     = dirname(__DIR__, 2);
$UPLOADS  = $RAIZ . "/uploads";

function linha(string $s): void { echo $s . "\n"; }

/** created_at aleatório nos últimos $dias dias (string p/ MySQL). */
function quando(int $dias = 7): string
{
    $ts = time() - random_int(0, $dias * 86400) - random_int(0, 3600);
    return date("Y-m-d H:i:s", $ts);
}

linha("== seed demo: vídeos + histórico ==");

/* --------------------------------------------------------------------
   1. LOGO / CAPA para as lojas que estão sem (25..28).
   Reusa imagens seed_*.jpg que já estão em uploads/ (o front monta
   uploads/<nome>). Não baixa nada.
   -------------------------------------------------------------------- */
$imgs = array_values(array_map("basename", glob($UPLOADS . "/seed_*.jpg") ?: []));
$semLogo = $pdo->query("SELECT id FROM lojas WHERE logo IS NULL OR banner IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$i = 0; $nLogo = 0;
foreach ($semLogo as $lojaId) {
    if (count($imgs) < $i + 2) { break; }
    $logo   = $imgs[$i++];
    $banner = $imgs[$i++];
    $pdo->prepare("UPDATE lojas SET logo = COALESCE(logo, ?), banner = COALESCE(banner, ?) WHERE id = ?")
        ->execute([$logo, $banner, $lojaId]);
    $nLogo++;
}
linha("1. logo/capa preenchidas: $nLogo loja(s)");

/* --------------------------------------------------------------------
   2. PUBLICA os vídeos do motor ('pronto') como loja_posts.
   Formato do feed: imagem = "video:<arquivo_local>". Dedupe pela imagem,
   igual ao post_criar.php. created_at espalhado na última semana.
   -------------------------------------------------------------------- */
$legendas = [
    "novidade"  => ["Chegou coisa nova por aqui 👀", "Olha esse lançamento 🔥", "Tá on! Passa pra ver 👇", "Novidade fresquinha pra vocês"],
    "promocao"  => ["Promoção relâmpago, só essa semana!", "Desconto especial pra quem chegar primeiro 🏃", "Bora aproveitar? Oferta por tempo limitado", "Preço bom demais pra deixar passar"],
];
$stmtVid = $pdo->query(
    "SELECT id, loja_id, arquivo_local FROM videos_gerados
      WHERE modelo IS NOT NULL AND status = 'pronto'
        AND arquivo_local IS NOT NULL AND arquivo_local <> ''
      ORDER BY id"
);
$nPost = 0;
foreach ($stmtVid->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $arq = $v["arquivo_local"];
    if (!is_file($UPLOADS . "/" . $arq)) { continue; } // arquivo sumiu do disco
    $imagem = "video:" . $arq;

    $ja = $pdo->prepare("SELECT id FROM loja_posts WHERE loja_id = ? AND imagem = ? LIMIT 1");
    $ja->execute([(int)$v["loja_id"], $imagem]);
    if ($ja->fetchColumn()) { continue; }

    $tipo    = random_int(0, 1) ? "promocao" : "novidade";
    $legenda = $legendas[$tipo][array_rand($legendas[$tipo])];

    $pdo->prepare(
        "INSERT INTO loja_posts (loja_id, conteudo, imagem, tipo, created_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([(int)$v["loja_id"], $legenda, $imagem, $tipo, quando(7)]);
    $nPost++;
}
linha("2. vídeos do motor publicados no feed: $nPost post(s)");

/* --------------------------------------------------------------------
   3. COPIA .mp4 já renderizados para lojas boas sem vídeo, pra variar o
   explorer de comércio. Cada loja-alvo ganha 1 vídeo (copiado de uma
   fonte 'pronto'), registrado em videos_gerados + publicado no feed.
   -------------------------------------------------------------------- */
$fontes = $pdo->query(
    "SELECT arquivo_local, formato, modelo FROM videos_gerados
      WHERE status = 'pronto' AND arquivo_local IS NOT NULL
      ORDER BY id LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// Lojas boas (nome/logo do seed) que ainda não têm vídeo publicado.
$alvos = $pdo->query(
    "SELECT l.id FROM lojas l
      WHERE l.logo IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM loja_posts p WHERE p.loja_id = l.id AND p.imagem LIKE 'video:%')
      ORDER BY l.id LIMIT 6"
)->fetchAll(PDO::FETCH_COLUMN);

$nCopia = 0;
foreach ($alvos as $k => $lojaId) {
    if (empty($fontes)) { break; }
    $fonte = $fontes[$k % count($fontes)];
    $src   = $UPLOADS . "/" . $fonte["arquivo_local"];
    if (!is_file($src)) { continue; }

    $dir = $UPLOADS . "/videos/lojas/" . $lojaId;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $nome = "mkt_seed_" . $lojaId . "_" . substr(md5($fonte["arquivo_local"] . $lojaId), 0, 10) . ".mp4";
    $rel  = "videos/lojas/" . $lojaId . "/" . $nome;
    $dest = $UPLOADS . "/" . $rel;

    if (!is_file($dest) && !@copy($src, $dest)) { continue; }

    // registra o vídeo (idempotente pelo arquivo_local)
    $ja = $pdo->prepare("SELECT id FROM videos_gerados WHERE loja_id = ? AND arquivo_local = ? LIMIT 1");
    $ja->execute([$lojaId, $rel]);
    if (!$ja->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO videos_gerados (loja_id, prompt, modelo, formato, status, provider, arquivo_local, created_at)
             VALUES (?, ?, ?, ?, 'pronto', 'motor', ?, ?)"
        )->execute([$lojaId, "[seed demo]", $fonte["modelo"], $fonte["formato"], $rel, quando(7)]);
    }

    // publica no feed
    $imagem = "video:" . $rel;
    $ja = $pdo->prepare("SELECT id FROM loja_posts WHERE loja_id = ? AND imagem = ? LIMIT 1");
    $ja->execute([$lojaId, $imagem]);
    if (!$ja->fetchColumn()) {
        $tipo    = random_int(0, 1) ? "promocao" : "novidade";
        $legenda = $legendas[$tipo][array_rand($legendas[$tipo])];
        $pdo->prepare(
            "INSERT INTO loja_posts (loja_id, conteudo, imagem, tipo, created_at) VALUES (?, ?, ?, ?, ?)"
        )->execute([$lojaId, $legenda, $imagem, $tipo, quando(7)]);
        $nCopia++;
    }
}
linha("3. vídeos copiados p/ lojas extras: $nCopia");

/* --------------------------------------------------------------------
   4. HISTÓRICO DE 1 SEMANA pro agente da loja ler:
   - chat cliente↔loja com perguntas (algumas SEM resposta do agente, de
     propósito: alimenta o "relatório do fim do dia / o que não soube");
   - comentários de clientes nos posts recentes das lojas.
   Clientes = usuários que NÃO são donos da loja.
   -------------------------------------------------------------------- */
$perguntasRespondidas = [
    ["Boa tarde! Vocês têm esse em outro tamanho?", "Temos sim! Posso te mostrar as opções disponíveis. 😊"],
    ["Qual o horário de funcionamento?", "Funcionamos de segunda a sábado, das 9h às 19h."],
    ["Fazem entrega?", "Fazemos entrega em toda a região central, com taxa a combinar."],
    ["Quanto custa o frete pro centro?", "Pro centro a entrega sai por R$ 8,00."],
    ["Aceitam cartão?", "Aceitamos crédito, débito e Pix. 💳"],
];
// Perguntas SEM resposta do agente (ele não soube / ficou pendente):
$perguntasPendentes = [
    "Vocês entregam no domingo?",
    "Aceita Pix parcelado?",
    "Tem estacionamento no local?",
    "Fazem nota fiscal pra empresa?",
    "Consigo retirar na loja hoje ainda?",
];
$comentarios = ["Quero! 😍", "Quanto custa?", "Vocês entregam?", "Amei isso", "Tá disponível ainda?", "Manda no direct o valor", "Que lindo 👏"];

// lojas com quem popular o histórico: as que têm vídeo publicado (as da demo)
$lojasDemo = $pdo->query(
    "SELECT DISTINCT loja_id FROM loja_posts WHERE imagem LIKE 'video:%' ORDER BY loja_id"
)->fetchAll(PDO::FETCH_COLUMN);

$nChat = 0; $nMsg = 0; $nPend = 0; $nCom = 0;
foreach ($lojasDemo as $lojaId) {
    // Já tem histórico? Não empilha em cima (mantém a rodada repetível).
    $temChats = (int)$pdo->query("SELECT COUNT(*) FROM loja_chats WHERE loja_id = " . (int)$lojaId)->fetchColumn();
    if ($temChats >= 3) { continue; }

    // dono da loja (não vira cliente dele mesmo)
    $dono = (int)$pdo->query("SELECT user_id FROM lojas WHERE id = " . (int)$lojaId)->fetchColumn();
    // 3 clientes quaisquer diferentes do dono
    $clientes = $pdo->prepare("SELECT id FROM users WHERE id <> ? ORDER BY RAND() LIMIT 3");
    $clientes->execute([$dono]);
    $clientes = $clientes->fetchAll(PDO::FETCH_COLUMN);

    foreach ($clientes as $idx => $cli) {
        // um chat por par (UNIQUE loja_id,user_id): pula se já existe
        $ja = $pdo->prepare("SELECT id FROM loja_chats WHERE loja_id = ? AND user_id = ?");
        $ja->execute([$lojaId, $cli]);
        if ($ja->fetchColumn()) { continue; }

        $quandoChat = quando(7);
        $pdo->prepare("INSERT INTO loja_chats (loja_id, user_id, created_at) VALUES (?, ?, ?)")
            ->execute([$lojaId, $cli, $quandoChat]);
        $chatId = (int)$pdo->lastInsertId();
        $nChat++;

        if ($idx === 0) {
            // conversa que o agente RESPONDEU (2 trocas)
            foreach (array_rand($perguntasRespondidas, 2) as $pi) {
                [$perg, $resp] = $perguntasRespondidas[$pi];
                $t = quando(6);
                $pdo->prepare("INSERT INTO loja_chat_mensagens (chat_id, role, conteudo, created_at) VALUES (?, 'user', ?, ?)")
                    ->execute([$chatId, $perg, $t]);
                $pdo->prepare("INSERT INTO loja_chat_mensagens (chat_id, role, conteudo, created_at) VALUES (?, 'agent', ?, ?)")
                    ->execute([$chatId, $resp, date("Y-m-d H:i:s", strtotime($t) + 40)]);
                $nMsg += 2;
            }
        } else {
            // pergunta PENDENTE (só o cliente falou; o agente não soube)
            $perg = $perguntasPendentes[array_rand($perguntasPendentes)];
            $pdo->prepare("INSERT INTO loja_chat_mensagens (chat_id, role, conteudo, created_at) VALUES (?, 'user', ?, ?)")
                ->execute([$chatId, $perg, quando(4)]);
            $nPend++;
        }
    }

    // comentários em até 3 posts recentes da loja
    $posts = $pdo->prepare("SELECT id FROM loja_posts WHERE loja_id = ? ORDER BY id DESC LIMIT 3");
    $posts->execute([$lojaId]);
    foreach ($posts->fetchAll(PDO::FETCH_COLUMN) as $postId) {
        $qtd = random_int(1, 3);
        for ($c = 0; $c < $qtd; $c++) {
            $cli = (int)$pdo->query("SELECT id FROM users WHERE id <> $dono ORDER BY RAND() LIMIT 1")->fetchColumn();
            $ja = $pdo->prepare("SELECT id FROM loja_post_comments WHERE loja_post_id = ? AND user_id = ? LIMIT 1");
            $ja->execute([$postId, $cli]);
            if ($ja->fetchColumn()) { continue; }
            $pdo->prepare("INSERT INTO loja_post_comments (loja_post_id, user_id, conteudo, created_at) VALUES (?, ?, ?, ?)")
                ->execute([$postId, $cli, $comentarios[array_rand($comentarios)], quando(6)]);
            $nCom++;
        }
    }
}
linha("4. histórico: $nChat chat(s), $nMsg msg respondidas, $nPend pergunta(s) pendente(s), $nCom comentário(s)");

linha("== fim ==");
