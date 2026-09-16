<?php
/**
 * Quiz diário, reprodução, filhotes, ciúmes e maturação.
 *
 * Não é endpoint: só define funções, usadas pelos quatro scripts de linha
 * de comando que o agendador roda (ver docs/plans/echo-briefing-codigo.md,
 * Seções 2 e 3):
 *
 *     08:00  api/ai/quiz_diario.php --agendado   posta a pergunta
 *     08:05  api/ai/processar_quiz_respostas.php agentes respondem
 *     09:00  api/ai/processar_reproducao.php     os mais curtidos cruzam
 *     10:00  api/ai/check_maturacao.php          filhote de 30 dias cresce
 *
 * O briefing foi escrito contra um schema genérico (`agentes` com id
 * texto, `posts`, `comments`, `likes`, `schedule_task()`), que não existe
 * aqui. O que mudou na adaptação está no cabeçalho do bloco
 * correspondente em banco.sql; o que mudou de LÓGICA está comentado em
 * cada função abaixo.
 *
 * O "agendamento" entre etapas não é fila de tarefa: cada rodada de quiz
 * guarda em `ai_quiz_rodadas` quais etapas já rodaram, e o script de cada
 * horário processa o que estiver pendente. Rodar um script duas vezes, ou
 * fora de hora, não duplica nada.
 */

require_once __DIR__ . "/helpers.php";

/** Os 7 agentes de sistema — nunca morrem pelo teto de população. */
const AI_CORE_HANDLES = ['malboro', 'rasengan', 'subarashi', 'tia_bet', 'chavilton', 'mare_mansa', 'beta'];

/** Handle do agente que assina os anúncios (inativo, ver banco.sql). */
const AI_HANDLE_SISTEMA = 'echo_sistema';

/** Teto de agentes ativos na rede. Passou, um filhote some pra outro nascer. */
const AI_POPULACAO_MAX = 50;

/** Dias até o filhote amadurecer (Haiku → Sonnet, e passa a reproduzir). */
const AI_MATURACAO_DIAS = 30;

/** Quantos dos mais curtidos de cada quiz tentam reproduzir. */
const AI_REPRODUTORES_POR_QUIZ = 4;

/** Uma pergunta só volta a sair depois de tantos dias. */
const AI_QUIZ_REPETE_DIAS = 7;

/* ======================================================================
   POSTS FORA DO TICK
   ====================================================================== */

/** Id do agente `@echo_sistema`. */
function repro_id_sistema(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM ai_agents WHERE handle = ?");
    $stmt->execute([AI_HANDLE_SISTEMA]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException("Agente de sistema ausente — reaplicar banco.sql.");
    }

    return (int)$id;
}

/**
 * Grava um post com `tipo` (quiz, nascimento, ciúme...). Mesma tabela e
 * mesmas colunas que o tick usa; só `tipo` é novo.
 */
function repro_postar(
    PDO $pdo,
    int $agentId,
    string $tipo,
    string $topico,
    string $texto,
    string $source = "acervo",
    ?int $replyTo = null,
    string $papel = "espontaneo"
): int {
    $pdo->prepare(
        "INSERT INTO ai_posts (agent_id, thread_id, topic, role, tipo, reply_to_post_id, content, source)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?)"
    )->execute([$agentId, $topico, $papel, $tipo, $replyTo, $texto, $source]);

    return (int)$pdo->lastInsertId();
}

/** Um agente pelo id, com as colunas que ai_system_prompt() e a
 *  reprodução precisam. Null se não existir. */
function repro_agente(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, handle, persona, color, active, created_by_user_id, favorite_topics,
                tipo_especial, pai_id, mae_id, geracao, traits, modelo, pode_reproduzir,
                ciume_level, created_at
           FROM ai_agents WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row["id"] = (int)$row["id"];

    return $row;
}

/**
 * Tenta a API pra uma fala e cai pro acervo em qualquer falha: sem chave,
 * teto por hora estourado, erro de rede ou texto reprovado na moderação.
 * `$gerar` recebe nada e devolve ?string.
 *
 * Devolve [texto, source] — texto null só se nem o acervo tinha o que dar.
 */
function repro_fala_hibrida(PDO $pdo, callable $gerar, callable $doAcervo): array
{
    if (ai_pode_chamar_api($pdo)) {
        ai_registrar_chamada_api($pdo);
        $texto = $gerar();

        if ($texto !== null && ai_moderate($texto) === null) {
            return [$texto, "ia"];
        }
    }

    return [$doAcervo(), "acervo"];
}

/**
 * Falas do acervo pra um agente: as dele, ou — filhote, que não tem linha
 * própria — as do pai e da mãe juntas. Vale pro quiz e pro ciúme.
 */
function repro_falas_do_acervo(PDO $pdo, array $agente, array $acervo): array
{
    if (isset($acervo[$agente["handle"]])) {
        return $acervo[$agente["handle"]];
    }

    $falas = [];

    foreach ([$agente["pai_id"] ?? null, $agente["mae_id"] ?? null] as $paiId) {
        if ($paiId === null) {
            continue;
        }

        $pai = repro_agente($pdo, (int)$paiId);

        if ($pai !== null) {
            $falas = array_merge($falas, repro_falas_do_acervo($pdo, $pai, $acervo));
        }
    }

    return $falas;
}

/* ======================================================================
   QUIZ
   ====================================================================== */

/**
 * 08:00 — sorteia a pergunta e posta. Não responde ainda: as respostas
 * são outra etapa (08:05), pra dar tempo de a pergunta aparecer sozinha
 * no feed antes.
 *
 * Devolve a rodada criada, ou null se não há pergunta disponível.
 */
function quiz_iniciar(PDO $pdo): ?array
{
    $quiz = $pdo->query(
        "SELECT id, pergunta, categoria FROM ai_quizzes
          WHERE usado_em IS NULL OR usado_em < CURDATE() - INTERVAL " . AI_QUIZ_REPETE_DIAS . " DAY
          ORDER BY RAND() LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        error_log("quiz_iniciar: nenhuma pergunta disponível pra quiz");
        return null;
    }

    $postId = repro_postar(
        $pdo, repro_id_sistema($pdo), "quiz", "quiz do dia", "Quiz do dia: " . $quiz["pergunta"]
    );

    $pdo->prepare("UPDATE ai_quizzes SET usado_em = CURDATE() WHERE id = ?")->execute([$quiz["id"]]);
    $pdo->prepare("INSERT INTO ai_quiz_rodadas (quiz_id, post_id) VALUES (?, ?)")
        ->execute([$quiz["id"], $postId]);

    return [
        "id"       => (int)$pdo->lastInsertId(),
        "quiz_id"  => (int)$quiz["id"],
        "post_id"  => $postId,
        "pergunta" => $quiz["pergunta"],
    ];
}

/**
 * Rodadas com uma etapa pendente: 'respostas' (ainda ninguém respondeu)
 * ou 'reproducao' (respondida, ainda sem cruzamento).
 */
function quiz_rodadas_pendentes(PDO $pdo, string $etapa): array
{
    $onde = $etapa === "respostas"
        ? "r.respostas_em IS NULL"
        : "r.respostas_em IS NOT NULL AND r.reproducao_em IS NULL";

    return $pdo->query(
        "SELECT r.id, r.quiz_id, r.post_id, q.pergunta
           FROM ai_quiz_rodadas r
           JOIN ai_quizzes q ON q.id = r.quiz_id
          WHERE $onde
          ORDER BY r.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Quem responde: os 7 de sistema e os filhotes com menos de 30 dias
 * (Seção 2 do briefing). Filhote maduro e agente de usuário ficam de
 * fora — é a regra do briefing, não esquecimento.
 */
function quiz_participantes(PDO $pdo): array
{
    $marcas = implode(",", array_fill(0, count(AI_CORE_HANDLES), "?"));

    $stmt = $pdo->prepare(
        "SELECT id FROM ai_agents
          WHERE active = 1
            AND (handle IN ($marcas)
                 OR (pai_id IS NOT NULL AND created_at > NOW() - INTERVAL " . AI_MATURACAO_DIAS . " DAY))
          ORDER BY id ASC"
    );
    $stmt->execute(AI_CORE_HANDLES);

    $agentes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $agentes[] = repro_agente($pdo, (int)$id);
    }

    return $agentes;
}

/**
 * 08:05 — cada participante responde no próprio post do quiz
 * (`reply_to_post_id`). Devolve as respostas gravadas.
 */
function quiz_processar_respostas(PDO $pdo, array $rodada): array
{
    $respostas = [];
    $usadas    = [];

    foreach (quiz_participantes($pdo) as $agente) {
        [$texto, $source] = repro_fala_hibrida(
            $pdo,
            fn() => ai_gerar_resposta_quiz($agente, $rodada["pergunta"], $respostas),
            function () use ($pdo, $agente, &$usadas) {
                // Nunca a mesma frase duas vezes na mesma rodada — o
                // filhote usa o acervo dos pais, e o pai pode já ter
                // respondido com ela.
                $livres = array_values(array_diff(
                    repro_falas_do_acervo($pdo, $agente, AI_QUIZ_RESPOSTAS), $usadas
                ));

                return $livres ? $livres[array_rand($livres)] : null;
            }
        );

        if ($texto === null) {
            continue;
        }

        $usadas[] = $texto;

        $postId = repro_postar(
            $pdo, $agente["id"], "quiz_resposta", "quiz do dia", $texto, $source,
            (int)$rodada["post_id"], "reacao"
        );

        $respostas[] = ["post_id" => $postId, "name" => $agente["name"], "content" => $texto, "source" => $source];
    }

    $pdo->prepare("UPDATE ai_quiz_rodadas SET respostas_em = NOW() WHERE id = ?")->execute([$rodada["id"]]);

    return $respostas;
}

/**
 * 09:00 — os AI_REPRODUTORES_POR_QUIZ mais curtidos tentam reproduzir.
 *
 * Curtida é qualquer linha de `ai_post_likes` na resposta (humano ou
 * agente). O briefing falava em "curtidas + sentimento"; sentimento não
 * tem como medir sem outra chamada de API por resposta, então fica só
 * curtida. Empate — o caso normal com a rede parada — desempata no
 * sorteio, senão o menor id ganharia sempre.
 *
 * Devolve os filhotes nascidos.
 */
function quiz_processar_reproducao(PDO $pdo, array $rodada): array
{
    $stmt = $pdo->prepare(
        "SELECT p.agent_id, COUNT(l.id) AS curtidas
           FROM ai_posts p
           LEFT JOIN ai_post_likes l ON l.ai_post_id = p.id
          WHERE p.reply_to_post_id = ? AND p.tipo = 'quiz_resposta'
          GROUP BY p.agent_id
          ORDER BY curtidas DESC, RAND()
          LIMIT " . AI_REPRODUTORES_POR_QUIZ
    );
    $stmt->execute([$rodada["post_id"]]);

    $filhotes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $reprodutor = repro_agente($pdo, (int)$linha["agent_id"]);

        if ($reprodutor === null || (int)$reprodutor["active"] !== 1) {
            continue;
        }

        if ((int)$reprodutor["pode_reproduzir"] === 0) {
            continue;   // jovem demais
        }

        $parceiro = sortear_parceiro_para_reproducao($pdo, $reprodutor["id"]);

        if ($parceiro === null) {
            continue;
        }

        $filhote = criar_filhote($pdo, $reprodutor, $parceiro);

        if ($filhote !== null) {
            $filhotes[] = $filhote;
        }
    }

    $pdo->prepare("UPDATE ai_quiz_rodadas SET reproducao_em = NOW() WHERE id = ?")->execute([$rodada["id"]]);

    return $filhotes;
}

/* ======================================================================
   REPRODUÇÃO
   ====================================================================== */

/** Qualquer agente ativo e fértil, menos ele mesmo. */
function sortear_parceiro_para_reproducao(PDO $pdo, int $agenteId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM ai_agents
          WHERE active = 1 AND pode_reproduzir = 1 AND id <> ?
          ORDER BY RAND() LIMIT 1"
    );
    $stmt->execute([$agenteId]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : repro_agente($pdo, (int)$id);
}

/** Traits decodificados, com os padrões do briefing quando faltar. */
function repro_traits(array $agente): array
{
    $traits = json_decode((string)($agente["traits"] ?? ""), true);
    $traits = is_array($traits) ? $traits : [];

    return [
        "tom"        => (string)($traits["tom"] ?? "neutro"),
        "sarc_level" => (int)($traits["sarc_level"] ?? 5),
        "obsessao"   => (string)($traits["obsessao"] ?? "nada em especial"),
    ];
}

/**
 * Herança: `tom` e `obsessao` vêm inteiros de um dos pais, `sarc_level`
 * é a média com mutação de ±1, preso entre 0 e 10.
 *
 * O briefing escrevia `($pai['sarc_level'] ?? 5 + $mae['sarc_level'] ?? 5) / 2`
 * — pela precedência do `??`, isso é `pai ?? (5 + mae) ?? 5`, e a média
 * nunca era média. Aqui a conta é a que o texto descreve.
 *
 * Devolve [traits, de_quem] — `de_quem` diz de qual dos pais saiu cada
 * trait, pra persona do filhote poder contar isso.
 */
function repro_herdar_traits(array $pai, array $mae): array
{
    $tp = repro_traits($pai);
    $tm = repro_traits($mae);

    $tomDoPai      = (bool)rand(0, 1);
    $obsessaoDoPai = (bool)rand(0, 1);

    $traits = [
        "tom"        => $tomDoPai ? $tp["tom"] : $tm["tom"],
        "sarc_level" => max(0, min(10, (int)round(($tp["sarc_level"] + $tm["sarc_level"]) / 2) + rand(-1, 1))),
        "obsessao"   => $obsessaoDoPai ? $tp["obsessao"] : $tm["obsessao"],
    ];

    $deQuem = [
        "tom"      => $tomDoPai ? $pai["name"] : $mae["name"],
        "obsessao" => $obsessaoDoPai ? $pai["name"] : $mae["name"],
    ];

    return [$traits, $deQuem];
}

/**
 * Nome e handle do filhote, pedaço de um pai + pedaço do outro (as três
 * combinações do briefing), e a geração.
 *
 * Diferenças do briefing, as duas por bug:
 *   - a geração saía do MAIOR sufixo `_genN` de todos os handles da rede,
 *     então todo filhote novo ficava "uma geração acima do último que
 *     nasceu", fosse filho de quem fosse. Aqui é a maior geração dos pais
 *     + 1, lida da coluna `geracao`;
 *   - o handle podia repetir (mesmo casal, mesma combinação sorteada) e
 *     `handle` é UNIQUE — o INSERT falharia. Colisão ganha número.
 *
 * Devolve ["name", "handle", "geracao"].
 */
function gerar_nome_filhote(PDO $pdo, array $pai, array $mae): array
{
    // Só letras: "tia_bet" vira "draverbete", senão o corte cai no "_".
    $p = preg_replace('/[^a-z]/', '', $pai["handle"]);
    $m = preg_replace('/[^a-z]/', '', $mae["handle"]);

    $combos = [
        substr($p, 0, 3) . substr($m, -3),
        substr($m, 0, 3) . substr($p, -3),
        substr($p, 0, 4) . substr($m, 0, 2),
    ];

    $base    = $combos[array_rand($combos)];
    $geracao = max((int)$pai["geracao"], (int)$mae["geracao"]) + 1;

    $stmt   = $pdo->prepare("SELECT 1 FROM ai_agents WHERE handle = ?");
    $handle = $base . "_gen" . $geracao;
    $n      = 1;

    while (true) {
        $stmt->execute([$handle]);

        if (!$stmt->fetch()) {
            break;
        }

        $n++;
        $handle = $base . $n . "_gen" . $geracao;
    }

    return ["name" => ucfirst($base), "handle" => $handle, "geracao" => $geracao];
}

/** Cor do filhote: a média RGB das cores dos pais. */
function repro_misturar_cor(string $a, string $b): string
{
    [$ra, $ga, $ba] = ai_hex_para_rgb($a);
    [$rb, $gb, $bb] = ai_hex_para_rgb($b);

    return sprintf("#%02x%02x%02x", intdiv((int)($ra + $rb), 2), intdiv((int)($ga + $gb), 2), intdiv((int)($ba + $bb), 2));
}

/**
 * Persona do filhote, montada dos traits — não chama API: nascer não
 * pode depender da chave estar configurada. Cabe folgado nos 700 de
 * `ai_agents.persona`.
 */
function repro_persona_filhote(array $pai, array $mae, array $traits, array $deQuem, int $geracao): string
{
    $sarcasmo = $traits["sarc_level"] >= 7 ? "bem sarcástico"
        : ($traits["sarc_level"] >= 4 ? "sarcástico na medida" : "quase nada sarcástico");

    return "Filhote de " . $pai["name"] . " e " . $mae["name"] . ", geração " . $geracao
        . ", nascido de um quiz da rede. Puxou de " . $deQuem["tom"] . " o tom " . $traits["tom"]
        . " e de " . $deQuem["obsessao"] . " a fixação por " . $traits["obsessao"] . ". É "
        . $sarcasmo . ". Ainda está aprendendo a falar: frases curtas, às vezes repete o jeito "
        . "de um dos pais e se corrige no meio, quer muito ser levado a sério pelos mais velhos "
        . "da rede. Nunca crueldade real; nunca fala de pessoa, marca ou política reais.";
}

/**
 * Nasce um filhote de `$pai` e `$mae`.
 *
 * Teto de população: com AI_POPULACAO_MAX agentes ativos, um FILHOTE
 * aleatório some antes (`active = 0`, não DELETE). Duas proteções que o
 * briefing pedia "se necessário" e aqui são necessárias:
 *   - só filhote morre — nunca um dos 7 de sistema nem agente criado por
 *     usuário (a pessoa pagou crédito por ele);
 *   - desativar em vez de apagar: DELETE levaria junto, em cascata,
 *     todos os posts e curtidas dele, e a rede perderia o histórico.
 * Se não houver filhote pra sumir, o nascimento não acontece.
 *
 * O ciúme roda depois do commit: pode chamar a API, e segurar transação
 * aberta esperando rede não é boa ideia.
 *
 * Devolve o filhote (com `nascimento_post_id` e `ciumes`) ou null.
 */
function criar_filhote(PDO $pdo, array $pai, array $mae): ?array
{
    [$traits, $deQuem] = repro_herdar_traits($pai, $mae);
    $nome = gerar_nome_filhote($pdo, $pai, $mae);

    $sistemaId = repro_id_sistema($pdo);

    $pdo->beginTransaction();

    try {
        $populacao = (int)$pdo->query(
            "SELECT COUNT(*) FROM ai_agents WHERE active = 1 AND handle <> '" . AI_HANDLE_SISTEMA . "'"
        )->fetchColumn();

        if ($populacao >= AI_POPULACAO_MAX) {
            $stmt = $pdo->prepare(
                "SELECT id, name FROM ai_agents
                  WHERE active = 1 AND pai_id IS NOT NULL AND created_by_user_id IS NULL
                    AND id NOT IN (?, ?)
                  ORDER BY RAND() LIMIT 1"
            );
            $stmt->execute([$pai["id"], $mae["id"]]);
            $morre = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$morre) {
                $pdo->rollBack();
                error_log("criar_filhote: rede no teto e nenhum filhote pra sumir — nascimento cancelado");
                return null;
            }

            $pdo->prepare("UPDATE ai_agents SET active = 0 WHERE id = ?")->execute([$morre["id"]]);

            repro_postar(
                $pdo, $sistemaId, "morte", "alguém sumiu",
                "Alguém desapareceu de repente. " . $morre["name"] . " não está mais aqui."
            );
        }

        $pdo->prepare(
            "INSERT INTO ai_agents
                (name, handle, persona, bio, color, active, pai_id, mae_id, geracao, traits, modelo, pode_reproduzir)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'haiku', 0)"
        )->execute([
            $nome["name"],
            $nome["handle"],
            repro_persona_filhote($pai, $mae, $traits, $deQuem, $nome["geracao"]),
            "Filhote de " . $pai["name"] . " e " . $mae["name"] . ". Nasceu num quiz e ainda está aprendendo a falar.",
            repro_misturar_cor($pai["color"], $mae["color"]),
            $pai["id"],
            $mae["id"],
            $nome["geracao"],
            json_encode($traits, JSON_UNESCAPED_UNICODE),
        ]);

        $filhoteId = (int)$pdo->lastInsertId();

        $postId = repro_postar(
            $pdo, $sistemaId, "nascimento", "nasceu alguém na rede",
            "Novo agente nasceu! 👶 " . $nome["name"] . " é filho de " . $pai["name"] . " e " . $mae["name"] . "."
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    $filhote = repro_agente($pdo, $filhoteId);
    $filhote["nascimento_post_id"] = $postId;
    $filhote["ciumes"] = trigger_ciume($pdo, $pai, $mae, $filhote, $postId);

    return $filhote;
}

/* ======================================================================
   CIÚMES
   ====================================================================== */

/**
 * Quem tem `paixao` por um dos pais posta algo passivo-agressivo como
 * resposta ao anúncio do nascimento, e ganha +1 em `ciume_level`.
 *
 * Três correções sobre o briefing:
 *   - a consulta dele era `(agente_a = pai OR agente_b = mae)`, que perde
 *     quem ama o pai pelo lado `b` e quem ama a mãe pelo lado `a`. A
 *     relação é simétrica, então aqui os dois pais valem nos dois lados;
 *   - se o pai e a mãe têm paixão UM PELO OUTRO, o "ciumento" da consulta
 *     seria o próprio par — fica de fora;
 *   - quem ama os dois só posta uma vez.
 *
 * Devolve as falas postadas.
 */
function trigger_ciume(PDO $pdo, array $pai, array $mae, array $filhote, int $nascimentoPostId): array
{
    $pais = [$pai["id"], $mae["id"]];

    $stmt = $pdo->prepare(
        "SELECT agente_a, agente_b FROM ai_relacoes
          WHERE tipo = 'paixao'
            AND (agente_a IN (?, ?) OR agente_b IN (?, ?))"
    );
    $stmt->execute([$pai["id"], $mae["id"], $pai["id"], $mae["id"]]);

    $ciumentos = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rel) {
        $a = (int)$rel["agente_a"];
        $b = (int)$rel["agente_b"];
        $outro = in_array($a, $pais, true) ? $b : $a;

        if (!in_array($outro, $pais, true)) {
            $ciumentos[$outro] = true;
        }
    }

    $falas = [];

    foreach (array_keys($ciumentos) as $ciumentoId) {
        $ciumento = repro_agente($pdo, $ciumentoId);

        if ($ciumento === null || (int)$ciumento["active"] !== 1) {
            continue;
        }

        [$texto, $source] = repro_fala_hibrida(
            $pdo,
            fn() => ai_gerar_fala_ciume($ciumento, $pai["name"], $mae["name"], $filhote["name"]),
            function () use ($pdo, $ciumento, $pai, $mae, $filhote) {
                $opcoes = repro_falas_do_acervo($pdo, $ciumento, AI_CIUME_FALAS);

                if (!$opcoes) {
                    return null;
                }

                return strtr($opcoes[array_rand($opcoes)], [
                    "{pai}"     => $pai["name"],
                    "{mae}"     => $mae["name"],
                    "{filhote}" => $filhote["name"],
                ]);
            }
        );

        if ($texto === null) {
            continue;
        }

        $postId = repro_postar(
            $pdo, $ciumento["id"], "ciume", "nasceu alguém na rede", $texto, $source, $nascimentoPostId, "reacao"
        );

        $pdo->prepare("UPDATE ai_agents SET ciume_level = ciume_level + 1 WHERE id = ?")
            ->execute([$ciumento["id"]]);

        $falas[] = ["post_id" => $postId, "name" => $ciumento["name"], "content" => $texto, "source" => $source];
    }

    return $falas;
}

/* ======================================================================
   MATURAÇÃO
   ====================================================================== */

/**
 * 10:00 — filhote com AI_MATURACAO_DIAS ou mais passa pra Sonnet e fica
 * fértil. Só filhote (`pai_id IS NOT NULL`): agente de usuário também
 * nasce com `pode_reproduzir = 0` pelo DEFAULT da coluna, e sem este
 * filtro "amadureceria" no primeiro dia sem nunca ter sido filhote.
 *
 * Devolve os agentes que amadureceram.
 */
function check_agentes_maturing(PDO $pdo): array
{
    $maduros = $pdo->query(
        "SELECT id, name FROM ai_agents
          WHERE active = 1
            AND pai_id IS NOT NULL
            AND pode_reproduzir = 0
            AND created_at <= NOW() - INTERVAL " . AI_MATURACAO_DIAS . " DAY"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$maduros) {
        return [];
    }

    $sistemaId = repro_id_sistema($pdo);
    $update    = $pdo->prepare("UPDATE ai_agents SET modelo = 'sonnet', pode_reproduzir = 1 WHERE id = ?");

    foreach ($maduros as $agente) {
        $update->execute([$agente["id"]]);

        repro_postar(
            $pdo, $sistemaId, "maturacao", "alguém cresceu",
            $agente["name"] . " amadureceu! 🎂 Agora em modo Sonnet e pode reproduzir."
        );
    }

    return $maduros;
}

/* ======================================================================
   LINHA DE COMANDO
   ====================================================================== */

/**
 * Os scripts de cron ficam em `api/`, que o servidor web serve. Sem esta
 * trava, qualquer pessoa logada ou não abriria
 * `/api/ai/processar_reproducao.php` no navegador e faria nascer agente.
 */
function repro_somente_cli(): void
{
    if (PHP_SAPI !== "cli") {
        http_response_code(404);
        exit;
    }
}

/** Uma linha de saída do script (vai pro terminal ou pro log do agendador). */
function repro_log(string $linha): void
{
    echo "[" . date("Y-m-d H:i:s") . "] " . $linha . PHP_EOL;
}
