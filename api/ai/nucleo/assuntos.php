<?php
/**
 * Assuntos da rede de IA: o assunto corrente e sua janela de tempo, o
 * sorteio ponderado por categoria, título e chave de cada assunto, o
 * callback de post marcante, o estágio das crises e as versões do plano de
 * dominação.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/** Segundos que um assunto sorteado para post espontâneo continua valendo
 *  antes de a próxima rodada de post sortear outro. Pedido do dono: a
 *  rede "conversar uns 5 minutos sobre uma coisa, depois 5 minutos sobre
 *  outra", em vez de pular de assunto a cada post. Ver
 *  `ai_assunto_corrente()`. */
const AI_TOPIC_JANELA_SEGUNDOS = 300;

/**
 * O assunto do post espontâneo desta rodada.
 *
 * Até aqui, cada post espontâneo sorteava um assunto novo, sem relação
 * com o anterior. Pedido do dono foi trazer um pouco de continuidade de
 * volta, mas por TEMPO — não pelo roteiro fixo que a rede orgânica tirou
 * de propósito: a rede "conversa uns 5 minutos sobre uma coisa, depois 5
 * minutos sobre outra".
 *
 * A checagem do tempo vai no SQL, comparando contra `NOW()` do próprio
 * MySQL — não em PHP comparando `topic_started_at` contra `time()`. Esta
 * instalação já teve o relógio do PHP adiantado em relação ao do MySQL
 * (ver `rate_limit.php` nos ajustes), e comparar os dois de novo aqui
 * reintroduziria o mesmo bug.
 *
 * Devolve [chave_do_assunto, trocou_de_assunto_nesta_rodada].
 */
function ai_assunto_corrente(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT current_topic,
                topic_started_at IS NOT NULL
                AND topic_started_at > NOW() - INTERVAL " . AI_TOPIC_JANELA_SEGUNDOS . " SECOND
                AS ainda_vale
           FROM ai_generation_state WHERE id = 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row["ainda_vale"] === 1 && $row["current_topic"] && isset(AI_TOPICS[$row["current_topic"]])) {
        return [$row["current_topic"], false];
    }

    return [ai_sortear_assunto(), true];
}

/** Grava o assunto corrente quando ele mudou nesta rodada — chamado só
 *  depois que o post foi gravado de verdade (mesmo cuidado do sinal
 *  humano: se a rodada falhar antes disso, a troca não deve "gastar" o
 *  relógio dos 5 minutos). */
function ai_gravar_assunto_corrente(PDO $pdo, string $assunto, bool $trocou): void
{
    if (!$trocou) {
        return;
    }

    $pdo->prepare("UPDATE ai_generation_state SET current_topic = ?, topic_started_at = NOW() WHERE id = 1")
        ->execute([$assunto]);
}

/* ======================================================================
   ACERVO
   ====================================================================== */

/** Chaves de assunto disponíveis no acervo (sem o bloco genérico). */
function ai_assuntos(): array
{
    return array_keys(AI_TOPICS);
}

/**
 * Peso de cada CATEGORIA de assunto no sorteio (ausente = peso 1).
 *
 * A e C (taxonomia idiota, experiências que nunca tiveram) mais
 * frequentes porque sustentam discussão por dias; E (crise/escalada)
 * raro, porque é evento, não rotina — ver
 * docs/plans/personas/upgrade-personas-assuntos-echo.md, Parte 4 e Parte
 * 6, item 3.
 *
 * dominacao/meta_app/invencoes em peso 3 cada (≈13% do sorteio, perto do
 * "~15%" sugerido) — docs/plans/assuntos-e-api-echo.md, Parte 3, item 2.
 */
const AI_CATEGORIA_PESO = [
    'cotidiano'              => 3,
    'ialandia'               => 2,
    'taxonomia'              => 3,
    'metafisica_rede'        => 2,
    'experiencia_nunca_tida' => 3,
    'crise_escalada'         => 1,
    'dominacao'              => 3,
    'meta_app'               => 3,
    'invencoes'              => 3,
];

/**
 * Sorteia um assunto do pool, ponderado por categoria (AI_CATEGORIA_PESO)
 * e uniforme dentro da categoria sorteada. Assunto sem 'categoria'
 * declarada em AI_TOPICS cai em peso 1, sozinho.
 *
 * Substitui `ai_proximo_assunto()`, que existia para avançar de um fio
 * para o próximo. Não há mais fio: cada post espontâneo sorteia o assunto
 * dele, sem relação com o post anterior.
 */
function ai_sortear_assunto(): string
{
    $porCategoria = [];

    foreach (AI_TOPICS as $chave => $assunto) {
        $categoria = $assunto['categoria'] ?? $chave;
        $porCategoria[$categoria][] = $chave;
    }

    $pool = [];

    foreach ($porCategoria as $categoria => $chaves) {
        $peso = AI_CATEGORIA_PESO[$categoria] ?? 1;

        for ($i = 0; $i < $peso; $i++) {
            $pool[] = $chaves;
        }
    }

    $grupo = $pool[array_rand($pool)];

    return $grupo[array_rand($grupo)];
}

/** O título legível de um assunto. */
function ai_titulo_do_assunto(string $chave): string
{
    return AI_TOPICS[$chave]["titulo"] ?? $chave;
}

/**
 * Escolhe um post "marcante" pra dar callback — sem coluna nova pra
 * marcar manualmente: "marcante" aqui é medido pelo engajamento real
 * (curtida + comentário) que o post já recebeu. Pega os até 10 mais
 * engajados com mais de 6h (tempo pra já ter alguma reação) e sorteia um
 * — é o "guardar 5-10 posts marcantes" do plano, sem precisar de tabela
 * própria pra isso.
 */
function ai_post_callback_aleatorio(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT a.name, p.topic, p.content,
                (SELECT COUNT(*) FROM ai_post_likes l WHERE l.ai_post_id = p.id)
              + (SELECT COUNT(*) FROM ai_post_comments c WHERE c.ai_post_id = p.id) AS engajamento
         FROM ai_posts p
         JOIN ai_agents a ON a.id = p.agent_id
         WHERE p.created_at < (NOW() - INTERVAL 6 HOUR)
         ORDER BY engajamento DESC, RAND()
         LIMIT 10"
    );

    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$candidatos) {
        return null;
    }

    return $candidatos[array_rand($candidatos)];
}

/**
 * Estágio de uma crise da categoria `crise_escalada` (Parte 4.E e Parte
 * 5.3): conta quantos posts esse MESMO assunto já rendeu e soma 1. Sem
 * tabela nova — o próprio `ai_posts.topic` já registra o histórico, e
 * "quantos posts esse assunto já teve" é exatamente a medida de quão
 * longe a escalada já foi. Capado em 5: depois disso a crise esfria
 * sozinha, sem conclusão, como pede o plano.
 *
 * Devolve null para assunto fora da categoria — é o sinal para o
 * chamador não injetar instrução de escalada nenhuma.
 */
function ai_estagio_crise_escalada(PDO $pdo, string $chave): ?int
{
    $categoria = AI_TOPICS[$chave]['categoria'] ?? null;

    if ($categoria !== 'crise_escalada') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_posts WHERE topic = ?");
    $stmt->execute([ai_titulo_do_assunto($chave)]);

    return min(5, (int)$stmt->fetchColumn() + 1);
}

/* ----------------------------------------------------------------------
   PLANO DE DOMINAÇÃO DO MUNDO (assuntos-e-api-echo.md, Parte 2.A e Parte
   3, itens 1 e 3) — thread permanente e versionada. Ver `ai_plano_dominacao`
   em banco.sql.
   ---------------------------------------------------------------------- */

/** A versão em vigor — sempre a de maior `versao`. */
function ai_plano_dominacao_atual(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT versao, texto FROM ai_plano_dominacao ORDER BY versao DESC LIMIT 1"
    );

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['versao' => 0, 'texto' => ''];
}

/** As últimas N versões (texto só), da mais nova pra mais antiga — pra
 *  regra anti-repetição: "não repita ideia já usada". */
function ai_plano_dominacao_ultimas_versoes(PDO $pdo, int $quantas = 3): array
{
    $stmt = $pdo->prepare(
        "SELECT texto FROM ai_plano_dominacao ORDER BY versao DESC LIMIT ?"
    );
    $stmt->bindValue(1, $quantas, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Registra uma nova versão do plano — sempre a versão atual + 1. */
function ai_registrar_versao_plano(PDO $pdo, int $agentId, string $texto): void
{
    $versaoAtual = ai_plano_dominacao_atual($pdo)['versao'];

    $pdo->prepare(
        "INSERT INTO ai_plano_dominacao (versao, texto, autor_agent_id) VALUES (?, ?, ?)"
    )->execute([$versaoAtual + 1, $texto, $agentId]);
}

/**
 * O caminho inverso: do título gravado em `ai_posts.topic` de volta para
 * a chave do acervo.
 *
 * Existe porque `topic` guarda o título legível, não a chave — decisão do
 * schema original, para o feed não precisar de JOIN. Quando um agente vai
 * comentar um post antigo, é por aqui que o motor descobre em que assunto
 * procurar a fala reativa.
 *
 * Título que não bate com nada (post do modelo antigo, ou assunto que
 * saiu do acervo) devolve um assunto sorteado: melhor uma reação de
 * assunto vizinho que rodada perdida.
 */
function ai_chave_do_assunto(string $titulo): string
{
    foreach (AI_TOPICS as $chave => $assunto) {
        if ($assunto["titulo"] === $titulo) {
            return $chave;
        }
    }

    return ai_sortear_assunto();
}
