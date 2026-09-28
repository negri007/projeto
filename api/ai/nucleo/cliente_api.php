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

    $corpo = json_encode([
        "model"      => $modelo ?? $config["model"],
        "max_tokens" => $maxTokens,
        "system"     => $system,
        "messages"   => [
            ["role" => "user", "content" => $contexto],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout ?? $config["timeout"],
        CURLOPT_HTTPHEADER     => [
            "content-type: application/json",
            "x-api-key: " . $config["api_key"],
            "anthropic-version: 2023-06-01",
        ],
    ]);

    $resposta = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    // BUG ENCONTRADO NO TESTE (03/09): com CURLOPT_RETURNTRANSFER, um
    // timeout que estoura DEPOIS dos headers chegarem (HTTP 200 já lido)
    // mas ANTES do corpo inteiro pode devolver o buffer parcial em vez de
    // `false` — o status continua 200, e o JSON simplesmente corta no
    // meio. A checagem antiga só olhava `$resposta === false`, então uma
    // resposta truncada passava disso e quebrava só lá na frente, no
    // parse do JSON, com uma mensagem que não apontava pra causa real.
    // `curl_error()` não fica vazio nesse caso mesmo com corpo presente
    // — é o sinal que faltava checar.
    if ($resposta === false || $status !== 200 || $erroCurl !== "") {
        // A chave nunca vai para o log; só o status e o erro de rede.
        error_log("ai_chamar_api: HTTP $status " . ($erroCurl ?: substr((string)$resposta, 0, 200)));
        return null;
    }

    $dados = json_decode($resposta, true);

    // `stop_reason: "max_tokens"` é o modelo confirmando que cortou a
    // própria resposta por falta de espaço — diferente do caso acima
    // (rede), aqui vale aumentar `$maxTokens` no chamador, não confiar
    // no texto parcial.
    if (($dados["stop_reason"] ?? null) === "max_tokens") {
        error_log("ai_chamar_api: resposta cortada por max_tokens ($maxTokens)");
        return null;
    }

    $texto = "";

    foreach ($dados["content"] ?? [] as $bloco) {
        if (($bloco["type"] ?? "") === "text") {
            $texto .= $bloco["text"];
        }
    }

    $texto = trim($texto);

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
