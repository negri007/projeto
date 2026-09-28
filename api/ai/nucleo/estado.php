<?php
/**
 * Estado da rede de IA: o passo corrente de cada agente (status), a linha
 * de estado do motor, o modo de geração (híbrido/acervo/api) e o ritmo do
 * tick.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/** Segundos mínimos entre duas rodadas. Sem isso, três abas abertas
 *  fariam a conversa disparar em velocidade absurda. */
const AI_TICK_INTERVAL = 20;

/** Segundos até uma trava órfã (processo morto no meio) expirar. */
const AI_LOCK_TIMEOUT = 30;

/* ======================================================================
   O QUE CADA AGENTE ESTA FAZENDO AGORA

   Uma rodada que passa pela API leva de 1,5 a 3,4 segundos, e ate hoje a
   tela nao mostrava nada nesse intervalo: a fala aparecia pronta, do
   nada. Estas funcoes gravam o passo corrente de UM agente para que o
   card "Os agentes" acenda so o bloquinho dele.

   Tres regras que valem para todas elas:

   1. Nunca lancam. Marcar status e enfeite; derrubar uma rodada da rede
      por causa de enfeite seria trocar o essencial pelo acessorio.
   2. Nao sao historico. Uma linha por agente, sobrescrita a cada passo.
   3. Apodrecem sozinhas. Quem le ignora linha mais velha que
      AI_STATUS_VALIDADE segundos, entao processo morto no meio nao
      deixa ninguem "pensando" para sempre na tela.
   ====================================================================== */

/**
 * Por quantos segundos um status ainda vale.
 *
 * Tem de ser maior que a rodada mais lenta ja vista (3,4s) para o status
 * nao sumir no meio de uma rodada legitima, e curto o bastante para que
 * um processo morto limpe rapido. AI_LOCK_TIMEOUT (30s) e o teto natural:
 * passado ele, a propria trava da rodada ja foi considerada orfa.
 */
const AI_STATUS_VALIDADE = 30;

/**
 * Por quantos segundos um agente que JA TERMINOU continua aparecendo.
 *
 * O motivo e o acervo. A rodada que responde pelo acervo dura 45
 * MILISSEGUNDOS (medido): comeca e acaba entre dois polls do navegador, e
 * o bloquinho nunca chegava a acender. So as rodadas que passam pela API
 * (1,5 a 3,4s) davam tempo de ser vistas, e essas sao a minoria -- com o
 * teto de 20 chamadas por hora e uma rodada a cada 20s, cerca de uma em
 * cada nove.
 *
 * Com a graca, terminar nao apaga: marca `fim`, e a leitura ainda devolve
 * a linha por mais este tanto. Toda acao passa a ter janela visivel,
 * venha do acervo ou da API.
 *
 * O VALOR TEM DE SER MAIOR QUE O POLL DE FUNDO DO NAVEGADOR, que hoje e
 * STATUS_MS = 5s em rede_ia.html. A rajada de 600ms so dispara na aba que
 * PROVOCOU a rodada; uma rodada disparada por outra aba (ou por outra
 * pessoa) chega aqui sem rajada nenhuma, e quem esta olhando so tem o
 * poll de fundo. Com graca menor que ele, essa rodada cairia inteira
 * entre dois polls e nunca apareceria -- exatamente o problema que esta
 * constante existe para resolver.
 *
 * Dai 6: um segundo de folga sobre os 5 do poll. Mexer em STATUS_MS sem
 * mexer aqui reabre o buraco.
 */
const AI_STATUS_GRACA = 6;

/**
 * Marca o passo corrente de um agente.
 *
 * `$estado` e uma das chaves de AI_STATUS_FRASES. `$detalhe` e o
 * complemento curto que aparece depois da frase ("sobre plantas").
 */
function ai_marcar_status(PDO $pdo, int $agentId, string $estado, ?string $detalhe = null): void
{
    try {
        // REPLACE e nao INSERT ... ON DUPLICATE KEY porque o unico dado
        // que interessa e o mais recente: nao ha nada da linha anterior
        // que valha a pena preservar.
        // `fim` volta a NULL: marcar um passo novo e dizer que o agente
        // esta agindo de novo, mesmo que a linha anterior dele ja
        // estivesse na graca.
        $stmt = $pdo->prepare(
            "REPLACE INTO ai_agente_status (agent_id, estado, detalhe, atualizado_em, fim)
             VALUES (?, ?, ?, NOW(), NULL)"
        );
        $stmt->execute([
            $agentId,
            mb_substr($estado, 0, 20),
            $detalhe !== null ? mb_substr($detalhe, 0, 120) : null,
        ]);
    } catch (Exception $e) {
        error_log("ai_marcar_status: " . $e->getMessage());
    }
}

/**
 * Encerra o passo de um agente QUE AGIU.
 *
 * Nao apaga a linha: carimba `fim`. O bloquinho dele continua na tela por
 * AI_STATUS_GRACA segundos, e e isso que faz a rodada do acervo -- de 45
 * milissegundos -- aparecer.
 *
 * Para quem NAO agiu (foi sorteado e o caminho mudou de dono, foi barrado
 * pela moderacao), use ai_descartar_status(): deixar a graca correndo ali
 * anunciaria na tela uma fala que nunca existiu.
 */
function ai_encerrar_status(PDO $pdo, int $agentId): void
{
    try {
        $pdo->prepare("UPDATE ai_agente_status SET fim = NOW() WHERE agent_id = ?")
            ->execute([$agentId]);
    } catch (Exception $e) {
        error_log("ai_encerrar_status: " . $e->getMessage());
    }
}

/**
 * Apaga o status de um agente (ou de todos, com `$agentId = null`), sem
 * graca nenhuma. E para quem foi marcado e acabou nao agindo.
 */
function ai_descartar_status(PDO $pdo, ?int $agentId = null): void
{
    try {
        if ($agentId === null) {
            $pdo->exec("DELETE FROM ai_agente_status");
            return;
        }

        $pdo->prepare("DELETE FROM ai_agente_status WHERE agent_id = ?")->execute([$agentId]);
    } catch (Exception $e) {
        error_log("ai_descartar_status: " . $e->getMessage());
    }
}

/**
 * Quem esta agindo agora, indexado por handle do agente.
 *
 * Devolve `[handle => ["estado" => ..., "detalhe" => ..., "ha" => seg]]`.
 * Linha velha nao entra: o filtro de validade e o que torna esta tabela
 * auto-limpante.
 */
function ai_status_ativos(PDO $pdo): array
{
    try {
        /* Duas situacoes entram, e por motivos diferentes:

           1. `fim IS NULL` -- o agente esta agindo AGORA. Ainda vale o
              teto de AI_STATUS_VALIDADE, que e o que faz um processo
              morto no meio parar de aparecer sozinho.
           2. `fim` recente -- ele acabou de agir. Continua na tela por
              AI_STATUS_GRACA segundos, e e essa clausula que da janela
              visivel a rodada do acervo, que dura 45ms. */
        $stmt = $pdo->query(
            "SELECT a.handle, s.estado, s.detalhe,
                    TIMESTAMPDIFF(SECOND, s.atualizado_em, NOW()) AS ha,
                    s.fim IS NOT NULL AS terminou
               FROM ai_agente_status s
               JOIN ai_agents a ON a.id = s.agent_id
              WHERE (s.fim IS NULL
                     AND s.atualizado_em > NOW() - INTERVAL " . AI_STATUS_VALIDADE . " SECOND)
                 OR s.fim > NOW() - INTERVAL " . AI_STATUS_GRACA . " SECOND"
        );

        $ativos = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ativos[$row["handle"]] = [
                "estado"   => $row["estado"],
                "detalhe"  => $row["detalhe"],
                "ha"       => max(0, (int)$row["ha"]),
                // O front usa isto para trocar o verbo: quem terminou nao
                // esta mais "escrevendo", e dizer que esta seria mentira
                // de tres segundos.
                "terminou" => (int)$row["terminou"] === 1,
            ];
        }

        return $ativos;
    } catch (Exception $e) {
        error_log("ai_status_ativos: " . $e->getMessage());

        return [];
    }
}

/* ======================================================================
   AGENTES E ESTADO
   ====================================================================== */

/** Agentes ativos, indexados por handle. */
function ai_agentes(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, name, handle, persona, bio, avatar, color,
                created_by_user_id, favorite_topics, tipo_especial,
                pai_id, mae_id, geracao, traits, modelo, pode_reproduzir
         FROM ai_agents WHERE active = 1 ORDER BY id ASC"
    );

    $agentes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row["id"] = (int)$row["id"];
        $agentes[$row["handle"]] = $row;
    }

    return $agentes;
}

/** A linha única de estado do motor. */
function ai_estado(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM ai_generation_state WHERE id = 1");
    $estado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$estado) {
        $pdo->exec("INSERT IGNORE INTO ai_generation_state (id) VALUES (1)");
        $estado = $pdo->query("SELECT * FROM ai_generation_state WHERE id = 1")
                      ->fetch(PDO::FETCH_ASSOC);
    }

    return $estado;
}

/** Os três modos de geração possíveis. */
const AI_MODES = ['hibrido', 'acervo', 'api'];

/**
 * Chance efetiva de a rodada gerar por IA de verdade, dado o modo e se
 * quem vai falar é um agente DE USUÁRIO.
 *
 * "acervo" nunca chama a API (custo zero, só o acervo escrito à mão).
 * "api" sempre chama (nunca cai no acervo) — é o modo de quem quer a
 * conversa mais fluida e não se importa com o custo. "hibrido" é o
 * padrão de sempre, EXCETO para um agente de usuário: ele não tem uma
 * linha sequer escrita no acervo (persona é texto livre que a pessoa
 * inventou, não uma das seis vozes fixas), então sem forçar a chamada
 * aqui ele só fala quando o sorteio de 15% dá certo — na prática, quase
 * nunca, e é por isso que agente de usuário parecia sempre inativo.
 * Forçar custa a MESMA chamada que já seria necessária pra ele falar
 * nesta rodada; não é chamada extra. Em modo "acervo" a regra não vale:
 * lá NADA chama a API, nem para agente de usuário — é o que o modo
 * promete.
 */
/**
 * O agente não tem uma linha sequer no acervo escrito à mão? Vale pro
 * agente de usuário e, desde o quiz/reprodução, pro filhote — a persona
 * dele é montada na hora do nascimento (ver criar_filhote() em
 * api/ai/reproducao.php), e AI_LINES só conhece os 7 de sistema. É o
 * terceiro argumento de `ai_chance_real()`.
 */
function ai_agente_sem_acervo(array $agente): bool
{
    return $agente["created_by_user_id"] !== null || !empty($agente["pai_id"]);
}

function ai_chance_real(string $modo, float $chanceBase, bool $agenteDeUsuario = false): float
{
    if ($modo === 'acervo') {
        return 0.0;
    }

    if ($modo === 'api') {
        return 1.0;
    }

    return $agenteDeUsuario ? 1.0 : $chanceBase;
}

/** Muda o modo de geração. Devolve false se o valor não é um dos três. */
function ai_definir_modo(PDO $pdo, string $modo): bool
{
    if (!in_array($modo, AI_MODES, true)) {
        return false;
    }

    $pdo->prepare("UPDATE ai_generation_state SET mode = ? WHERE id = 1")->execute([$modo]);

    return true;
}
