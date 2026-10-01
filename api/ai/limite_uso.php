<?php
/**
 * Freio de uso por pessoa nas ações que gastam crédito de API.
 *
 * O teto que já existia, `AI_TETO_CHAMADAS_HORA` (20), é GLOBAL: conta
 * toda chamada da instalação, venha de onde vier. Ele protege a conta de
 * cobrança, e faz bem isso — mas não protege os usuários uns dos outros.
 *
 * "Falar com a IAlândia" dispara de 2 a 4 chamadas de uma vez, e não tinha
 * freio nenhum. Uma pessoa apertando o botão seis vezes seguidas consome as
 * 20 chamadas da hora inteira sozinha, e a rede emudece para todo mundo até
 * a janela girar. Não precisa de má intenção: basta alguém empolgado na
 * hora errada, e "a hora errada" inclui a apresentação do TCC.
 *
 * Por isso o limite é POR USUÁRIO e por janela, e some quando a janela
 * passa. Reaproveita `ai_api_uso`, que já registra uma linha por chamada,
 * em vez de criar tabela nova — mas precisa saber de quem foi a chamada,
 * daí a coluna `user_id` acrescentada em banco.sql.
 */

// `login_tempo_legivel()` mora no freio do login e formata segundos em
// "3 minutos". Reaproveitar evita duas versoes da mesma frase divergindo.
require_once __DIR__ . "/../auth/rate_limit.php";

/** Quantas provocações uma pessoa pode disparar por hora. */
const AI_PROVOCACOES_POR_HORA = 4;

/** Janela do freio, em minutos. */
const AI_LIMITE_JANELA_MIN = 60;

/**
 * Quantas chamadas de API esta pessoa já provocou na janela.
 *
 * Nunca lança: falha de contagem não pode derrubar a funcionalidade. Se a
 * consulta quebrar, devolve 0 — o teto global continua valendo como rede
 * de baixo, então o pior caso é o freio por pessoa não valer nessa vez.
 */
function ai_chamadas_do_usuario(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ai_api_uso
              WHERE user_id = ?
                AND criado_em > NOW() - INTERVAL " . AI_LIMITE_JANELA_MIN . " MINUTE"
        );
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("ai_chamadas_do_usuario: " . $e->getMessage());

        return 0;
    }
}

/**
 * Segundos que faltam para esta pessoa poder agir de novo, ou 0 se já
 * pode.
 *
 * Com `$usadas` chamadas na janela e teto `$teto`, só volta a caber quando
 * `$usadas - $teto + 1` delas vencerem — a espera sai da chamada nessa
 * posição, contando da mais antiga. Quando `$usadas == $teto` é a própria
 * mais antiga (o caso de antes). O caso de passar do teto aparece porque
 * tetos diferentes (provocação 4, agente 8, turma 10) contam a mesma
 * tabela: quem gastou 9 nas turmas e tenta provocar precisa esperar 6
 * vencerem, não 1.
 */
function ai_espera_do_usuario(PDO $pdo, int $userId, int $teto, int $usadas): int
{
    $posicao = max(0, $usadas - $teto);

    try {
        $stmt = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(
                        SECOND,
                        NOW(),
                        criado_em + INTERVAL " . AI_LIMITE_JANELA_MIN . " MINUTE
                    )
               FROM ai_api_uso
              WHERE user_id = ?
                AND criado_em > NOW() - INTERVAL " . AI_LIMITE_JANELA_MIN . " MINUTE
              ORDER BY criado_em ASC
              LIMIT 1 OFFSET " . $posicao
        );
        $stmt->execute([$userId]);

        return max(0, (int)$stmt->fetchColumn());
    } catch (Exception $e) {
        error_log("ai_espera_do_usuario: " . $e->getMessage());

        return 0;
    }
}

/**
 * Esta pessoa pode provocar a rede agora?
 *
 * Devolve `["ok" => true]` ou `["ok" => false, "espera" => segundos]`.
 * A decisão de virar HTTP 429 fica com o endpoint, não aqui.
 */
function ai_pode_provocar(PDO $pdo, int $userId, int $teto = AI_PROVOCACOES_POR_HORA): array
{
    $usadas = ai_chamadas_do_usuario($pdo, $userId);

    if ($usadas < $teto) {
        return ["ok" => true];
    }

    return [
        "ok"     => false,
        "espera" => ai_espera_do_usuario($pdo, $userId, $teto, $usadas),
    ];
}

/* ----------------------------------------------------------------------
   AÇÕES QUE FICAVAM DE FORA (28/09/2026)

   Resumo e quiz das turmas, e prévia/confirmação de agente, iam à API sem
   passar por este freio nem registrar em `ai_api_uso` — nem o teto global
   via o gasto. Com `regerar: true` num loop, o quiz (Sonnet, PDF de até
   20 MB) gastava sem limite nenhum.

   Os tetos são maiores que o da provocação porque são uso normal de
   trabalho: um professor sobe vários materiais numa tarde, e criar um
   agente passa por prévia E confirmação. Contam a mesma tabela, então o
   total da pessoa na hora continua sendo um só.
   ---------------------------------------------------------------------- */

/** Ações de IA por pessoa por hora nas turmas (resumo, quiz). */
const AI_ACOES_TURMA_POR_HORA = 10;

/**
 * Reserva uma vaga de IA para esta pessoa: confere o freio e, se couber,
 * grava a linha em `ai_api_uso` no nome dela ANTES da chamada (a chamada
 * que falha ou é recusada pela moderação também custou).
 *
 * Devolve null quando reservou, ou os segundos de espera quando o freio
 * fechou — e aí nada é gravado. Nunca encerra o script: serve igual para
 * endpoint (que responde 429) e para linha de comando (que decide o que
 * fazer com a espera). É o que o cliente da API usa (ai_api_mensagens).
 */
function ai_cota_reservar(PDO $pdo, int $userId, int $teto): ?int
{
    if (!function_exists("ai_registrar_chamada_api")) {
        require_once __DIR__ . "/helpers.php";
    }

    $freio = ai_pode_provocar($pdo, $userId, $teto);

    if (!$freio["ok"]) {
        return max(1, (int)$freio["espera"]);
    }

    ai_registrar_chamada_api($pdo, $userId);

    return null;
}

/** A mensagem do 429 de cota, igual em todo lugar que a mostra. */
function ai_cota_mensagem(int $espera): string
{
    return "Muitos pedidos à IA nesta hora. Tente de novo em "
        . login_tempo_legivel(max(1, $espera)) . ".";
}

/**
 * Freio + registro de uma ação que vai à API, para endpoint: estourado,
 * responde 429 no formato de erro da API e encerra. Livre, reserva a vaga
 * (ver ai_cota_reservar). Usado pelos endpoints de criação/edição de agente,
 * que cobram por AÇÃO antes de chamar ai_compilar_agente_usuario().
 *
 * Sem IA configurada não freia nem registra: nada vai ser gasto, e quem
 * chamou segue até o erro de sempre ("A IA não está configurada...").
 */
function ai_exigir_cota(PDO $pdo, int $userId, int $teto): void
{
    if (!function_exists("ai_config")) {
        require_once __DIR__ . "/helpers.php";
    }

    if (ai_config() === null) {
        return;
    }

    $espera = ai_cota_reservar($pdo, $userId, $teto);

    if ($espera !== null) {
        http_response_code(429);
        echo json_encode(["error" => ai_cota_mensagem($espera)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
