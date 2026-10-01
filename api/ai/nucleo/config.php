<?php
/**
 * Configuração da IA e o teto de chamadas: leitura do ai_config.php e o
 * registro/contagem de chamadas de API na hora.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/**
 * Teto DURO de chamadas de API por hora, enquanto o consumo real não foi
 * observado em produção (Parte 3.5: "acompanhar o consumo no Console na
 * primeira semana e ajustar"). Conta CHAMADA, não post — um lote de
 * AI_QUEUE_TAMANHO_LOTE posts custa uma chamada só. Estourar o teto não
 * é erro: a rodada simplesmente cai para o acervo, como qualquer outra
 * falha da API (ver `ai_chamar_api()`).
 *
 * 20/hora ainda deixa rodar mais do que o tick naturalmente pede
 * (AI_TICK_INTERVAL = 20s ⇒ no máximo 180 rodadas/hora, e cada lote
 * cobre AI_QUEUE_TAMANHO_LOTE delas) e é fácil de subir depois de ver o
 * Console.
 */
const AI_TETO_CHAMADAS_HORA = 20;

/** Limite de tamanho da fala, dos dois lados (acervo e IA real). */
const AI_TEXT_MAX = 500;

/* ======================================================================
   CONFIGURAÇÃO DA API
   ====================================================================== */

/**
 * Lê `api/ai/ai_config.php`, se existir. Mesmo padrão do mailer: sem
 * arquivo de configuração, o sistema continua funcionando — só sem o
 * componente de IA real.
 *
 * Devolve null quando não há configuração utilizável.
 */
function ai_config(): ?array
{
    static $cache = false;

    if ($cache !== false) {
        return $cache;
    }

    // Um nível acima: este arquivo mora em api/ai/nucleo/, o config em api/ai/.
    $arquivo = __DIR__ . "/../ai_config.php";

    if (!is_file($arquivo)) {
        return $cache = null;
    }

    $config = require $arquivo;

    if (!is_array($config) || empty($config["api_key"])) {
        return $cache = null;
    }

    $config["model"]   = $config["model"]   ?? "claude-haiku-4-5-20251001";
    $config["timeout"] = (int)($config["timeout"] ?? 15);
    // O resumo/triagem usa o `model` (Haiku) e o quiz usa `model_sonnet`
    // (o gabarito precisa estar certo). Sem a chave no arquivo, cada um cai
    // num id atual padrão.
    $config["model_haiku"]  = $config["model_haiku"]  ?? $config["model"];
    $config["model_sonnet"] = $config["model_sonnet"] ?? "claude-sonnet-5";

    return $cache = $config;
}

/** Existe chave de API utilizável? Não olha o teto por hora — é a
 *  pergunta "a IA está configurada", usada por exemplo como flag
 *  informativa pro front (feed.php). Para decidir se TENTA a API AGORA,
 *  usar `ai_pode_chamar_api()`. */
function ai_config_valida(): bool
{
    return ai_config() !== null;
}

/**
 * Quantas chamadas de API de verdade já saíram na última hora — conta
 * `ai_api_uso`, uma linha por chamada (ver `ai_registrar_chamada_api()`).
 */
function ai_chamadas_api_na_ultima_hora(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM ai_api_uso WHERE criado_em > NOW() - INTERVAL 1 HOUR"
    )->fetchColumn();
}

/**
 * A pergunta que todo ponto de decisão deve fazer antes de tentar a API
 * real: está configurada E ainda não bateu no teto por hora
 * (AI_TETO_CHAMADAS_HORA, docs/plans/assuntos-e-api-echo Parte 3.5).
 * Estourar o teto não é erro — a rodada só cai pro acervo, como qualquer
 * outra falha da API.
 */
function ai_pode_chamar_api(PDO $pdo): bool
{
    return ai_config_valida() && ai_chamadas_api_na_ultima_hora($pdo) < AI_TETO_CHAMADAS_HORA;
}

/** Registra UMA chamada de API de verdade — chamar exatamente uma vez
 *  por tentativa real, no momento em que `$usarIaReal` vira true, nunca
 *  por post gerado (um lote gera vários posts numa chamada só). */
function ai_registrar_chamada_api(PDO $pdo, ?int $userId = null): void
{
    // `user_id` carrega quem disparou a chamada (o professor ou o aluno
    // numa turma, a triagem de professor). E sobre ele que o freio por
    // pessoa em api/ai/limite_uso.php trabalha; fica nulo so quando nao
    // ha dono identificado.
    $stmt = $pdo->prepare("INSERT INTO ai_api_uso (criado_em, user_id) VALUES (NOW(), ?)");
    $stmt->execute([$userId]);
}
