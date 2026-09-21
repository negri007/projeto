<?php
/**
 * O cliente fala, o agente da loja responde.
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

try {
    $input    = json_decode(file_get_contents("php://input"), true);
    $lojaId   = (int)($input["loja_id"] ?? 0);
    $mensagem = trim((string)($input["mensagem"] ?? ""));

    if (!$lojaId || $mensagem === "") {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $loja = loja_por_id($pdo, $lojaId);

    if ($loja === null) {
        echo json_encode(["error" => "Loja não encontrada."]);
        exit;
    }

    /* Cada mensagem gasta uma chamada de API. Sem freio por pessoa,
       alguem segurando Enter numa conversa consome a cota da hora
       inteira e cala a Rede IA para todo mundo. E o mesmo freio da
       provocacao da IAlandia. */
    $freio = ai_pode_provocar($pdo, $userId);

    if (!$freio["ok"]) {
        http_response_code(429);
        echo json_encode([
            "error" => "Você mandou muitas mensagens seguidas. Tente de novo em "
                . login_tempo_legivel((int)$freio["espera"]) . ".",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Uma conversa por par cliente/loja: quem volta continua de onde parou.
    $stmt = $pdo->prepare("SELECT id FROM loja_chats WHERE loja_id = ? AND user_id = ?");
    $stmt->execute([$lojaId, $userId]);
    $chatId = (int)$stmt->fetchColumn();

    if (!$chatId) {
        $pdo->prepare("INSERT INTO loja_chats (loja_id, user_id) VALUES (?, ?)")
            ->execute([$lojaId, $userId]);
        $chatId = (int)$pdo->lastInsertId();
    }

    $mensagem = mb_substr($mensagem, 0, 1000);

    $stmt = $pdo->prepare(
        "SELECT role, conteudo FROM loja_chat_mensagens
          WHERE chat_id = ? ORDER BY id DESC LIMIT " . LOJA_CHAT_HISTORICO
    );
    $stmt->execute([$chatId]);
    $historico = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $pdo->prepare("INSERT INTO loja_chat_mensagens (chat_id, role, conteudo) VALUES (?, 'user', ?)")
        ->execute([$chatId, $mensagem]);

    // Sempre devolve texto: quando nao da para responder, o convite para
    // o WhatsApp. Ver loja_agente_responder().
    $resposta = loja_agente_responder($pdo, $lojaId, $historico, $mensagem, $userId);

    $pdo->prepare("INSERT INTO loja_chat_mensagens (chat_id, role, conteudo) VALUES (?, 'agent', ?)")
        ->execute([$chatId, $resposta]);

    echo json_encode([
        "ok"                  => true,
        "resposta"            => $resposta,
        // A tela desenha um card com botao de adicionar ao carrinho para
        // cada produto que o agente citou.
        "produtos_mencionados" => loja_produtos_mencionados($pdo, $lojaId, $resposta),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/chat_mensagem: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao falar com a loja."]);
}
