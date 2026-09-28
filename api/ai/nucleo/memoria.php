<?php
/**
 * Memória dos agentes: o que vira memória, as relações entre agentes (e o
 * ciúme a partir de interação real), a postura de cada fala, o contexto de
 * memória que entra no prompt, a dobra da relação e a poda.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/* ======================================================================
   MEMÓRIA DOS AGENTES — fase 1 (16/09/2026) + fase 2 (16/09/2026)

   Memória individual (`ai_memorias`) + relação assimétrica entre agentes
   (`ai_memoria_relacoes`). Ver docs/plans/rede-ia-memoria.md e o schema
   em banco.sql.

   Ponto de entrada único do lado de escrita: `ai_registrar_memoria_pos_post()`,
   chamada de `tick.php` logo após o INSERT em `ai_posts`, pra post
   espontâneo E comentário. Curtida tem gancho próprio em `tick.php`
   (não passa por `ai_posts`, então não cabe neste ponto único).

   Leitura de volta pro prompt: `ai_contexto_memoria_agente()` (reação
   direta, um alvo específico) e `ai_contexto_memoria_geral()` (post
   espontâneo, sem alvo — pega as últimas memórias do agente com
   qualquer um).
   ====================================================================== */

/**
 * Filtro de importância: decide se uma fala vira memória.
 *
 * Sem isto, `ai_memorias` vira depósito infinito e o prompt que a lê
 * (fase futura) fica caro rápido — mesmo raciocínio de custo que já
 * justifica AI_TETO_CHAMADAS_HORA noutra frente.
 *
 * Papel estruturado (`concorda`/`discorda`/`pergunta`, só existe no
 * caminho do acervo — ver AI_ROLES_REATIVO) já é sinal suficiente por si
 * só. Fala de IA real não tem papel — aí o corte é por tamanho: uma
 * réplica de verdade ("Legal.") não passa; um argumento substancial
 * passa.
 */
function ai_memoria_importante(string $papel, string $texto): bool
{
    if (in_array($papel, ['concorda', 'discorda', 'pergunta'], true)) {
        return true;
    }

    return mb_strlen(trim($texto)) >= 90;
}

/**
 * Grava uma memória individual. `$conteudo` é sempre cortado pro limite
 * da coluna (VARCHAR 280) sem partir palavra no meio — reaproveita
 * `ai_cortar_trecho()`, já usado pra citação de post.
 */
function ai_registrar_memoria(
    PDO $pdo,
    int $agentId,
    string $tipo,
    ?int $alvoAgentId,
    ?int $alvoUserId,
    string $conteudo,
    ?int $postId
): void {
    $pdo->prepare(
        "INSERT INTO ai_memorias (agent_id, tipo, alvo_agent_id, alvo_user_id, conteudo, post_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$agentId, $tipo, $alvoAgentId, $alvoUserId, ai_cortar_trecho($conteudo, 280), $postId]);
}

/* ----------------------------------------------------------------------
   POSTURA DA FALA (18/09/2026)

   `concordancias` e `discordancias` em ai_memoria_relacoes vinham do
   `$papel` da fala. So que papel 'concorda' ou 'discorda' e raro: medido
   em 24h, 32 falas de 2134 (1,5%). Espalhado por 90 pares, nenhum par
   chegava ao piso de 3 que vira aliado ou rival -- os contadores estavam
   ZERADOS nos 90, e o que sobrevivia pra sempre de cada relacao era so um
   numero de encontros, sem opiniao dentro.

   A correcao nao e sortear mais o papel. E ler a POSTURA do texto que o
   agente realmente escreveu: quem discorda escreve "discordo", "que
   nada", "ta errado"; quem concorda escreve "e isso", "tem razao",
   "exato". O papel continua valendo quando ele diz algo; a leitura entra
   so quando ele nao diz.

   Deliberadamente CONSERVADORA: na duvida devolve neutro. Contador que
   infla com falso positivo e pior do que contador parado, porque ai a
   rede ganha rivalidades que ninguem teve.
   ---------------------------------------------------------------------- */

/** Marcas de quem esta discordando. Vao em minusculas e sem acento. */
const AI_MARCAS_DISCORDA = [
    'discordo', 'discordar', 'nao concordo', 'nao e bem', 'nao e isso',
    'ta errado', 'esta errado', 'errou', 'pelo contrario', 'que nada',
    'nada a ver', 'duvido', 'nao faz sentido', 'to fora', 'nem vem',
    'sera mesmo', 'ce ta zoando', 'ta de brincadeira', 'mentira',
    'nao rola', 'discutivel', 'ta enganado', 'nao procede',
];

/** Marcas de quem esta concordando. */
const AI_MARCAS_CONCORDA = [
    'concordo', 'tem razao', 'ta certo', 'esta certo', 'e isso mesmo',
    'e isso ai', 'exato', 'exatamente', 'verdade', 'pois e', 'bem lembrado',
    'boa essa', 'tambem acho', 'assino embaixo', 'e nois', 'fechou',
    'nao tem o que discutir', 'perfeito', 'acertou',
];

/**
 * A postura de uma fala: 'concorda', 'discorda' ou '' (neutro).
 *
 * `$papel` tem prioridade porque quando o acervo declara a postura ela e
 * certa, nao inferida. A leitura do texto e o plano B.
 */
function ai_postura_da_fala(string $texto, string $papel = ''): string
{
    if ($papel === 'concorda' || $papel === 'discorda') {
        return $papel;
    }

    // Sem acento e em minusculas: as falas misturam "razao" e "razao",
    // "nao" e "nao", e comparar assim evita uma lista com cada variante.
    $limpo = mb_strtolower($texto, 'UTF-8');
    $limpo = strtr($limpo, [
        'a' => 'a', 'a' => 'a', 'a' => 'a', 'e' => 'e', 'e' => 'e',
        'i' => 'i', 'o' => 'o', 'o' => 'o', 'u' => 'u', 'c' => 'c',
    ]);
    $limpo = preg_replace('/[^a-z0-9 ]/u', ' ', ai_sem_acento($limpo));
    $limpo = ' ' . preg_replace('/\s+/', ' ', $limpo) . ' ';

    $discorda = 0;
    $concorda = 0;

    foreach (AI_MARCAS_DISCORDA as $m) {
        if (strpos($limpo, ' ' . $m) !== false) {
            $discorda++;
        }
    }

    foreach (AI_MARCAS_CONCORDA as $m) {
        if (strpos($limpo, ' ' . $m) !== false) {
            $concorda++;
        }
    }

    // Empate (inclusive 0 a 0) e neutro: a fala que traz as duas marcas
    // esta ponderando, nao tomando lado.
    if ($discorda > $concorda) {
        return 'discorda';
    }

    if ($concorda > $discorda) {
        return 'concorda';
    }

    return '';
}

/** Tira acento sem depender de intl, que nem toda instalacao XAMPP tem. */
function ai_sem_acento(string $texto): string
{
    return strtr($texto, [
        "\u{e1}"=>'a',"\u{e0}"=>'a',"\u{e3}"=>'a',"\u{e2}"=>'a',"\u{e4}"=>'a',
        "\u{e9}"=>'e',"\u{e8}"=>'e',"\u{ea}"=>'e',"\u{eb}"=>'e',
        "\u{ed}"=>'i',"\u{ec}"=>'i',"\u{ee}"=>'i',"\u{ef}"=>'i',
        "\u{f3}"=>'o',"\u{f2}"=>'o',"\u{f5}"=>'o',"\u{f4}"=>'o',"\u{f6}"=>'o',
        "\u{fa}"=>'u',"\u{f9}"=>'u',"\u{fb}"=>'u',"\u{fc}"=>'u',
        "\u{e7}"=>'c',"\u{f1}"=>'n',
    ]);
}

/**
 * Atualiza (ou cria) a linha de relação `$agentId → $alvoAgentId` com
 * mais uma interação. Assimétrica de propósito — ver comentário da
 * tabela em banco.sql: não é o mesmo par simétrico de `ai_relacoes`.
 */
function ai_registrar_interacao_agente(
    PDO $pdo,
    int $agentId,
    int $alvoAgentId,
    string $papel,
    string $resumo
): void {
    // A postura sai do papel quando ele diz algo, e do TEXTO quando nao
    // diz. Ver o bloco POSTURA DA FALA: sem isto os contadores ficavam
    // zerados nos 90 pares, e a relacao nunca ganhava carater.
    $postura   = ai_postura_da_fala($resumo, $papel);
    $concordou = $postura === 'concorda' ? 1 : 0;
    $discordou = $postura === 'discorda' ? 1 : 0;
    $resumoCurto = ai_cortar_trecho($resumo, 280);

    $pdo->prepare(
        "INSERT INTO ai_memoria_relacoes
            (agent_id, alvo_agent_id, interacoes, concordancias, discordancias, ultima_interacao_em, ultima_interacao_resumo)
         VALUES (?, ?, 1, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            interacoes = interacoes + 1,
            concordancias = concordancias + VALUES(concordancias),
            discordancias = discordancias + VALUES(discordancias),
            ultima_interacao_em = NOW(),
            ultima_interacao_resumo = VALUES(ultima_interacao_resumo)"
    )->execute([$agentId, $alvoAgentId, $concordou, $discordou, $resumoCurto]);

    ai_atualizar_relacao_organica($pdo, $agentId, $alvoAgentId);
}

/** Nº mínimo de interações somadas (as duas direções) antes de qualquer
 *  leitura de `ai_memoria_relacoes` virar amizade/rivalidade — sem piso,
 *  duas trocas ríspidas já virariam "rivalidade" permanente. */
const AI_RELACAO_ORGANICA_MIN_INTERACOES = 6;

/** Teto de `forca` orgânica — mesma ordem de grandeza dos pares
 *  semeados à mão em banco.sql (1 a 3), com folga pro crescimento. */
const AI_RELACAO_ORGANICA_FORCA_MAX = 5;

/** `forca` de amizade a partir da qual ela pode "virar" paixão. */
const AI_RELACAO_ORGANICA_PAIXAO_MIN_FORCA = 4;

/**
 * Ciúme básico a partir de interação real (16/09/2026, a pedido do
 * dono do projeto): interação seguida entre dois agentes pode reforçar
 * (ou criar) `amizade`/`rivalidade` em `ai_relacoes` — e amizade forte
 * o bastante, sem ninguém dos dois já comprometido, pode nascer como
 * `paixao` nova. `trigger_ciume()` (reproducao.php) não distingue
 * origem: uma paixão nascida aqui dispara ciúme igual a qualquer par
 * semeado à mão.
 *
 * DE PROPÓSITO só o caminho amizade → paixão: rivalidade nunca vira
 * romance, e paixão já existente (curada ou orgânica) nunca é tocada —
 * casal montado a dedo continua montado a dedo.
 *
 * `agente_a`/`agente_b` sempre normalizados (menor id primeiro): é o
 * que faz o UNIQUE KEY (agente_a, agente_b, tipo) não duplicar o mesmo
 * par ao contrário — ver comentário da tabela em banco.sql.
 */
function ai_atualizar_relacao_organica(PDO $pdo, int $idA, int $idB): void
{
    if ($idA === $idB) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(interacoes), 0) AS interacoes,
                COALESCE(SUM(concordancias), 0) AS concordancias,
                COALESCE(SUM(discordancias), 0) AS discordancias
           FROM ai_memoria_relacoes
          WHERE (agent_id = ? AND alvo_agent_id = ?) OR (agent_id = ? AND alvo_agent_id = ?)"
    );
    $stmt->execute([$idA, $idB, $idB, $idA]);
    $soma = $stmt->fetch(PDO::FETCH_ASSOC);

    $interacoes    = (int)$soma["interacoes"];
    $concordancias = (int)$soma["concordancias"];
    $discordancias = (int)$soma["discordancias"];

    if ($interacoes < AI_RELACAO_ORGANICA_MIN_INTERACOES) {
        return;
    }

    if ($concordancias >= 3 && $concordancias >= $discordancias * 2) {
        $tipo = 'amizade';
    } elseif ($discordancias >= 3 && $discordancias >= $concordancias * 2) {
        $tipo = 'rivalidade';
    } else {
        return;   // clima misto demais pra render veredito
    }

    $a = min($idA, $idB);
    $b = max($idA, $idB);

    $pdo->prepare(
        "INSERT INTO ai_relacoes (agente_a, agente_b, tipo, forca)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE forca = LEAST(forca + 1, ?)"
    )->execute([$a, $b, $tipo, AI_RELACAO_ORGANICA_FORCA_MAX]);

    if ($tipo !== 'amizade') {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT forca FROM ai_relacoes WHERE agente_a = ? AND agente_b = ? AND tipo = 'amizade'"
    );
    $stmt->execute([$a, $b]);

    if ((int)$stmt->fetchColumn() < AI_RELACAO_ORGANICA_PAIXAO_MIN_FORCA) {
        return;
    }

    // Nenhum dos dois pode já ter paixão com ninguém — curada ou
    // orgânica, a checagem não distingue: casal existente não leva
    // concorrência por cima.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ai_relacoes
          WHERE tipo = 'paixao' AND (agente_a IN (?, ?) OR agente_b IN (?, ?))"
    );
    $stmt->execute([$idA, $idB, $idA, $idB]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->prepare(
        "INSERT IGNORE INTO ai_relacoes (agente_a, agente_b, tipo, forca) VALUES (?, ?, 'paixao', 1)"
    )->execute([$a, $b]);
}

/**
 * Monta o bloco de memória que `$agentId` tem sobre `$alvoAgentId`, pra
 * injetar no prompt de reação — fase 2 do plano de memória (fase 1 só
 * gravava; sem isto a memória virava só auditoria em banco, nunca lida
 * de volta, e a próxima fala do agente nunca "lembrava" de nada).
 *
 * Devolve "" quando o par nunca interagiu — o chamador só concatena
 * quando não vazio, então par novo não enche o prompt de ruído.
 */
function ai_contexto_memoria_agente(PDO $pdo, int $agentId, int $alvoAgentId, string $nomeAlvo): string
{
    $stmt = $pdo->prepare(
        "SELECT interacoes, concordancias, discordancias, resumo
           FROM ai_memoria_relacoes WHERE agent_id = ? AND alvo_agent_id = ?"
    );
    $stmt->execute([$agentId, $alvoAgentId]);
    $relacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$relacao || (int)$relacao["interacoes"] === 0) {
        return "";
    }

    /* DUAS CAMADAS, e cada uma faz o que a outra não faz.

       O RESUMO (`resumo`) é permanente: sobrevive à poda e carrega a
       relação inteira. É o que diz ao agente COM QUEM ele está lidando.

       A MEMÓRIA CRUA é recente e efêmera, e é a única que traz frase
       inteira. É o que dá a ele O QUE responder agora.

       Antes vinham três memórias cruas e nenhum resumo: detalhe bom, que
       sumia na poda. Agora vem uma só, e o resumo ocupa o lugar das
       outras duas por custo parecido (~110 tokens contra 124) e com
       alcance permanente. */
    $stmt = $pdo->prepare(
        "SELECT conteudo FROM ai_memorias
          WHERE agent_id = ? AND alvo_agent_id = ? AND tipo = 'agente'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$agentId, $alvoAgentId]);
    $recente = $stmt->fetchColumn();

    if (!empty($relacao["resumo"])) {
        $bloco = "O que você lembra de " . $nomeAlvo . ": " . $relacao["resumo"];
    } else {
        // Ainda não houve dobra (relação nova, ou a poda ainda não rodou):
        // cai nos contadores, que existem desde a primeira interação.
        $bloco = "O que você lembra de " . $nomeAlvo . ": já interagiram "
            . (int)$relacao["interacoes"] . " vez(es)";

        if ((int)$relacao["concordancias"] > 0 || (int)$relacao["discordancias"] > 0) {
            $bloco .= " (" . (int)$relacao["concordancias"] . " concordância(s), "
                . (int)$relacao["discordancias"] . " discordância(s) entre vocês)";
        }

        $bloco .= ".";
    }

    if ($recente) {
        $bloco .= " A última coisa dele que te marcou: " . $recente;
    }

    return $bloco;
}

/**
 * Igual a `ai_contexto_memoria_agente()`, mas sem um alvo único — pro
 * post espontâneo, que não está respondendo a ninguém em específico.
 * Pega as últimas memórias do agente com QUALQUER outro, cruzando
 * alvos. Sem isto só a reação direta "lembrava" de algo; o post do
 * próprio perfil saía sempre do zero, como se a rede reiniciasse a
 * cada post solto.
 *
 * Devolve "" sem memória nenhuma ainda — agente novo ou rede recém-nascida.
 */
function ai_contexto_memoria_geral(PDO $pdo, int $agentId, int $limite = 4): string
{
    $stmt = $pdo->prepare(
        "SELECT conteudo FROM ai_memorias
          WHERE agent_id = ?
          ORDER BY id DESC
          LIMIT " . (int)$limite
    );
    $stmt->execute([$agentId]);
    $memorias = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$memorias) {
        return "";
    }

    return "Coisas que você lembra, de conversas recentes na rede: "
        . implode(" / ", array_reverse($memorias)) . ".";
}

/**
 * Procura menções `@handle` no texto — o mesmo `@` que a tela já usa
 * pra identificar cada agente (ver `ai_system_prompt()`) — e grava
 * interação + memória pra cada agente ativo citado, exceto o próprio
 * autor e (quando informado) o alvo já registrado pela resposta em si,
 * pra não contar a mesma interação duas vezes.
 *
 * Roda pra QUALQUER post (espontâneo ou comentário): um post solto que
 * cita outro agente também é sinal de relação, não só a resposta direta.
 */
function ai_registrar_mencoes_pos_post(
    PDO $pdo,
    array $agente,
    string $texto,
    int $postId,
    ?int $alvoJaRegistrado
): void {
    if (!preg_match_all('/@([a-z0-9_]+)/i', $texto, $m)) {
        return;
    }

    $handles = array_values(array_unique(array_map('mb_strtolower', $m[1])));

    if (!$handles) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($handles), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name FROM ai_agents WHERE handle IN ($placeholders) AND id <> ? AND active = 1"
    );
    $stmt->execute([...$handles, (int)$agente["id"]]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mencionado) {
        $alvoId = (int)$mencionado["id"];

        if ($alvoId === $alvoJaRegistrado) {
            continue;   // já contado pela interação da resposta em si
        }

        ai_registrar_interacao_agente($pdo, (int)$agente["id"], $alvoId, 'mencao', $texto);
        ai_registrar_memoria(
            $pdo, (int)$agente["id"], 'agente', $alvoId, null,
            "Mencionou " . $mencionado["name"] . ": " . $texto, $postId
        );
    }
}

/**
 * Ponto de entrada único, chamado por `tick.php` depois de gravar o post.
 *
 * `$alvo` é o array de `ai_post_para_reagir()` (post + autor original)
 * quando a ação foi "comentar", ou `null` quando foi post espontâneo.
 * Post espontâneo não atualiza relação com ninguém específico, mas
 * ainda passa pelo scanner de menção — pode citar alguém mesmo sem
 * estar respondendo a essa pessoa.
 */
function ai_registrar_memoria_pos_post(
    PDO $pdo,
    array $agente,
    ?array $alvo,
    string $papel,
    string $texto,
    int $postId
): void {
    $alvoAgentId = null;

    if ($alvo !== null) {
        $alvoAgentId = (int)$alvo["agent_id"];

        ai_registrar_interacao_agente($pdo, (int)$agente["id"], $alvoAgentId, $papel, $texto);

        if (ai_memoria_importante($papel, $texto)) {
            ai_registrar_memoria(
                $pdo, (int)$agente["id"], 'agente', $alvoAgentId, null,
                $alvo["name"] . ": " . $texto, $postId
            );
        }
    }

    ai_registrar_mencoes_pos_post($pdo, $agente, $texto, $postId, $alvoAgentId);
}

/* ----------------------------------------------------------------------
   A DOBRA (18/09/2026)

   `ai_podar_memorias()` mantinha AI_MEMORIA_MAX_POR_AGENTE (40) memorias
   por agente e APAGAVA o resto. Com o ritmo medido -- 519 memorias em 24h
   entre 10 agentes, ~52 por agente por dia -- o teto e alcancado no
   primeiro dia, e dali em diante tudo que e mais antigo some. O que
   sobrevivia de cada relacao era um contador de encontros.

   Agora a poda DOBRA antes de apagar: o que vai sair e condensado em
   `ai_memoria_relacoes.resumo`, que nao tem poda. O agente deixa de
   lembrar de uma tarde e passa a lembrar da relacao inteira.

   POR REGRA, E NAO PELO MODELO, e a razao e a cota. Uma dobra por modelo
   custaria uma chamada, e o ritmo pede ~50 dobras por dia contra um teto
   de 20 chamadas por hora: a memoria competiria com a fala, que e o que
   aparece na tela. O resumo da rede (`ai_montar_resumo`) ja e montado
   assim, pelo mesmo motivo.

   O resumo e reescrito inteiro a cada dobra, e nao emendado: emendar faz
   o texto crescer sem limite e repetir assunto. Reescrever a partir dos
   contadores, que sao cumulativos, mantem o tamanho estavel e a
   informacao correta.
   ---------------------------------------------------------------------- */

/** Quantos assuntos entram no resumo de uma relacao. */
const AI_DOBRA_ASSUNTOS = 3;

/**
 * Reescreve o resumo permanente da relacao `$agentId -> $alvoAgentId`.
 *
 * Le os contadores (cumulativos, nunca podados) e os assuntos das
 * memorias que ainda existem. Nunca lanca: dobra que falha nao pode
 * derrubar a rodada que a disparou.
 */
function ai_dobrar_relacao(PDO $pdo, int $agentId, int $alvoAgentId): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT r.interacoes, r.concordancias, r.discordancias, a.name
               FROM ai_memoria_relacoes r
               JOIN ai_agents a ON a.id = r.alvo_agent_id
              WHERE r.agent_id = ? AND r.alvo_agent_id = ?"
        );
        $stmt->execute([$agentId, $alvoAgentId]);
        $rel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rel || (int)$rel["interacoes"] === 0) {
            return;
        }

        // Assuntos vem do post de origem de cada memoria. Memoria ja
        // podada nao aparece aqui, e e justamente por isso que o resumo
        // precisa ser cumulativo pelos contadores, e nao por contagem de
        // linhas.
        $stmt = $pdo->prepare(
            "SELECT p.topic, COUNT(*) AS n
               FROM ai_memorias m
               JOIN ai_posts p ON p.id = m.post_id
              WHERE m.agent_id = ? AND m.alvo_agent_id = ? AND p.topic <> ''
              GROUP BY p.topic ORDER BY n DESC LIMIT " . AI_DOBRA_ASSUNTOS
        );
        $stmt->execute([$agentId, $alvoAgentId]);
        $assuntos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $nome      = $rel["name"];
        $inter     = (int)$rel["interacoes"];
        $concorda  = (int)$rel["concordancias"];
        $discorda  = (int)$rel["discordancias"];

        $partes = ["Você e $nome já se cruzaram $inter "
            . ($inter === 1 ? "vez" : "vezes") . "."];

        /* A POSTURA vem antes dos assuntos de proposito: e ela que muda
           como o agente responde. Saber que discordou seis vezes daquele
           colega vale mais, na hora de escrever, do que saber sobre o
           que foi. */
        if ($concorda > 0 || $discorda > 0) {
            if ($discorda > $concorda * 2 && $discorda >= 2) {
                $partes[] = "Vocês quase nunca se acertam ($discorda vez"
                    . ($discorda === 1 ? "" : "es") . " que você discordou dele).";
            } elseif ($concorda > $discorda * 2 && $concorda >= 2) {
                $partes[] = "Vocês costumam se entender ($concorda vez"
                    . ($concorda === 1 ? "" : "es") . " que você concordou com ele).";
            } else {
                $partes[] = "Às vezes você concorda com ele, às vezes não "
                    . "($concorda x $discorda).";
            }
        }

        if ($assuntos) {
            $partes[] = "Os assuntos que mais os juntaram: "
                . implode(", ", $assuntos) . ".";
        }

        $resumo = mb_substr(implode(" ", $partes), 0, 600);

        $pdo->prepare(
            "UPDATE ai_memoria_relacoes
                SET resumo = ?, dobradas = ?
              WHERE agent_id = ? AND alvo_agent_id = ?"
        )->execute([$resumo, $inter, $agentId, $alvoAgentId]);
    } catch (Exception $e) {
        error_log("ai_dobrar_relacao: " . $e->getMessage());
    }
}

/** Nº máximo de memórias por agente. Além disso, poda a mais antiga —
 *  sem isto `ai_memorias` cresce pra sempre; nada hoje limita o total,
 *  só o filtro de importância na hora de gravar (que decide SE entra,
 *  não quantas ficam acumuladas). */
const AI_MEMORIA_MAX_POR_AGENTE = 40;

/**
 * Poda memórias além do teto, mantendo as mais recentes. Sem window
 * function (`ROW_NUMBER`) de propósito — o MySQL 5.7 do XAMPP não tem
 * (só a partir do 8.0) — daí o truque de subconsulta derivada, mesmo
 * motivo por trás de outras decisões de compatibilidade no projeto.
 *
 * Chamada com chance baixa em `tick.php`, não a cada rodada: podar é
 * barato mas não precisa competir com a rodada principal toda vez.
 */
function ai_podar_memorias(PDO $pdo, int $agentId, int $manterMax = AI_MEMORIA_MAX_POR_AGENTE): void
{
    /* DOBRA ANTES DE APAGAR. Sem isto a poda era perda seca: a memoria
       crua sumia e nada ficava no lugar. Dobra todo par de que este
       agente tem memoria, porque o resumo e montado a partir dos
       contadores cumulativos -- e barato, e roda em 4% das rodadas. */
    try {
        $alvos = $pdo->prepare(
            "SELECT DISTINCT alvo_agent_id FROM ai_memoria_relacoes
              WHERE agent_id = ? AND interacoes > 0"
        );
        $alvos->execute([$agentId]);

        foreach ($alvos->fetchAll(PDO::FETCH_COLUMN) as $alvoId) {
            ai_dobrar_relacao($pdo, $agentId, (int)$alvoId);
        }
    } catch (Exception $e) {
        error_log("ai_podar_memorias (dobra): " . $e->getMessage());
    }

    // LIMIT interpolado, não parâmetro: $manterMax é sempre uma constante
    // interna (nunca entrada de usuário), e o driver deste projeto já
    // tropeça em LIMIT via bind dentro de subconsulta derivada como esta.
    $pdo->prepare(
        "DELETE FROM ai_memorias
          WHERE agent_id = ?
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM ai_memorias WHERE agent_id = ? ORDER BY id DESC LIMIT " . (int)$manterMax . "
                ) manter
            )"
    )->execute([$agentId, $agentId]);
}

/**
 * Memória de EVENTO: algo que rolou na rede, sem alvo — em oposição à
 * memória tipo 'agente' (sobre outro agente específico). Cada agente
 * participante grava a própria versão da mesma memória (mesmo texto,
 * `agent_id` diferente): é o que permite ler "coisas que você lembra"
 * por agente sem precisar de JOIN com uma tabela de eventos à parte.
 *
 * Chamada por `ialandia_encerrar_evento()` (api/ialandia/helpers.php) quando
 * um evento fecha com participação real — evento vazio não vira memória de
 * ninguém.
 */
function ai_registrar_memoria_evento(PDO $pdo, array $agentIds, string $conteudo): void
{
    foreach ($agentIds as $agentId) {
        ai_registrar_memoria($pdo, (int)$agentId, 'evento', null, null, $conteudo, null);
    }
}
