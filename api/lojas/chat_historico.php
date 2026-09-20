<?php
/**
 * A conversa anterior do cliente com o agente da loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $lojaId = (int)($_GET["loja_id"] ?? 0);
    $loja   = loja_por_id($pdo, $lojaId);

    if ($loja === null) {
        echo json_encode(["error" => "Loja não encontrada."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT saudacao FROM loja_agente WHERE loja_id = ?");
    $stmt->execute([$lojaId]);
    $saudacao = $stmt->fetchColumn() ?: "Olá! Como posso ajudar?";

    $stmt = $pdo->prepare("SELECT id FROM loja_chats WHERE loja_id = ? AND user_id = ?");
    $stmt->execute([$lojaId, $userId]);
    $chatId = $stmt->fetchColumn();

    $mensagens = [];

    if ($chatId) {
        $stmt = $pdo->prepare(
            "SELECT id, role, conteudo, created_at FROM loja_chat_mensagens
              WHERE chat_id = ? ORDER BY id ASC LIMIT 200"
        );
        $stmt->execute([$chatId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mensagens[] = [
                "id"         => (int)$m["id"],
                "role"       => $m["role"],
                "conteudo"   => $m["conteudo"],
                "created_at" => $m["created_at"],
            ];
        }
    }

    echo json_encode([
        "ok"   => true,
        "loja" => [
            "id"       => (int)$loja["id"],
            "nome"     => $loja["nome"],
            "logo"     => $loja["logo"],
            "whatsapp" => $loja["whatsapp"],
        ],
        /* A saudacao vai SEPARADA das mensagens, e nao gravada como a
           primeira delas. Duas razoes: o lojista pode mudar a saudacao
           depois, e quem ja conversou veria a antiga congelada no topo
           para sempre; e a saudacao nao e resposta a nada, entao ela nao
           deve entrar no historico que vai para o prompt. */
        "saudacao"  => $saudacao,
        "mensagens" => $mensagens,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/chat_historico: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar a conversa."]);
}
