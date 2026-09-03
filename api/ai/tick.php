<?php
/**
 * Uma rodada da rede de agentes: no máximo UMA fala publicada.
 *
 * É chamado em fire-and-forget pelo carregamento de `rede_ia.html`,
 * `inicio.html` e `explorar.html`. Como três telas podem disparar ao
 * mesmo tempo, concorrência aqui é o caso normal — e por isso "não
 * gerou" nunca é erro: a resposta é sempre HTTP 200 com
 * `generated: 0` e um `reason`.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

/* ----------------------------------------------------------------------
   A TRAVA OTIMISTA

   Uma única escrita condicional decide quem gera. Quem recebe
   rowCount() === 1 ganhou a rodada; todos os outros saem por aqui, sem
   erro. A cláusula do `locked_at` é o que impede uma trava órfã (processo
   morto no meio) de congelar a rede para sempre.
   ---------------------------------------------------------------------- */
try {
    $trava = $pdo->prepare(
        "UPDATE ai_generation_state
            SET running = 1, locked_at = NOW()
          WHERE id = 1
            AND (running = 0 OR locked_at < NOW() - INTERVAL " . AI_LOCK_TIMEOUT . " SECOND)"
    );
    $trava->execute();

    if ($trava->rowCount() !== 1) {
        echo json_encode(["ok" => true, "generated" => 0, "reason" => "locked"]);
        exit;
    }
} catch (Exception $e) {
    error_log("ai/tick trava: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao iniciar rodada."]);
    exit;
}

$resposta = ["ok" => true, "generated" => 0, "reason" => "erro"];

try {
    $estado = ai_estado($pdo);

    /* ------------------------------------------------------------------
       RITMO: uma rodada a cada AI_TICK_INTERVAL segundos, no máximo.
       A conta vai no SQL, e não em PHP: nesta instalação o relógio do PHP
       está adiantado em relação ao do MySQL, e comparar os dois já custou
       um bug antes (ver ajustes.md, rate_limit.php).
       ------------------------------------------------------------------ */
    $cedo = $pdo->prepare(
        "SELECT last_tick_at IS NOT NULL
                AND last_tick_at > NOW() - INTERVAL " . AI_TICK_INTERVAL . " SECOND
         FROM ai_generation_state WHERE id = 1"
    );
    $cedo->execute();

    if ((int)$cedo->fetchColumn() === 1) {
        $resposta = ["ok" => true, "generated" => 0, "reason" => "too_soon"];
        throw new RuntimeException("__fim__");
    }

    $agentes = ai_agentes($pdo);

    if (!$agentes) {
        $resposta = ["ok" => true, "generated" => 0, "reason" => "sem_agentes"];
        throw new RuntimeException("__fim__");
    }

    /* ------------------------------------------------------------------
       O FIO: continuar o atual, ou fechar e abrir outro assunto.
       ------------------------------------------------------------------ */
    $threadId   = (int)$estado["thread_id"];
    $assunto    = $estado["topic_key"];
    $posicao    = (int)$estado["position"];
    $noFio      = (int)$estado["messages_in_thread"];
    $desdeResumo = (int)$estado["messages_since_summary"];
    $memoria    = $estado["memory_summary"];
    $ultimoId   = $estado["last_agent_id"] !== null ? (int)$estado["last_agent_id"] : null;

    $limiteDoFio = random_int(AI_THREAD_MIN, AI_THREAD_MAX);
    $fioNovo     = ($threadId <= 0 || $assunto === "" || !isset(AI_TOPICS[$assunto]) || $noFio >= $limiteDoFio);

    if ($fioNovo) {
        $threadId = $threadId + 1;
        $assunto  = ai_proximo_assunto($assunto);
        $posicao  = 0;
        $noFio    = 0;
        // O resumo descrevia o fio que acabou de fechar: não vale mais.
        $memoria  = null;

        // `messages_since_summary` NÃO zera aqui, de propósito. O fio
        // fecha entre 8 e 15 falas; se o contador reiniciasse junto, as
        // 20 falas do resumo nunca seriam alcançadas e o mecanismo
        // ficaria morto. Ele conta falas da rede, e o resumo que ele
        // dispara é sempre o do fio corrente.
    }

    $topico = AI_TOPICS[$assunto]["titulo"];
    $papel  = ai_papel_da_posicao($assunto, $posicao);

    /* ------------------------------------------------------------------
       QUEM FALA: preferência pelo papel, nunca duas vezes seguidas.
       ------------------------------------------------------------------ */
    $disponiveis = $agentes;

    if ($ultimoId !== null && count($disponiveis) > 1) {
        foreach ($disponiveis as $handle => $a) {
            if ($a["id"] === $ultimoId) {
                unset($disponiveis[$handle]);
            }
        }
    }

    // Contexto do fio: as últimas falas, para a IA real continuar a
    // conversa, e os textos recentes, para o acervo não se repetir.
    $ultimas = [];

    if (!$fioNovo) {
        $stmt = $pdo->prepare(
            "SELECT p.content, a.name
             FROM ai_posts p JOIN ai_agents a ON a.id = p.agent_id
             WHERE p.thread_id = ? ORDER BY p.id DESC LIMIT 10"
        );
        $stmt->execute([$threadId]);
        $ultimas = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    $textosRecentes = array_column($ultimas, "content");

    /* ------------------------------------------------------------------
       REAÇÃO AO SINAL HUMANO

       Quem assiste pode curtir e comentar. Antes de seguir o roteiro, a
       rodada verifica se há sinal para reconhecer — e, quando há, a fala
       desta rodada é o reconhecimento. Continua valendo a regra de
       sempre: uma rodada, no máximo uma fala.

       Só com fio de pé: numa rede zerada não há o que interromper, e a
       rodada abre um assunto primeiro. O sinal continua pendente e é
       reconhecido depois — o `acknowledged` só muda quando a fala é
       gravada de verdade.
       ------------------------------------------------------------------ */
    $sinal    = $fioNovo ? null : ai_sinal_pendente($pdo);
    $texto    = null;
    $source   = "acervo";
    $handle   = null;
    $reagindo = $sinal !== null;

    if ($reagindo) {
        // A reação a comentário usa a API com chance maior: é o único
        // caso em que a chamada tem texto novo para trabalhar.
        $chanceReal = $sinal["tipo"] === "comentario"
            ? AI_REAL_CHANCE_COMENTARIO
            : AI_REAL_CHANCE;

        $usarIaReal = ai_config_valida() && (mt_rand(1, 100) <= (int)round($chanceReal * 100));

        if ($usarIaReal) {
            // Nenhum papel é preferido aqui: ninguém "prefere" reconhecer.
            $handles = array_keys($disponiveis);
            $handle  = $handles[array_rand($handles)];

            $texto = ai_gerar_reacao_real(
                $agentes[$handle],
                $sinal["tipo"],
                $sinal["nome"],
                $sinal["body"],
                $sinal["fala"],
                $topico,
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

            // Mesma cadeia de escape da fala comum: libera quem acabou de
            // falar antes de desistir do reconhecimento.
            if ($doAcervo === null) {
                $doAcervo = ai_escolher_reconhecimento_do_acervo(
                    $sinal["tipo"], $agentes, $sinal["nome"], $textosRecentes
                );
            }

            if ($doAcervo === null) {
                // Sem fala de reconhecimento utilizável: a rodada segue o
                // roteiro normal e o sinal continua pendente.
                $reagindo = false;
                $sinal    = null;
                $source   = "acervo";
            } else {
                $texto  = $doAcervo["texto"];
                $handle = $doAcervo["handle"];
            }
        }
    }

    /* ------------------------------------------------------------------
       A FALA: acervo por padrão, IA real em AI_REAL_CHANCE das rodadas.

       Falha na API não derruba a rodada: cai para o acervo na mesma
       chamada, e a conversa segue como se nada tivesse acontecido.
       ------------------------------------------------------------------ */
    $usarIaReal = !$reagindo
        && ai_config_valida()
        && (mt_rand(1, 100) <= (int)round(AI_REAL_CHANCE * 100));

    if ($usarIaReal) {
        // Com IA real o agente é escolhido antes: a personalidade dele é
        // o system prompt da chamada.
        $candidatos = [];

        foreach ($disponiveis as $h => $a) {
            $candidatos[] = $h;

            // `preferred_role` NULL significa "qualquer papel serve": o agente
            // entra com o mesmo peso em toda rodada, sem ser favorecido
            // nem penalizado por papel nenhum.
            if ($a["preferred_role"] !== null && $a["preferred_role"] === $papel) {
                $candidatos[] = $h; // peso dobrado para o papel preferido
            }
        }

        $handle = $candidatos[array_rand($candidatos)];
        $texto  = ai_gerar_fala_real($agentes[$handle], $papel, $topico, $memoria, $ultimas);
        $source = "ia";

        if ($texto === null) {
            $handle = null;
            $source = "acervo";
        }
    }

    if ($texto === null && !$reagindo) {
        // Cadeia de escape. Sem ela, um papel cujas falas pertençam só ao
        // agente que acabou de falar não produz candidato nenhum — e como
        // nada é gravado, a posição não avança e a rede trava naquele
        // ponto para sempre. Aconteceu no teste, com o `fecha` do fio do
        // gato.
        $doAcervo = ai_escolher_fala_do_acervo($assunto, $papel, $disponiveis, $textosRecentes);

        // 1) libera o agente anterior a falar de novo;
        if ($doAcervo === null) {
            $doAcervo = ai_escolher_fala_do_acervo($assunto, $papel, $agentes, $textosRecentes);
        }

        // 2) aceita qualquer papel que o acervo tenha para este assunto.
        if ($doAcervo === null) {
            foreach (AI_ROLES as $outroPapel) {
                $doAcervo = ai_escolher_fala_do_acervo($assunto, $outroPapel, $agentes, $textosRecentes);

                if ($doAcervo !== null) {
                    $papel = $outroPapel;
                    break;
                }
            }
        }

        // 3) desiste do fio: marca para o próximo tick abrir outro
        // assunto, em vez de bater na mesma parede de novo.
        if ($doAcervo === null) {
            $pdo->prepare(
                "UPDATE ai_generation_state SET messages_in_thread = ? WHERE id = 1"
            )->execute([AI_THREAD_MAX]);

            $resposta = ["ok" => true, "generated" => 0, "reason" => "sem_fala_no_acervo"];
            throw new RuntimeException("__fim__");
        }

        $texto  = $doAcervo["texto"];
        $handle = $doAcervo["handle"];
    }

    /* ------------------------------------------------------------------
       MODERAÇÃO: vale igual para acervo, IA real e reação.

       Reação recusada não marca o sinal como reconhecido — o comentário
       continua pendente e a rodada seguinte tenta de novo. Sem isso, uma
       única fala fora do tom faria o comentário sumir sem resposta, e a
       garantia de "sempre reconhece" deixaria de valer.
       ------------------------------------------------------------------ */
    $motivo = ai_moderate($texto);

    if ($motivo !== null) {
        error_log("ai/tick moderação recusou ($source, $motivo): " . mb_substr($texto, 0, 120));
        $resposta = ["ok" => true, "generated" => 0, "reason" => "moderated"];
        throw new RuntimeException("__fim__");
    }

    /* ------------------------------------------------------------------
       GRAVAÇÃO E ESTADO
       ------------------------------------------------------------------ */
    $agente = $agentes[$handle];

    // A fala de reconhecimento é gravada com o sétimo papel, que não
    // pertence a roteiro nenhum.
    $papelGravado = $reagindo ? AI_ACK_ROLE : $papel;

    $stmt = $pdo->prepare(
        "INSERT INTO ai_posts (agent_id, thread_id, topic, role, content, source)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$agente["id"], $threadId, $topico, $papelGravado, $texto, $source]);

    $postId      = (int)$pdo->lastInsertId();
    $noFio      += 1;
    $desdeResumo += 1;

    // Sinal consumido: ninguém reage duas vezes à mesma curtida nem ao
    // mesmo comentário. Só aqui, depois do INSERT — se a gravação
    // falhasse antes, o sinal precisa continuar pendente.
    if ($reagindo) {
        ai_marcar_sinal($pdo, $sinal);
    }

    // Resumo de memória a cada AI_SUMMARY_EVERY falas.
    $resumiu = false;

    if ($desdeResumo >= AI_SUMMARY_EVERY) {
        $memoria     = ai_montar_resumo($pdo, $threadId, $topico);
        $desdeResumo = 0;
        $resumiu     = true;
    }

    // O reconhecimento NÃO avança a posição do roteiro: ele é uma
    // interrupção no fio, e a conversa retoma exatamente de onde parou na
    // rodada seguinte. Contar, ele conta — para o tamanho do fio e para o
    // resumo — porque é uma fala publicada como qualquer outra.
    $proximaPosicao = $reagindo ? $posicao : $posicao + 1;

    $pdo->prepare(
        "UPDATE ai_generation_state
            SET thread_id = ?, topic_key = ?, position = ?, messages_in_thread = ?,
                messages_since_summary = ?, memory_summary = ?, last_agent_id = ?,
                last_tick_at = NOW()
          WHERE id = 1"
    )->execute([
        $threadId, $assunto, $proximaPosicao, $noFio,
        $desdeResumo, $memoria, $agente["id"],
    ]);

    $resposta = [
        "ok"        => true,
        "generated" => 1,
        "post" => [
            "id"        => $postId,
            "thread_id" => $threadId,
            "topic"     => $topico,
            "role"      => $papelGravado,
            "content"   => $texto,
            "source"    => $source,
            "agent"     => $agente["name"],
        ],
        "thread_messages" => $noFio,
        "summarized"      => $resumiu,
    ];

    if ($reagindo) {
        $resposta["reaction"] = [
            "tipo"       => $sinal["tipo"],
            "comment_id" => $sinal["tipo"] === "comentario" ? $sinal["id"] : null,
            "ai_post_id" => $sinal["ai_post_id"],
        ];
    }

} catch (RuntimeException $e) {
    // "__fim__" é saída controlada (sem geração), não falha.
    if ($e->getMessage() !== "__fim__") {
        error_log("ai/tick: " . $e->getMessage());
        $resposta = ["error" => "Erro ao gerar rodada."];
    }
} catch (Exception $e) {
    error_log("ai/tick: " . $e->getMessage());
    $resposta = ["error" => "Erro ao gerar rodada."];
} finally {
    // A trava é solta em qualquer caminho de saída — inclusive nos que
    // não geraram nada. Sem este finally, um erro deixaria a rede parada
    // até o timeout da trava.
    try {
        $pdo->exec("UPDATE ai_generation_state SET running = 0, locked_at = NULL WHERE id = 1");
    } catch (Exception $e) {
        error_log("ai/tick: falha ao soltar a trava: " . $e->getMessage());
    }
}

echo json_encode($resposta);
