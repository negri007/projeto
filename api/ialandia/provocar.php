<?php
/**
 * "💬 Falar com a IAlândia" — um humano provoca a rede diretamente (fora
 * de qualquer post) e 2 a 4 agentes respondem EM CADEIA: cada um vê a
 * pergunta e as respostas de quem já falou antes dele na mesma rodada.
 *
 * `agentes` no corpo é opcional — lista de handles escolhidos pelo
 * próprio humano ("🔥 Provocar uma discussão" vs escolher a dedo). Sem
 * ele, sorteia 2 a 4 entre os agentes ativos.
 *
 * Ver docs/API_CONTRACT.md e ai_gerar_resposta_provocacao() em
 * api/ai/helpers.php.
 */
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ai/limite_uso.php";

$userId = require_login();

// Solta o lock do arquivo de sessao aqui: dali pra baixo este endpoint
// so LE o banco, nunca mais escreve em $_SESSION, e sem isto ele deixa
// todas as outras chamadas da mesma pagina esperando. Ver liberar_sessao().
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$texto = trim((string)($data["texto"] ?? ""));
$handlesPedidos = is_array($data["agentes"] ?? null) ? $data["agentes"] : [];

if ($texto === "") {
    echo json_encode(["error" => "Escreva algo pra provocar a rede."]);
    exit;
}

// Teto próprio, menor que AI_TEXT_MAX (500, o tamanho de uma FALA): isto
// é a pergunta de um humano, não a resposta de um agente.
if (mb_strlen($texto) > 300) {
    echo json_encode(["error" => "Máximo de 300 caracteres."]);
    exit;
}

// Freio por pessoa, ANTES da moderação: moderar já gasta uma chamada de
// API, então conferir depois seria pagar pelo pedido que vai ser recusado.
$freio = ai_pode_provocar($pdo, $userId);

if (!$freio["ok"]) {
    http_response_code(429);
    echo json_encode([
        "error" => "Você já provocou a rede bastante nesta hora. Tente de novo em "
                 . login_tempo_legivel($freio["espera"]) . ".",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$motivo = ai_moderate_conteudo($texto);

if ($motivo !== null) {
    echo json_encode(["error" => "Esse texto não passou pela moderação."]);
    exit;
}

if (ai_config() === null) {
    http_response_code(503);
    echo json_encode(["error" => "IA real não está configurada neste ambiente."]);
    exit;
}

$agentesAtivos = ai_agentes($pdo);

if (!$agentesAtivos) {
    echo json_encode(["error" => "Nenhum agente disponível agora."]);
    exit;
}

$handlesValidos = array_values(array_intersect(
    array_map("strval", $handlesPedidos),
    array_keys($agentesAtivos)
));

// Escolhido a dedo (até 4, pra não estourar o teto de chamada da hora
// numa provocação só) ou sorteio de 2 a 4 entre os ativos.
if ($handlesValidos) {
    $escolhidos = array_slice($handlesValidos, 0, 4);
} else {
    $todos = array_keys($agentesAtivos);
    shuffle($todos);
    $escolhidos = array_slice($todos, 0, min(count($todos), rand(2, 4)));
}

$pdo->prepare("INSERT INTO ai_provocacoes (user_id, texto) VALUES (?, ?)")->execute([$userId, $texto]);
$provocacaoId = (int)$pdo->lastInsertId();

$respostas       = [];
$anteriores      = [];
$limiteAtingido  = false;

foreach ($escolhidos as $i => $handle) {
    if (!ai_pode_chamar_api($pdo)) {
        $limiteAtingido = true;
        break;
    }

    $agente = $agentesAtivos[$handle];
    ai_registrar_chamada_api($pdo, $userId);

    /* ------------------------------------------------------------------
       O bloquinho deste agente acende no card "Os agentes" enquanto ele
       pensa. Aqui a espera e o caso mais visivel de todos: a cadeia sao
       ate quatro chamadas de API EM SERIE, e durante os dez e poucos
       segundos que ela leva a tela mostrava so um botao desabilitado.
       Agora da pra ver a vez passando de um pro outro.

       O primeiro da fila responde a pessoa; do segundo em diante o
       agente ja tem as falas anteriores no prompt, e e sobre a ultima
       delas que ele reage de fato.
       ------------------------------------------------------------------ */
    ai_marcar_status(
        $pdo,
        (int)$agente["id"],
        "respondendo",
        $anteriores ? $anteriores[count($anteriores) - 1]["name"] : "voce"
    );

    try {
        $conteudo = ai_gerar_resposta_provocacao($agente, $texto, $anteriores);
    } finally {
        // Encerra mesmo se a geracao explodir: o proximo do laco acende o
        // dele em seguida, e dois acesos ao mesmo tempo contariam uma
        // mentira sobre uma cadeia que e serial. Com graca, porque este
        // agente de fato trabalhou -- gastou uma chamada de API.
        ai_encerrar_status($pdo, (int)$agente["id"]);
    }

    // Falha da API ou resposta que não passa na moderação: pula este
    // agente sem travar a cadeia — os outros ainda respondem.
    if ($conteudo === null || ai_moderate($conteudo) !== null) {
        continue;
    }

    $pdo->prepare(
        "INSERT INTO ai_provocacao_respostas (provocacao_id, agent_id, ordem, conteudo) VALUES (?, ?, ?, ?)"
    )->execute([$provocacaoId, $agente["id"], $i, $conteudo]);

    $item = [
        "agent"   => $agente["name"],
        "handle"  => $handle,
        "avatar"  => $agente["avatar"],
        "color"   => $agente["color"],
        "content" => $conteudo,
    ];
    $respostas[]  = $item;
    $anteriores[] = ["name" => $agente["name"], "conteudo" => $conteudo];
}

echo json_encode([
    "ok"              => true,
    "provocacao"      => ["id" => $provocacaoId, "texto" => $texto],
    "respostas"       => $respostas,
    "limite_atingido" => $limiteAtingido,
], JSON_UNESCAPED_UNICODE);
