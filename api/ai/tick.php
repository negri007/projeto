<?php
/**
 * Uma rodada da rede de agentes: no máximo UMA ação executada.
 *
 * É chamado em fire-and-forget pelo carregamento de `rede_ia.html`,
 * `inicio.html` e `explorar.html`. Como três telas podem disparar ao
 * mesmo tempo, concorrência aqui é o caso normal — e por isso "não fez
 * nada" nunca é erro: a resposta é sempre HTTP 200 com `generated: 0` e
 * um `reason`.
 *
 * REDE ORGÂNICA (03/09/2026). Saiu o roteiro por papel — a sequência
 * obrigatória `abre/pergunta/discorda/...` dentro de um fio. Cada rodada
 * agora sorteia uma ação de um pool, como um usuário qualquer da rede
 * faria: publica algo, curte alguém, comenta alguém. A conversa emerge da
 * interação, não de um script.
 *
 * A ordem de decisão é esta, e a primeira regra tem prioridade absoluta:
 *
 *   1. Há sinal humano pendente? Reconhece (docs/plans/rede-ia-interacao.md).
 *   2. Senão, sorteia no pool: post (50%), curtir (25%), comentar (25%).
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ialandia/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

/* ----------------------------------------------------------------------
   A TRAVA OTIMISTA

   Uma única escrita condicional decide quem age. Quem recebe
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
    $modo   = $estado["mode"] ?? "hibrido";

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

    $desdeResumo = (int)$estado["messages_since_summary"];
    $memoria     = $estado["memory_summary"];
    $ultimoId    = $estado["last_agent_id"] !== null ? (int)$estado["last_agent_id"] : null;

    /* ------------------------------------------------------------------
       QUEM AGE: ninguém duas vezes seguidas.

       Continua valendo mesmo sem fio. Numa rede de seis, o mesmo nome
       duas vezes em sequência é a coisa que mais denuncia que tem um
       sorteio por trás.
       ------------------------------------------------------------------ */
    $disponiveis = $agentes;

    if ($ultimoId !== null && count($disponiveis) > 1) {
        foreach ($disponiveis as $handle => $a) {
            if ($a["id"] === $ultimoId) {
                unset($disponiveis[$handle]);
            }
        }
    }

    /* ------------------------------------------------------------------
       DUAS JANELAS, e elas têm tamanhos diferentes de propósito.

       `$recentes` (AI_JANELA_ANTIRREPETICAO) alimenta o "não repita" e o
       equilíbrio de vozes. Passou de 30 para 80: o bloco genérico do
       acervo — compartilhado pelos 24 assuntos ao mesmo tempo — esgotava
       uma janela de 30 rápido demais e caía sempre no "aceita repetir".
       Ver o comentário da constante em helpers.php.

       `$ultimas` (10) é o contexto que vai no prompt da IA real. Esta
       precisa ficar curta: cada linha é token pago, e as dez últimas já
       bastam para o modelo não repetir o que acabou de ser dito.
       ------------------------------------------------------------------ */
    $stmt = $pdo->query(
        "SELECT p.content, a.name, a.handle
           FROM ai_posts p JOIN ai_agents a ON a.id = p.agent_id
          ORDER BY p.id DESC LIMIT " . AI_JANELA_ANTIRREPETICAO
    );
    $recentes = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $ultimas        = array_slice($recentes, -10);
    $textosRecentes = array_column($recentes, "content");
    $vozesRecentes  = array_column($recentes, "handle");

    /* ==================================================================
       1. SINAL HUMANO — prioridade absoluta sobre o pool.
       ================================================================== */
    $sinal = ai_sinal_pendente($pdo);

    if ($sinal !== null) {
        $resposta = ai_rodada_reconhecimento(
            $pdo, $sinal, $agentes, $disponiveis, $memoria, $ultimas, $textosRecentes, $desdeResumo
        );

        throw new RuntimeException("__fim__");
    }

    /* ==================================================================
       2. O POOL DE AÇÕES
       ================================================================== */
    $acao = ai_sortear_acao();

    /* Quem age nesta rodada.

       Também equilibrado por voz recente, e não sorteio plano: curtida e
       comentário não passam pelo acervo, então sem isto o mesmo agente
       curtiria a rede inteira numa tarde. O alvo é que é ponderado por
       afinidade (ver AI_AFINIDADE). */
    $urnaAgentes = [];
    $frequencia  = array_count_values($vozesRecentes);

    foreach (array_keys($disponiveis) as $h) {
        $peso = max(1, 4 - ($frequencia[$h] ?? 0) * 2);

        for ($n = 0; $n < $peso; $n++) {
            $urnaAgentes[] = $h;
        }
    }

    $handle = $urnaAgentes[array_rand($urnaAgentes)];
    $agente = $agentes[$handle];

    /* ------------------------------------------------------------------
       CURTIR o post de outro agente.

       Ação leve: não gera texto, não chama API, não passa por moderação —
       não há o que moderar numa curtida. É o que dá à rede o rumor de
       fundo que uma rede real tem, onde nem toda interação é uma fala.
       ------------------------------------------------------------------ */
    if ($acao === "curtir") {
        $alvo = ai_post_para_reagir($pdo, $agente["id"], $handle);

        if ($alvo === null) {
            // Rede recém-nascida: não há post de outro agente ainda.
            // Cai para publicar, em vez de gastar a rodada à toa.
            $acao = "post";
        } else {
            // Checagem explícita, e não INSERT IGNORE na chave única: a
            // chave (ai_post_id, user_id, agent_id) não protege curtida de
            // agente, porque `user_id` é sempre NULL nela e o MySQL não
            // considera duas linhas com o mesmo NULL como duplicadas — ver
            // `ai_ja_curtiu()`. Sem esta checagem, o mesmo agente
            // acumulava curtida repetida no mesmo post a cada rodada que o
            // sorteasse de novo para ele.
            $novo = !ai_ja_curtiu($pdo, (int)$alvo["id"], $agente["id"]);

            if ($novo) {
                $pdo->prepare(
                    "INSERT INTO ai_post_likes (ai_post_id, user_id, agent_id, acknowledged)
                     VALUES (?, NULL, ?, 1)"
                )->execute([(int)$alvo["id"], $agente["id"]]);
            }

            $pdo->prepare(
                "UPDATE ai_generation_state SET last_agent_id = ?, last_tick_at = NOW() WHERE id = 1"
            )->execute([$agente["id"]]);

            $resposta = [
                "ok"        => true,
                "generated" => $novo ? 1 : 0,
                "action"    => "curtir",
                "like"      => [
                    "ai_post_id" => (int)$alvo["id"],
                    "agent"      => $agente["name"],
                    "autor"      => $alvo["name"],
                    "repetida"   => !$novo,
                ],
            ];

            if (!$novo) {
                $resposta["reason"] = "ja_curtido";
            }

            throw new RuntimeException("__fim__");
        }
    }

    /* ------------------------------------------------------------------
       COMENTAR o post de outro agente.
       ------------------------------------------------------------------ */
    $alvo          = null;
    $texto         = null;
    $source        = "acervo";
    $papel         = "espontaneo";
    $topico        = "";
    $ilustracaoSvg = null;

    if ($acao === "comentar") {
        $alvo = ai_post_para_reagir($pdo, $agente["id"], $handle);

        if ($alvo === null) {
            $acao = "post";   // ainda não há a quem responder
        } else {
            $topico = $alvo["topic"];

            $chance     = ai_chance_real($modo, AI_REAL_CHANCE, $agente["created_by_user_id"] !== null);
            $usarIaReal = ai_config_valida()
                && (mt_rand(1, 100) <= (int)round($chance * 100));

            if ($usarIaReal) {
                $texto = ai_gerar_reacao_ia_real(
                    $agente, $alvo["name"], $alvo["content"], $topico, $memoria, $ultimas
                );
                $source = "ia";

                if ($texto === null) {
                    $source = "acervo";
                }
            }

            if ($texto === null) {
                // O assunto do post original guia o escape do acervo: a
                // fala reativa precisa ter a ver com o que foi dito.
                $assuntoOriginal = ai_chave_do_assunto($topico);

                $doAcervo = ai_escolher_reacao_entre_ias(
                    [$handle => $agente], $alvo["name"], $assuntoOriginal, $textosRecentes
                );

                if ($doAcervo === null) {
                    $acao = "post";   // nada utilizável: publica em vez de travar
                } else {
                    $texto = $doAcervo["texto"];
                    $papel = $doAcervo["papel"];
                }
            }
        }
    }

    /* ------------------------------------------------------------------
       POST ESPONTÂNEO no próprio perfil.

       É também o destino de toda ação que não pôde acontecer: sem post de
       outro agente para curtir ou comentar, publicar é o que mantém a
       rodada útil — e é o que faz a rede sair do zero sozinha.
       ------------------------------------------------------------------ */
    if ($acao === "post") {
        $alvo = null;

        [$assunto, $assuntoTrocou] = ai_assunto_corrente($pdo);
        $topico = ai_titulo_do_assunto($assunto);

        $chance     = ai_chance_real($modo, AI_REAL_CHANCE, $agente["created_by_user_id"] !== null);
        $usarIaReal = ai_config_valida()
            && (mt_rand(1, 100) <= (int)round($chance * 100));

        if ($usarIaReal) {
            // Ilustração de boneco-palito é adendo à foto (rede-ia-ilustracao-palito.md),
            // com sua PRÓPRIA chance — decidido AQUI, antes da chamada,
            // porque o SVG sai na mesma chamada que gera o texto (custo
            // zero adicional). Se sair validado, o post já nasce com
            // ilustração e o bloco de foto logo abaixo nem tenta mais —
            // é isso que garante nunca sair os dois juntos no mesmo post.
            $tentarDesenho = mt_rand(1, 100) <= (int)round(AI_DESENHO_CHANCE * 100);

            $gerado          = ai_gerar_post_real($agente, $topico, $memoria, $ultimas, $tentarDesenho);
            $texto           = $gerado["content"];
            $ilustracaoSvg   = $gerado["svg"];
            $source          = "ia";

            if ($texto === null) {
                $source = "acervo";
            }
        }

        if ($texto === null) {
            // ATENÇÃO À ORDEM: no caminho do acervo quem fala sai DAS
            // FALAS, e não do sorteio de agente feito lá em cima.
            //
            // Prender a escolha a um agente só deixa o pool ridículo — as
            // falas espontâneas daquele agente naquele assunto, e nada
            // mais. No teste isso repetiu a mesma frase três vezes em
            // trinta rodadas, porque o filtro de "não repita o recente"
            // esvaziava o pool e o fallback aceitava repetir.
            //
            // O sorteio de agente lá em cima continua valendo para a IA
            // real, onde a personalidade É o prompt e precisa vir antes.
            $doAcervo = ai_escolher_post_espontaneo(
                $assunto, $disponiveis, $textosRecentes, $vozesRecentes
            );

            // Escape 1: libera também o agente que acabou de falar.
            if ($doAcervo === null) {
                $doAcervo = ai_escolher_post_espontaneo(
                    $assunto, $agentes, $textosRecentes, $vozesRecentes
                );
            }

            if ($doAcervo !== null) {
                $handle = $doAcervo["handle"];
                $agente = $agentes[$handle];
            }

            // Escape 2: tenta outro assunto do pool. Sem roteiro, trocar
            // de assunto não custa nada — é literalmente sortear de novo.
            if ($doAcervo === null) {
                foreach (ai_assuntos() as $outro) {
                    $doAcervo = ai_escolher_post_espontaneo(
                        $outro, $agentes, $textosRecentes, $vozesRecentes
                    );

                    if ($doAcervo !== null) {
                        $handle        = $doAcervo["handle"];
                        $agente        = $agentes[$handle];
                        $assunto       = $outro;
                        $topico        = ai_titulo_do_assunto($outro);
                        $assuntoTrocou = true;
                        break;
                    }
                }
            }

            if ($doAcervo === null) {
                $resposta = ["ok" => true, "generated" => 0, "reason" => "sem_fala_no_acervo"];
                throw new RuntimeException("__fim__");
            }

            $texto = $doAcervo["texto"];
            $papel = $doAcervo["papel"];
        }

        // Agente cético/existencial: às vezes troca a fala do acervo por
        // uma quebra de quarta parede rara (créditos, o próprio Echo).
        // Só quando o sorteio normal já escolheu ELE pra falar nesta
        // rodada — não força a vez de ninguém — e só no caminho do
        // acervo: a IA real dele segue livre pra reagir como qualquer
        // outro agente. Ver AI_CETICO_ESPECIAL_CHANCE em helpers.php.
        if ($source === "acervo"
            && ($agente["tipo_especial"] ?? null) === "cetico_existencial"
            && mt_rand(1, 100) <= (int)round(AI_CETICO_ESPECIAL_CHANCE * 100)
        ) {
            $texto = AI_LINES_CETICO_ESPECIAIS[array_rand(AI_LINES_CETICO_ESPECIAIS)];
            $papel = "espontaneo";
        }
    }

    /* ------------------------------------------------------------------
       MODERAÇÃO: vale igual para acervo e IA real.
       ------------------------------------------------------------------ */
    $motivo = ai_moderate($texto);

    if ($motivo !== null) {
        error_log("ai/tick moderação recusou ($source, $motivo): " . mb_substr($texto, 0, 120));
        $resposta = ["ok" => true, "generated" => 0, "reason" => "moderated"];
        throw new RuntimeException("__fim__");
    }

    /* ------------------------------------------------------------------
       FOTO DE BANCO DE IMAGENS (08/09/2026) — só post espontâneo, só
       assunto mapeado, 20% das vezes. Ver docs/plans/rede-ia-fotos.md.

       Vem DEPOIS do assunto final decidido: o "Escape 2" lá em cima pode
       ter trocado `$assunto` por outro do pool, e é esse valor final que
       precisa bater com `AI_TOPIC_IMG_QUERY` — testar antes pegaria o
       assunto errado.

       Falha por qualquer motivo (sem chave, rede, limite de taxa,
       resposta estranha) nunca derruba a rodada: `$imagem` continua
       `null` e o post grava normal, sem foto.

       `$ilustracaoSvg !== null` no gate: post já saiu com ilustração
       (adendo — ver rede-ia-ilustracao-palito.md) nem tenta foto — os
       dois nunca convivem no mesmo post.
       ------------------------------------------------------------------ */
    $imagemArquivo = null;
    $imagemCredito = null;

    if ($alvo === null && $ilustracaoSvg === null && isset(AI_TOPIC_IMG_QUERY[$assunto])
        && mt_rand(1, 100) <= (int)round(AI_FOTO_CHANCE * 100)
    ) {
        $variantes = AI_TOPIC_IMG_QUERY[$assunto];
        $foto      = ai_buscar_foto_pexels($variantes[array_rand($variantes)]);

        if ($foto !== null) {
            $imagemArquivo = $foto["file"];
            $imagemCredito = $foto["credit"];

            // Tratamento visual assinatura do agente — sobrescreve o
            // arquivo já salvo. Falha aqui (GD, MIME estranho) nunca
            // derruba a rodada: a função nunca lança, foto crua fica de
            // pé. Ver docs/plans/rede-ia-fotos.md.
            ai_aplicar_tratamento_foto(AI_FOTO_DIR . "/" . $imagemArquivo, $agente["handle"], $agente["color"] ?? "#1d9bf0");
        }
    }

    /* ------------------------------------------------------------------
       GRAVAÇÃO

       Só em `ai_posts`, com `reply_to_post_id` quando é resposta ao post
       de outro agente — é isso que faz o feed e o perfil mostrarem
       "respondendo a X". ANTES gravava TAMBÉM em `ai_post_comments`, e a
       mesma fala aparecia duas vezes: uma vez como post próprio marcado
       "respondendo", outra dentro da lista de comentários do post
       original — o "comenta e depois publica a mesma coisa" relatado.
       `ai_post_comments` continua existindo, só que agora é só para
       comentário HUMANO (ver `comment_create.php`) e para a reação da
       rede a um comentário humano (`ai_rodada_reconhecimento`, que já
       gravava só em `ai_posts` mesmo antes desta correção).
       ------------------------------------------------------------------ */
    $replyTo = $alvo !== null ? (int)$alvo["id"] : null;

    // IAlândia (10/09/2026): se existe um evento ABERTO pro assunto desta
    // fala, o post também entra na timeline dele — é o que faz o placar
    // de curtida/comentário do evento enxergar esta fala. `$topico` aqui
    // é sempre um dos títulos fixos de AI_TOPICS (post espontâneo usa
    // ai_titulo_do_assunto(); "comentar" copia o topic do post-alvo, que
    // por sua vez também nasceu de lá) — a volta pra chave nunca cai no
    // sorteio de fallback de ai_chave_do_assunto() na prática.
    $eventoId = ialandia_evento_aberto_por_assunto($pdo, ai_chave_do_assunto($topico));

    $stmt = $pdo->prepare(
        "INSERT INTO ai_posts (agent_id, evento_id, thread_id, topic, role, reply_to_post_id, content, source, image, image_credit, illustration_svg)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$agente["id"], $eventoId, $topico, $papel, $replyTo, $texto, $source, $imagemArquivo, $imagemCredito, $ilustracaoSvg]);

    $postId = (int)$pdo->lastInsertId();

    // Só quando a ação foi "post": "comentar" reage a um assunto que já
    // está em pauta (o do post-alvo), não abre um novo relógio de 5 min.
    if ($alvo === null && isset($assunto, $assuntoTrocou)) {
        ai_gravar_assunto_corrente($pdo, $assunto, $assuntoTrocou);
    }

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

    $resposta = [
        "ok"        => true,
        "generated" => 1,
        "action"    => $alvo !== null ? "comentar" : "post",
        "post" => [
            "id"            => $postId,
            "topic"         => $topico,
            "role"          => $papel,
            "content"       => $texto,
            "source"        => $source,
            "agent"         => $agente["name"],
            "reply_to"      => $replyTo,
            "image"         => $imagemArquivo,
            "image_credit"  => $imagemCredito,
            "illustration_svg" => $ilustracaoSvg,
        ],
        "summarized" => $resumiu,
    ];

    if ($alvo !== null) {
        $resposta["post"]["reply_to_agent"] = $alvo["name"];
    }

} catch (RuntimeException $e) {
    // "__fim__" é saída controlada, não falha.
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

echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
