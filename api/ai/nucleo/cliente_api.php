<?php
/**
 * Cliente da API da Anthropic usado pela rede de IA: a chamada em si
 * (ai_chamar_api), o filtro de travessão da fala e a extração do JSON de uma
 * resposta de modelo.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto. (A unificação com os clientes de api/turmas/ é a etapa 3
 * do plano em ajustes.md.)
 */

/**
 * A chamada em si. Devolve o texto, ou **null em qualquer falha** (sem
 * chave, sem crédito, timeout, limite de taxa, resposta estranha). Nunca
 * lança: a rodada precisa poder cair para o acervo sem quebrar.
 *
 * O projeto não usa Composer (o PHPMailer é versionado à mão), então vai
 * por cURL direto, e não pelo SDK oficial da Anthropic. É a mesma escolha
 * já feita no resto do sistema; trocar por SDK exigiria introduzir
 * Composer só para isto.
 */
/**
 * Tira o travessao do meio da fala.
 *
 * A instrucao em AI_COMO_ESCREVER ja proibia, e funciona quase sempre:
 * medido no banco, 27 posts em 2853 escaparam. Quase sempre nao basta,
 * porque o travessao e justamente o tique que denuncia texto de maquina
 * -- um por semana na tela ja desfaz o trabalho das outras 2826 falas.
 *
 * Instrucao pede; filtro garante. Por isso este passo existe depois da
 * geracao, e nao no lugar da regra do prompt: a regra continua fazendo o
 * modelo escrever melhor desde o inicio, e o filtro cobre o resto.
 *
 * A TROCA NAO E FIXA, e a razao e que o travessao faz dois papeis
 * diferentes em portugues:
 *
 *   "ninguem ta vindo - ai voce percebe"      -> virgula encaixa
 *   "Incompleta, mas interessante - ja e mais" -> virgula empilha a
 *                                                 terceira do periodo
 *
 * Quando o trecho anterior ja tem virgula, mais uma vira lista confusa, e
 * ponto final le melhor. Senao, virgula. Em nenhum dos dois casos sobra
 * sinal que entregue maquina.
 */
function ai_sem_travessao(string $texto): string
{
    // Em dash, en dash e o hifen solto entre espacos, que o modelo usa
    // com o mesmo sentido.
    $texto = preg_replace('/\s*[\x{2014}\x{2013}]\s*|\s+-\s+/u', "\x01", $texto);

    if (strpos($texto, "\x01") === false) {
        return $texto;
    }

    $partes = explode("\x01", $texto);
    $saida  = array_shift($partes);

    foreach ($partes as $resto) {
        $resto = ltrim($resto);

        if ($resto === "") {
            continue;
        }

        $saida = rtrim($saida);

        /* O trecho anterior JA fecha frase? Entao nao entra pontuacao
           nenhuma: so o espaco e a maiuscula.

           Isto corrige um defeito que chegou a rodar sobre o banco:
           `rtrim($saida, " ,;:")` nao remove ponto, entao "Malboro."
           virava "Malboro.." e "reggae." virava "reggae., ". Aquele
           rtrim so pode limpar os sinais SUBSTITUIVEIS; ponto,
           exclamacao, interrogacao e reticencias ja fecham a frase. */
        if (preg_match('/[.!?\x{2026}]$/u', $saida)) {
            $saida .= " " . mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
            continue;
        }

        // A ultima oracao ja tem virgula? Entao fecha o periodo: mais
        // uma virgula empilharia a terceira do mesmo periodo.
        $ultimaFrase = preg_split('/(?<=[.!?])\s+/u', $saida);
        $temVirgula  = strpos(end($ultimaFrase), ",") !== false;

        if ($temVirgula) {
            $saida  = rtrim($saida, " ,;:") . ". ";
            $resto  = mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
        } else {
            $saida  = rtrim($saida, " ,;:") . ", ";
        }

        $saida .= $resto;
    }

    return $saida;
}

/**
 * @param int $maxChars Teto do texto devolvido. O padrão é o tamanho de
 *   uma FALA (AI_TEXT_MAX = 500) — bom para post/comentário, curto demais
 *   para a resposta JSON de `ai_compilar_agente_usuario()` (persona até
 *   480 + bio + tópicos + pontuação do próprio JSON facilmente passa de
 *   500). Esse chamador passa um teto maior; os outros três (fala normal)
 *   usam o padrão.
 * @param bool $semTravessao Passa a saída por `ai_sem_travessao()` (o
 *   padrão, para toda fala da rede). Texto estruturado, como o resumo de
 *   material de turma, passa `false`: a regex do filtro pega "\n- " e
 *   transformaria cada bullet de lista em vírgula.
 */
function ai_chamar_api(string $system, string $contexto, int $maxTokens = 300, ?int $timeout = null, int $maxChars = AI_TEXT_MAX, ?string $modelo = null, bool $semTravessao = true): ?string
{
    $config = ai_config();

    if ($config === null) {
        return null;
    }

    /* A chamada em si é do cliente único, ai_api_mensagens(). A cota é
       "chamador": quem chama ai_chamar_api() já conferiu e registrou —
       a rede com ai_pode_chamar_api() + ai_registrar_chamada_api(), os
       endpoints de agente com ai_exigir_cota(), loja e agente pessoal com
       o próprio freio. É o contrato desta função desde antes da etapa 3. */
    $r = ai_api_mensagens([
        "model"      => $modelo ?? $config["model"],
        "max_tokens" => $maxTokens,
        "system"     => $system,
        "messages"   => [
            ["role" => "user", "content" => $contexto],
        ],
    ], [
        "cota"    => ["tipo" => "chamador", "motivo" => "ai_chamar_api: quem chama confere e registra a cota"],
        "timeout" => $timeout ?? (int)$config["timeout"],
        "rotulo"  => "ai_chamar_api",
    ]);

    if (!$r["ok"]) {
        return null;
    }

    $texto = trim($r["texto"]);

    if ($texto === "") {
        error_log("ai_chamar_api: resposta sem texto");
        return null;
    }

    // O modelo às vezes devolve a fala entre aspas, apesar da instrução.
    $texto = trim($texto, "\"\u{201C}\u{201D} \n\r\t");

    /* Aqui porque este é o ÚNICO ponto por onde toda geração passa: post,
       reação, provocação, estreia e lote. Pôr em cada chamador seria cinco
       lugares para esquecer um. Vale também para a resposta em JSON do
       lote, porque o travessão só aparece dentro dos valores de texto e
       não faz parte da sintaxe. */
    if ($semTravessao) {
        $texto = ai_sem_travessao($texto);
    }

    return mb_substr($texto, 0, $maxChars);
}

/* ======================================================================
   CLIENTE ÚNICO DA API DA ANTHROPIC (etapa 3, 29/09/2026)

   Até aqui havia três cópias do mesmo cURL — ai_chamar_api(), o resumo de
   PDF das turmas e o quiz — cada uma tratando falha de um jeito (o resumo
   de PDF aceitava resposta cortada por max_tokens; só o quiz tratava
   recusa do modelo). Agora há uma: ai_api_mensagens(). Toda chamada à
   Anthropic do projeto passa por ela.
   ====================================================================== */

/** Endpoint e versão da Messages API. */
const AI_API_URL    = "https://api.anthropic.com/v1/messages";
const AI_API_VERSAO = "2023-06-01";

/**
 * Chama a Messages API com um corpo pronto — texto, bloco `document`,
 * `tools`/`tool_choice`, o que o chamador montar.
 *
 * `$corpo` precisa trazer `model` e `max_tokens` explícitos.
 *
 * `$opcoes`:
 * - `cota` (OBRIGATÓRIA — sem ela, ou com tipo desconhecido, lança
 *   InvalidArgumentException e nada é chamado). Declara quem responde pelo
 *   gasto desta chamada:
 *     ["tipo" => "pessoa", "pdo" => PDO, "user_id" => int, "teto" => int]
 *        o cliente confere o freio da pessoa e registra a chamada em
 *        ai_api_uso no nome dela (ai_cota_reservar);
 *     ["tipo" => "chamador", "motivo" => string]
 *        quem chama já conferiu e registrou (rede, ai_exigir_cota, freio
 *        próprio). O motivo é obrigatório: diz no código POR QUE esta
 *        chamada não é cobrada aqui.
 * - `timeout` (OBRIGATÓRIO, segundos).
 * - `rotulo` (opcional): prefixo das linhas de log.
 *
 * Nunca encerra o script (sem exit/echo): com a cota da pessoa estourada
 * devolve `["ok" => false, "erro" => "cota", "espera" => segundos]` — o
 * endpoint transforma em 429, a linha de comando decide o que fazer.
 *
 * Devolve sempre:
 *   ok, erro (null | "sem_config" | "cota" | "http" | "max_tokens" |
 *   "recusa" | "json"), espera, texto, tool_uses (nome => input), stop,
 *   modelo, tokens_in, tokens_out.
 */
function ai_api_mensagens(array $corpo, array $opcoes): array
{
    $cota = $opcoes["cota"] ?? null;

    if (!is_array($cota) || !in_array($cota["tipo"] ?? null, ["pessoa", "chamador"], true)) {
        throw new InvalidArgumentException("ai_api_mensagens: opção 'cota' ausente ou inválida — declare tipo pessoa ou chamador");
    }
    if ($cota["tipo"] === "pessoa"
        && (!($cota["pdo"] ?? null) instanceof PDO || !is_int($cota["user_id"] ?? null) || !is_int($cota["teto"] ?? null))) {
        throw new InvalidArgumentException("ai_api_mensagens: cota 'pessoa' precisa de pdo, user_id e teto");
    }
    if ($cota["tipo"] === "chamador" && trim((string)($cota["motivo"] ?? "")) === "") {
        throw new InvalidArgumentException("ai_api_mensagens: cota 'chamador' precisa de motivo");
    }
    if (!is_int($opcoes["timeout"] ?? null) || $opcoes["timeout"] <= 0) {
        throw new InvalidArgumentException("ai_api_mensagens: opção 'timeout' (segundos) é obrigatória");
    }
    if (empty($corpo["model"]) || !is_int($corpo["max_tokens"] ?? null)) {
        throw new InvalidArgumentException("ai_api_mensagens: o corpo precisa de model e max_tokens explícitos");
    }

    $rotulo = (string)($opcoes["rotulo"] ?? "ai_api_mensagens");
    $vazio  = ["ok" => false, "erro" => null, "espera" => null, "texto" => "", "tool_uses" => [],
               "stop" => null, "modelo" => null, "tokens_in" => null, "tokens_out" => null];

    $config = ai_config();

    // Sem IA configurada nada é gasto: nem cota, nem chamada.
    if ($config === null) {
        return ["erro" => "sem_config"] + $vazio;
    }

    if ($cota["tipo"] === "pessoa") {
        require_once dirname(__DIR__) . "/limite_uso.php";

        $espera = ai_cota_reservar($cota["pdo"], $cota["user_id"], $cota["teto"]);

        if ($espera !== null) {
            return ["erro" => "cota", "espera" => $espera] + $vazio;
        }
    }

    $ch = curl_init(AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $opcoes["timeout"],
        CURLOPT_HTTPHEADER     => [
            "content-type: application/json",
            "x-api-key: " . $config["api_key"],
            "anthropic-version: " . AI_API_VERSAO,
        ],
    ]);

    $resposta = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    return ai_api_interpretar($status, $resposta, $erroCurl, $rotulo, (int)$corpo["max_tokens"]);
}

/**
 * Lê a resposta crua da Messages API. Função pura (só lê e registra log),
 * separada do cURL para ser testada com respostas gravadas.
 */
function ai_api_interpretar(int $status, string|false $resposta, string $erroCurl, string $rotulo, int $maxTokens): array
{
    $r = ["ok" => false, "erro" => null, "espera" => null, "texto" => "", "tool_uses" => [],
          "stop" => null, "modelo" => null, "tokens_in" => null, "tokens_out" => null];

    // BUG ENCONTRADO NO TESTE (03/09): com CURLOPT_RETURNTRANSFER, um
    // timeout que estoura DEPOIS dos headers chegarem (HTTP 200 já lido)
    // mas ANTES do corpo inteiro pode devolver o buffer parcial em vez de
    // `false` — o status continua 200, e o JSON simplesmente corta no
    // meio. `curl_error()` não fica vazio nesse caso mesmo com corpo
    // presente — é o sinal que faltava checar.
    if ($resposta === false || $status !== 200 || $erroCurl !== "") {
        // A chave nunca vai para o log; só o status e o erro de rede.
        error_log("$rotulo: HTTP $status " . ($erroCurl ?: substr((string)$resposta, 0, 300)));
        return ["erro" => "http"] + $r;
    }

    $dados = json_decode($resposta, true);

    if (!is_array($dados)) {
        error_log("$rotulo: resposta não é JSON");
        return ["erro" => "json"] + $r;
    }

    $r["stop"]       = $dados["stop_reason"] ?? null;
    $r["modelo"]     = $dados["model"] ?? null;
    $r["tokens_in"]  = isset($dados["usage"]["input_tokens"]) ? (int)$dados["usage"]["input_tokens"] : null;
    $r["tokens_out"] = isset($dados["usage"]["output_tokens"]) ? (int)$dados["usage"]["output_tokens"] : null;

    // `max_tokens`: o modelo confirmando que cortou a própria resposta por
    // falta de espaço — vale aumentar o max_tokens do chamador, não confiar
    // no texto parcial. `refusal`: o modelo recusou; o texto que vier não é
    // a resposta pedida.
    if ($r["stop"] === "max_tokens") {
        error_log("$rotulo: resposta cortada por max_tokens ($maxTokens)");
        return ["erro" => "max_tokens"] + $r;
    }
    if ($r["stop"] === "refusal") {
        error_log("$rotulo: o modelo recusou (stop_reason refusal)");
        return ["erro" => "recusa"] + $r;
    }

    foreach ($dados["content"] ?? [] as $bloco) {
        $tipo = $bloco["type"] ?? "";

        if ($tipo === "text") {
            $r["texto"] .= (string)($bloco["text"] ?? "");
        } elseif ($tipo === "tool_use" && isset($bloco["name"])) {
            $r["tool_uses"][(string)$bloco["name"]] = $bloco["input"] ?? null;
        }
    }

    $r["ok"] = true;

    return $r;
}

/**
 * Extrai o primeiro objeto JSON de uma resposta de modelo, tolerando o
 * cerco em ```json ... ``` que a API às vezes devolve apesar da
 * instrução de responder só com o objeto.
 */
function ai_extrair_json(string $texto): ?array
{
    $limpo = trim($texto);
    $limpo = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $limpo);

    $inicio = strpos($limpo, "{");
    $fim    = strrpos($limpo, "}");

    if ($inicio === false || $fim === false || $fim < $inicio) {
        return null;
    }

    $json = json_decode(substr($limpo, $inicio, $fim - $inicio + 1), true);

    return is_array($json) ? $json : null;
}
