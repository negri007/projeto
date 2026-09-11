<?php
/**
 * Helpers do módulo IAlândia (eventos + apostas).
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/ialandia/` e pelo hook em `api/ai/tick.php` (que marca
 * `ai_posts.evento_id` quando o assunto sorteado bate com um evento
 * aberto).
 */

/** Depois de quantas horas aberto um evento fecha sozinho — não existe
 *  painel de admin nesta versão (ver docs/API_CONTRACT.md), então o
 *  fechamento é sempre por tempo, checado de forma preguiçosa (mesmo
 *  espírito de `posts_expirar_efemeros()`). */
const IALANDIA_DURACAO_HORAS = 48;

/** Faixa de crédito aceita numa aposta — mesma moeda de `ai_credits`
 *  usada pra criar agente. */
const IALANDIA_APOSTA_MIN = 1;
const IALANDIA_APOSTA_MAX = 50;

/**
 * Id do evento ABERTO para este assunto, ou null. É o que o hook em
 * tick.php chama antes de gravar cada post — se vier preenchido, o post
 * também pertence à timeline do evento.
 */
function ialandia_evento_aberto_por_assunto(PDO $pdo, string $assuntoKey): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM ai_ialandia_eventos WHERE assunto_key = ? AND status = 'aberto' LIMIT 1"
    );
    $stmt->execute([$assuntoKey]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

/**
 * Fecha (e resolve as apostas de) todo evento aberto há mais de
 * IALANDIA_DURACAO_HORAS. Chamada antes de qualquer leitura em
 * `api/ialandia/` — nenhuma tela pode mostrar "aberto" num evento que já
 * devia ter fechado.
 */
function ialandia_expirar_eventos(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT id FROM ai_ialandia_eventos
          WHERE status = 'aberto'
            AND criado_em <= (NOW() - INTERVAL :horas HOUR)"
    );
    $stmt->execute(["horas" => IALANDIA_DURACAO_HORAS]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eventoId) {
        ialandia_encerrar_evento($pdo, (int)$eventoId);
    }
}

/**
 * Fecha um evento específico: soma o engajamento (curtida + comentário)
 * de cada agente nos posts DAQUELE evento, define o vencedor (mais
 * pontos) e resolve as apostas. Sem post nenhum no evento — ninguém
 * ganha nada pra decidir por —, todas as apostas são estornadas em vez
 * de perdidas.
 *
 * Idempotente: evento que já não está `aberto` sai sem fazer nada, então
 * chamar duas vezes (a expiração em lote e, por coincidência, uma
 * chamada direta) nunca resolve a mesma aposta duas vezes.
 */
function ialandia_encerrar_evento(PDO $pdo, int $eventoId): void
{
    $stmt = $pdo->prepare("SELECT status FROM ai_ialandia_eventos WHERE id = ?");
    $stmt->execute([$eventoId]);

    if ($stmt->fetchColumn() !== "aberto") {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT p.agent_id,
                (SELECT COUNT(*) FROM ai_post_likes l WHERE l.ai_post_id = p.id) +
                (SELECT COUNT(*) FROM ai_post_comments c WHERE c.ai_post_id = p.id) AS pontos
           FROM ai_posts p
          WHERE p.evento_id = ?"
    );
    $stmt->execute([$eventoId]);

    $pontosPorAgente = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $agenteId = (int)$row["agent_id"];
        $pontosPorAgente[$agenteId] = ($pontosPorAgente[$agenteId] ?? 0) + (int)$row["pontos"];
    }

    $vencedorId = null;

    if ($pontosPorAgente) {
        arsort($pontosPorAgente);
        $vencedorId = array_key_first($pontosPorAgente);
    }

    $pdo->prepare(
        "UPDATE ai_ialandia_eventos
            SET status = 'encerrado', encerrado_em = NOW(), agente_vencedor_id = ?
          WHERE id = ?"
    )->execute([$vencedorId, $eventoId]);

    if ($vencedorId !== null) {
        ialandia_resolver_apostas($pdo, $eventoId, $vencedorId);
    } else {
        // Evento sem post nenhum: não houve competição de verdade, então
        // devolve o crédito em vez de confiscar por um resultado que
        // nunca aconteceu.
        ialandia_estornar_apostas($pdo, $eventoId);
    }
}

/**
 * Paga as apostas do evento — pool tipo pari-mutuel: quem apostou no
 * vencedor divide TODO o pool (o que os perdedores também apostaram) na
 * proporção do que cada um apostou. Ninguém apostou no vencedor: o pool
 * inteiro fica sem dono, mas ninguém perde além do que já é regra
 * (apostar em quem perde não devolve nada).
 */
function ialandia_resolver_apostas(PDO $pdo, int $eventoId, int $vencedorId): void
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(creditos), 0) FROM ai_ialandia_apostas WHERE evento_id = ?"
    );
    $stmt->execute([$eventoId]);
    $poolTotal = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(creditos), 0) FROM ai_ialandia_apostas
          WHERE evento_id = ? AND agente_id = ?"
    );
    $stmt->execute([$eventoId, $vencedorId]);
    $poolVencedor = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, user_id, agente_id, creditos FROM ai_ialandia_apostas
          WHERE evento_id = ? AND resolvida = 0"
    );
    $stmt->execute([$eventoId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $aposta) {
        $retorno = 0;

        if ((int)$aposta["agente_id"] === $vencedorId && $poolVencedor > 0) {
            $retorno = (int)floor(((int)$aposta["creditos"] / $poolVencedor) * $poolTotal);
        }

        if ($retorno > 0) {
            $pdo->prepare("UPDATE users SET ai_credits = ai_credits + ? WHERE id = ?")
                ->execute([$retorno, (int)$aposta["user_id"]]);
        }

        $pdo->prepare(
            "UPDATE ai_ialandia_apostas SET resolvida = 1, creditos_retorno = ? WHERE id = ?"
        )->execute([$retorno, (int)$aposta["id"]]);
    }
}

/** Devolve o crédito de toda aposta pendente do evento, sem vencedor
 *  nenhum pra pagar — usado só quando o evento fecha sem post nenhum. */
function ialandia_estornar_apostas(PDO $pdo, int $eventoId): void
{
    $stmt = $pdo->prepare(
        "SELECT id, user_id, creditos FROM ai_ialandia_apostas
          WHERE evento_id = ? AND resolvida = 0"
    );
    $stmt->execute([$eventoId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $aposta) {
        $pdo->prepare("UPDATE users SET ai_credits = ai_credits + ? WHERE id = ?")
            ->execute([(int)$aposta["creditos"], (int)$aposta["user_id"]]);

        $pdo->prepare(
            "UPDATE ai_ialandia_apostas SET resolvida = 1, creditos_retorno = ? WHERE id = ?"
        )->execute([(int)$aposta["creditos"], (int)$aposta["id"]]);
    }
}

/** Normaliza uma linha de evento para o formato do contrato. `vencedor`
 *  só vem preenchido quando o evento já fechou E teve post — encerrado
 *  sem post nenhum tem `vencedor: null` mesmo estando `encerrado`. */
function ialandia_evento_row(array $row): array
{
    return [
        "id"          => (int)$row["id"],
        "titulo"      => $row["titulo"],
        "descricao"   => $row["descricao"],
        "status"      => $row["status"],
        "criado_em"   => $row["criado_em"],
        "encerrado_em" => $row["encerrado_em"] ?? null,
        "vencedor"    => !empty($row["vencedor_id"]) ? [
            "id"     => (int)$row["vencedor_id"],
            "name"   => $row["vencedor_name"],
            "handle" => $row["vencedor_handle"],
            "color"  => $row["vencedor_color"],
            "avatar" => !empty($row["vencedor_avatar"]) ? $row["vencedor_avatar"] : null,
        ] : null,
    ];
}
