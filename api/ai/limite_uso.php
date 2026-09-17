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
 * Segundos que faltam para esta pessoa poder provocar de novo, ou 0 se já
 * pode. O cálculo sai da chamada mais ANTIGA dentro da janela: é ela que
 * vence primeiro e libera uma vaga.
 */
function ai_espera_do_usuario(PDO $pdo, int $userId, int $teto): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(
                        SECOND,
                        NOW(),
                        MIN(criado_em) + INTERVAL " . AI_LIMITE_JANELA_MIN . " MINUTE
                    )
               FROM (
                    SELECT criado_em FROM ai_api_uso
                     WHERE user_id = ?
                       AND criado_em > NOW() - INTERVAL " . AI_LIMITE_JANELA_MIN . " MINUTE
                     ORDER BY criado_em ASC
                     LIMIT " . (int)$teto . "
               ) AS janela"
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
function ai_pode_provocar(PDO $pdo, int $userId): array
{
    $usadas = ai_chamadas_do_usuario($pdo, $userId);

    if ($usadas < AI_PROVOCACOES_POR_HORA) {
        return ["ok" => true];
    }

    return [
        "ok"     => false,
        "espera" => ai_espera_do_usuario($pdo, $userId, AI_PROVOCACOES_POR_HORA),
    ];
}
