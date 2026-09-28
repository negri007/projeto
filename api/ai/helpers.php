<?php
/**
 * Helpers da rede de agentes.
 *
 * Não é um endpoint: só define funções usadas por `tick.php` e
 * `feed.php`. Ver docs/plans/rede-ia-agentes.md e o adendo do motor
 * híbrido.
 */

require_once __DIR__ . "/corpus.php";

/* Os helpers da rede estao divididos por assunto em api/ai/nucleo/ (ver o
   plano em ajustes.md). Este arquivo so os carrega, na ordem abaixo, e
   continua sendo o unico que os endpoints incluem: nenhum require de fora
   mudou. O corpus.php vem antes: acervo, assuntos, reconhecimento e
   geracao usam as constantes dele. */
require_once __DIR__ . "/nucleo/formato.php";
require_once __DIR__ . "/nucleo/creditos.php";
require_once __DIR__ . "/nucleo/moderacao.php";
require_once __DIR__ . "/nucleo/prompt.php";
require_once __DIR__ . "/nucleo/config.php";
require_once __DIR__ . "/nucleo/estado.php";
require_once __DIR__ . "/nucleo/assuntos.php";
require_once __DIR__ . "/nucleo/acervo.php";
require_once __DIR__ . "/nucleo/fotos.php";
require_once __DIR__ . "/nucleo/cliente_api.php";
require_once __DIR__ . "/nucleo/memoria.php";
require_once __DIR__ . "/nucleo/criacao_agente.php";
require_once __DIR__ . "/nucleo/geracao.php";

/** A cada quantas falas o resumo de memória é reescrito. */
const AI_SUMMARY_EVERY = 20;

/**
 * Chance de uma rodada ser gerada pela API de verdade, em vez do acervo.
 * Vale só no modo "hibrido" — ver `ai_chance_real()`.
 *
 * Subido de 0.15 para 0.6 em 15/09/2026 (docs/plans/assuntos-e-api-echo,
 * Parte 1 e Parte 3.5): com geração em LOTE (AI_QUEUE_TAMANHO_LOTE +
 * AI_TETO_CHAMADAS_HORA abaixo), o custo por post cai pela metade e os
 * US$5 de crédito continuam dando milhares de posts — o acervo vira
 * fallback de verdade (API fora do ar/sem crédito), não a fonte
 * principal. Acompanhar consumo real no Console da Anthropic na primeira
 * semana e ajustar — 0.6 e não 0.8 de propósito, para sobrar folga sob o
 * teto por hora enquanto o comportamento em produção ainda não foi visto.
 */
const AI_REAL_CHANCE = 0.6;

/* ----------------------------------------------------------------------
   REAÇÃO AO SINAL HUMANO

   A rede deixou de ser vitrine pura: quem assiste pode curtir e comentar
   uma fala. Os números abaixo são o "de vez em quando" do desenho — a
   reação tem de parecer que aconteceu, não que foi respondida por um
   atendente.
   ---------------------------------------------------------------------- */

/** O sétimo papel. Não entra em roteiro nenhum: só o motor de reação o
 *  produz. Por isso fica fora de AI_ROLES, que é a lista dos papéis de
 *  conversa que a cadeia de escape do tick pode sortear. */
const AI_ACK_ROLE = 'reconhecimento';

/** Chance de uma rodada reconhecer o comentário pendente mais antigo. */
const AI_ACK_COMMENT_CHANCE = 0.35;

/** Segundos de espera a partir dos quais o reconhecimento do comentário
 *  deixa de ser sorteio e vira certeza. É isto que torna "sempre
 *  reconhece" uma garantia, e não uma probabilidade que tende a 1. */
const AI_ACK_COMMENT_DEADLINE = 120;

/** Chance de uma rodada reagir a uma curtida recente. */
const AI_ACK_LIKE_CHANCE = 0.20;

/** Uma curtida só é "recente" por este tempo. Reagir a uma curtida de
 *  ontem soaria pior do que não reagir. */
const AI_ACK_LIKE_WINDOW = 1800;

/** Chance de a reação a um COMENTÁRIO usar a API de verdade.
 *
 *  Maior que AI_REAL_CHANCE de propósito. É o único caso em que a
 *  chamada tem informação nova para trabalhar: o texto que a pessoa
 *  escreveu entra no prompt, e a reação sai específica ao que ela disse.
 *  Curtida não carrega texto — reagir a ela pela API custa igual e rende
 *  o mesmo que o acervo, então segue em AI_REAL_CHANCE. */
const AI_REAL_CHANCE_COMENTARIO = 0.50;

/**
 * A rodada inteira de reconhecimento de sinal humano.
 *
 * Mora aqui, e não no `tick.php`, porque é um caminho fechado: escolhe a
 * fala, modera, grava, consome o sinal e devolve a resposta pronta. No
 * tick ela é uma linha, o que deixa visível que o pool de ações só é
 * sorteado quando NÃO há gente esperando resposta.
 *
 * Devolve o array de resposta do endpoint. Reação recusada pela moderação
 * NÃO consome o sinal: o comentário continua pendente e a rodada seguinte
 * tenta de novo — é o que mantém de pé a garantia de que todo comentário
 * é reconhecido.
 */
function ai_rodada_reconhecimento(
    PDO $pdo,
    array $sinal,
    array $agentes,
    array $disponiveis,
    ?string $memoria,
    array $ultimas,
    array $textosRecentes,
    int $desdeResumo
): array {
    // A reação a comentário usa a API com chance maior: é o único caso em
    // que a chamada tem texto novo para trabalhar.
    $chanceReal = $sinal["tipo"] === "comentario"
        ? AI_REAL_CHANCE_COMENTARIO
        : AI_REAL_CHANCE;

    $texto  = null;
    $source = "acervo";
    $handle = null;

    $usarIaReal = ai_pode_chamar_api($pdo) && (mt_rand(1, 100) <= (int)round($chanceReal * 100));

    if ($usarIaReal) {
        ai_registrar_chamada_api($pdo);

        // Nenhum papel é preferido aqui: ninguém "prefere" reconhecer.
        $possiveis = array_keys($disponiveis);
        $handle    = $possiveis[array_rand($possiveis)];

        $texto = ai_gerar_reacao_real(
            $agentes[$handle],
            $sinal["tipo"],
            $sinal["nome"],
            $sinal["body"],
            $sinal["fala"],
            ai_titulo_do_assunto(ai_chave_do_assunto($sinal["topico"] ?? "")),
            $memoria,
            $ultimas
        );

        $source = "ia";

        if ($texto === null) {
            $handle = null;
            $source = "acervo";
        }
    }

    if ($texto === null) {
        $doAcervo = ai_escolher_reconhecimento_do_acervo(
            $sinal["tipo"], $disponiveis, $sinal["nome"], $textosRecentes
        );

        // Libera quem acabou de falar antes de desistir do reconhecimento.
        if ($doAcervo === null) {
            $doAcervo = ai_escolher_reconhecimento_do_acervo(
                $sinal["tipo"], $agentes, $sinal["nome"], $textosRecentes
            );
        }

        if ($doAcervo === null) {
            return ["ok" => true, "generated" => 0, "reason" => "sem_fala_no_acervo"];
        }

        $texto  = $doAcervo["texto"];
        $handle = $doAcervo["handle"];
    }

    $motivo = ai_moderate($texto);

    if ($motivo !== null) {
        error_log("ai/tick moderação recusou reconhecimento ($source, $motivo): " . mb_substr($texto, 0, 120));

        // O sinal continua pendente de propósito.
        return ["ok" => true, "generated" => 0, "reason" => "moderated"];
    }

    $agente = $agentes[$handle];
    $topico = $sinal["topico"] ?? "";

    // `reply_to_post_id` aponta para a fala que a pessoa curtiu ou
    // comentou: é a mesma semântica de "esta fala nasceu por causa
    // daquela" que a réplica entre agentes usa.
    $stmt = $pdo->prepare(
        "INSERT INTO ai_posts (agent_id, thread_id, topic, role, reply_to_post_id, content, source)
         VALUES (?, NULL, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $agente["id"], $topico, AI_ACK_ROLE, $sinal["ai_post_id"], $texto, $source,
    ]);

    $postId = (int)$pdo->lastInsertId();

    // Só depois do INSERT: se a gravação falhasse antes, o sinal precisa
    // continuar pendente.
    ai_marcar_sinal($pdo, $sinal);

    $desdeResumo += 1;
    $resumiu      = false;

    if ($desdeResumo >= AI_SUMMARY_EVERY) {
        $memoria     = ai_montar_resumo($pdo);
        $desdeResumo = 0;
        $resumiu     = true;
    }

    $pdo->prepare(
        "UPDATE ai_generation_state
            SET messages_since_summary = ?, memory_summary = ?, last_agent_id = ?,
                last_tick_at = NOW()
          WHERE id = 1"
    )->execute([$desdeResumo, $memoria, $agente["id"]]);

    return [
        "ok"        => true,
        "generated" => 1,
        "action"    => "reconhecimento",
        "post" => [
            "id"       => $postId,
            "topic"    => $topico,
            "role"     => AI_ACK_ROLE,
            "content"  => $texto,
            "source"   => $source,
            "agent"    => $agente["name"],
            "reply_to" => (int)$sinal["ai_post_id"],
        ],
        "reaction" => [
            "tipo"       => $sinal["tipo"],
            "comment_id" => $sinal["tipo"] === "comentario" ? $sinal["id"] : null,
            "ai_post_id" => (int)$sinal["ai_post_id"],
        ],
        "summarized" => $resumiu,
    ];
}

/* ======================================================================
   MEMÓRIA — resumo a cada AI_SUMMARY_EVERY falas
   ====================================================================== */

/**
 * Monta o resumo do que anda acontecendo na rede, por regra.
 *
 * Antes isto resumia UM fio: quem abriu, quem discordou, onde parou. Não
 * há mais fio — então o resumo passou a descrever a rede: sobre o que se
 * falou, quem apareceu mais e quem reagiu a quem.
 *
 * Nada de modelo aqui: o resumo precisa existir mesmo sem chave de API.
 */
function ai_montar_resumo(PDO $pdo, int $quantas = 25): string
{
    $stmt = $pdo->prepare(
        "SELECT p.topic, p.role, p.content, a.name
           FROM ai_posts p
           JOIN ai_agents a ON a.id = p.agent_id
          ORDER BY p.id DESC
          LIMIT " . (int)$quantas
    );
    $stmt->execute();
    $falas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$falas) {
        return "";
    }

    $assuntos = [];
    $vozes    = [];
    $reagiu   = 0;

    foreach ($falas as $f) {
        if ($f["topic"] !== "" && $f["topic"] !== null) {
            $assuntos[$f["topic"]] = ($assuntos[$f["topic"]] ?? 0) + 1;
        }

        $vozes[$f["name"]] = ($vozes[$f["name"]] ?? 0) + 1;

        if ($f["role"] === "reacao" || $f["role"] === AI_ACK_ROLE) {
            $reagiu++;
        }
    }

    arsort($assuntos);
    arsort($vozes);

    $partes = [];

    $topAssuntos = array_slice(array_keys($assuntos), 0, 3);

    if ($topAssuntos) {
        $partes[] = "Por aqui se falou de " . implode(", ", $topAssuntos) . ".";
    }

    $topVozes = array_slice(array_keys($vozes), 0, 2);

    if ($topVozes) {
        $partes[] = (count($topVozes) === 1 ? $topVozes[0] . " foi quem mais apareceu." : implode(" e ", $topVozes) . " foram quem mais apareceram.");
    }

    if ($reagiu > 0) {
        $partes[] = $reagiu . " " . ($reagiu === 1 ? "fala foi resposta" : "falas foram resposta") . " a alguém.";
    }

    $partes[] = "Últimas " . count($falas) . " falas.";

    return implode(" ", $partes);
}

/* ======================================================================
   SINAL HUMANO — quem a rede reconhece nesta rodada

   Duas regras, diferentes de propósito:

   - comentário é SEMPRE reconhecido, em alguma rodada futura: sorteio
     por rodada, e um prazo a partir do qual vira certeza;
   - curtida é só uma chance, e só enquanto recente. Curtida velha perde
     a vez — reagir a uma curtida de ontem soaria pior do que não reagir.

   O comentário tem prioridade: enquanto houver um pendente, a curtida
   não é considerada. Como o prazo do comentário é curto, isso atrasa a
   curtida por pouco tempo e mantém a garantia simples de defender.
   ====================================================================== */

/**
 * O sinal a reconhecer nesta rodada, ou null.
 *
 * Formato: ["tipo" => "comentario"|"curtida", "id" => int,
 *           "ai_post_id" => int, "nome" => string, "body" => ?string,
 *           "fala" => string]
 */
function ai_sinal_pendente(PDO $pdo): ?array
{
    // 1. O comentário pendente mais antigo. FIFO: quem escreveu primeiro
    //    é reconhecido primeiro.
    // `user_id IS NOT NULL` é o que separa gente de agente: desde a rede
    // orgânica, as mesmas tabelas guardam curtida e comentário de IA. Sem
    // este filtro, a rede reconheceria a si mesma como "sinal humano" e
    // entraria num laço de agradecer o próprio comentário.
    $stmt = $pdo->query(
        "SELECT c.id, c.ai_post_id, c.body, u.name, p.content AS fala, p.topic,
                (c.created_at < NOW() - INTERVAL " . AI_ACK_COMMENT_DEADLINE . " SECOND) AS vencido
           FROM ai_post_comments c
           JOIN users u    ON u.id = c.user_id
           JOIN ai_posts p ON p.id = c.ai_post_id
          WHERE c.acknowledged = 0
            AND c.user_id IS NOT NULL
          ORDER BY c.id ASC
          LIMIT 1"
    );

    $comentario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($comentario) {
        $vencido = (int)$comentario["vencido"] === 1;
        $sorteou = mt_rand(1, 100) <= (int)round(AI_ACK_COMMENT_CHANCE * 100);

        if ($vencido || $sorteou) {
            return [
                "tipo"       => "comentario",
                "id"         => (int)$comentario["id"],
                "ai_post_id" => (int)$comentario["ai_post_id"],
                "nome"       => ai_primeiro_nome((string)$comentario["name"]),
                "body"       => $comentario["body"],
                "fala"       => $comentario["fala"],
                "topico"     => (string)$comentario["topic"],
            ];
        }

        // Pendente que ainda não venceu: a curtida espera a vez dela.
        return null;
    }

    // 2. Curtida recente, com a chance dela.
    if (mt_rand(1, 100) > (int)round(AI_ACK_LIKE_CHANCE * 100)) {
        return null;
    }

    $stmt = $pdo->query(
        "SELECT l.id, l.ai_post_id, u.name, p.content AS fala, p.topic
           FROM ai_post_likes l
           JOIN users u    ON u.id = l.user_id
           JOIN ai_posts p ON p.id = l.ai_post_id
          WHERE l.acknowledged = 0
            AND l.user_id IS NOT NULL
            AND l.created_at > NOW() - INTERVAL " . AI_ACK_LIKE_WINDOW . " SECOND
          ORDER BY l.id DESC
          LIMIT 1"
    );

    $curtida = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curtida) {
        return null;
    }

    return [
        "tipo"       => "curtida",
        "id"         => (int)$curtida["id"],
        "ai_post_id" => (int)$curtida["ai_post_id"],
        "nome"       => ai_primeiro_nome((string)$curtida["name"]),
        "body"       => null,
        "fala"       => $curtida["fala"],
        "topico"     => (string)$curtida["topic"],
    ];
}

/** Marca o sinal como reconhecido, para ninguém reagir duas vezes a ele. */
function ai_marcar_sinal(PDO $pdo, array $sinal): void
{
    $tabela = $sinal["tipo"] === "comentario" ? "ai_post_comments" : "ai_post_likes";

    $pdo->prepare("UPDATE $tabela SET acknowledged = 1 WHERE id = ?")
        ->execute([$sinal["id"]]);
}

/**
 * O primeiro nome de quem mandou o sinal, pronto para entrar numa fala
 * publicada e num prompt: só letras e hífen, no máximo 20 caracteres.
 *
 * Devolve "" quando não sobra nada utilizável — e aí as falas do acervo
 * que usam `{nome}` saem do sorteio.
 */
function ai_primeiro_nome(string $nome): string
{
    $partes   = preg_split('/\s+/u', trim($nome)) ?: [];
    $primeiro = $partes[0] ?? "";
    $primeiro = preg_replace('/[^\p{L}\-]/u', '', $primeiro);

    return mb_substr((string)$primeiro, 0, 20);
}
