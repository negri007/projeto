<?php
/**
 * O agente propõe uma resposta para uma mensagem que a pessoa recebeu.
 *
 * A diferença para gerar_sugestao_post.php é quem aperta o botão: lá é a
 * pessoa, aqui é a chegada de uma mensagem no chat. Isso muda o
 * tratamento de erro inteiro.
 *
 * Quem pediu espera resposta, e merece saber quando não deu. Quem não
 * pediu nada não pode ser interrompido por um aviso de cota no meio de
 * uma conversa com outra pessoa. Por isso aqui nada vira HTTP 429 nem
 * toast: quando não dá, a tela simplesmente não mostra sugestão.
 *
 * A sugestão NÃO é enviada. Ela aparece embaixo do campo de texto, e
 * quem decide mandar é a pessoa, pelo mesmo messages/send.php de sempre.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ai/limite_uso.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$body     = json_decode(file_get_contents("php://input"), true) ?: [];
$mensagem = trim((string)($body["mensagem"] ?? ""));

/* Mensagem de chat cabe em pouco, e o que interessa para imitar o jeito
   de responder é o fim dela, não um textão colado. Cortar aqui também
   segura o custo do prompt. */
if (function_exists("mb_substr")) {
    $mensagem = mb_substr($mensagem, 0, 600);
} else {
    $mensagem = substr($mensagem, 0, 600);
}

try {
    /* O freio por pessoa vale aqui também: sem ele, uma conversa animada
       gastaria a cota da hora inteira sozinha e calaria a Rede IA para
       todo mundo. Fechado o freio, a resposta é "não gerou" e ponto — a
       pessoa não pediu isto, então não tem por que receber um aviso. */
    $freio = ai_pode_provocar($pdo, $userId);

    if (!$freio["ok"]) {
        echo json_encode([
            "ok"     => true,
            "gerou"  => false,
            "codigo" => "sem_cota",
            "motivo" => "Seu Echo já sugeriu bastante coisa nesta hora.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r     = user_agent_gerar_sugestao_resposta($pdo, $userId, $mensagem);
    $texto = $r["texto"];

    if ($texto === null) {
        echo json_encode([
            "ok"     => true,
            "gerou"  => false,
            "codigo" => $r["motivo"],
            "motivo" => "Sem sugestão agora.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // O texto saiu de um modelo e vai sair assinado por uma pessoa, numa
    // conversa privada. Mesma moderação das falas da Rede IA.
    $recusa = ai_moderate($texto);

    if ($recusa !== null) {
        error_log("user_agent/gerar_sugestao_resposta moderação recusou ($recusa)");
        echo json_encode([
            "ok"     => true,
            "gerou"  => false,
            "codigo" => "moderacao",
            "motivo" => "Sem sugestão agora.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* A sugestão fica guardada como as outras: se a pessoa sair do chat
       sem decidir, ela continua na aba do Echo em vez de sumir. */
    $id = user_agent_guardar_sugestao($pdo, $userId, "resposta", $texto);

    echo json_encode([
        "ok"       => true,
        "gerou"    => true,
        "sugestao" => [
            "id"       => $id,
            "tipo"     => "resposta",
            "sugestao" => $texto,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/gerar_sugestao_resposta: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar a sugestão."]);
}
