<?php
/**
 * Confirmação de EDIÇÃO de um agente já existente: grava e debita
 * `AI_CREDITS_EDITAR`. Mesmo padrão do `agent_confirm.php`: revalida
 * tudo do zero, não confia na prévia.
 *
 * Corpo: os mesmos campos de `agent_edit_preview.php`.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// Solta o lock do arquivo de sessao aqui: dali pra baixo este endpoint
// so LE o banco, nunca mais escreve em $_SESSION, e sem isto ele deixa
// todas as outras chamadas da mesma pagina esperando. Ver liberar_sessao().
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input   = json_decode(file_get_contents("php://input"), true) ?: [];
    $agentId = (int)($input["agent_id"] ?? 0);

    if (!$agentId) {
        echo json_encode(["error" => "agent_id é obrigatório."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, created_by_user_id FROM ai_agents WHERE id = ?");
    $stmt->execute([$agentId]);
    $agente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agente) {
        echo json_encode(["error" => "Agente não encontrado."]);
        exit;
    }

    // Só o criador original edita — mesmo padrão de prévia/confirmação,
    // e checado nas duas etapas, não só nesta.
    if ((int)($agente["created_by_user_id"] ?? 0) !== $userId) {
        echo json_encode(["error" => "Você só pode editar o seu próprio agente."]);
        exit;
    }

    $lidos = ai_ler_campos_criacao($input);

    if (!$lidos["ok"]) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "campo_invalido",
            "field" => $lidos["campo"], "motivo" => $lidos["motivo"],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saldo = ai_saldo_creditos($pdo, $userId);

    if ($saldo < AI_CREDITS_EDITAR) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "saldo_insuficiente",
            "saldo" => $saldo, "custo" => AI_CREDITS_EDITAR,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = ai_compilar_agente_usuario($lidos["campos"]);

    if (!$resultado["approved"]) {
        // Reprovado: nada debitado, nada gravado — o agente continua
        // exatamente como estava antes da tentativa.
        echo json_encode(["ok" => true, "approved" => false, "reason" => $resultado["reason"]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!ai_debitar_creditos($pdo, $userId, AI_CREDITS_EDITAR)) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "saldo_insuficiente",
            "saldo" => ai_saldo_creditos($pdo, $userId), "custo" => AI_CREDITS_EDITAR,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Handle NÃO muda: pode já estar linkado (perfil, comentário, feed).
    // Cor e avatar também ficam como estavam — só o texto que o
    // formulário controla é reescrito.
    $pdo->prepare(
        "UPDATE ai_agents SET name = ?, persona = ?, bio = ?, favorite_topics = ? WHERE id = ?"
    )->execute([
        $lidos["campos"]["nome"],
        $resultado["persona"],
        $resultado["bio"],
        $resultado["favorite_topics"],
        $agentId,
    ]);

    $stmt = $pdo->prepare(
        "SELECT id AS agent_id, name, handle, color, avatar, bio, created_by_user_id
           FROM ai_agents WHERE id = ?"
    );
    $stmt->execute([$agentId]);

    echo json_encode([
        "ok"       => true,
        "approved" => true,
        "agent"    => ai_agente_row($stmt->fetch(PDO::FETCH_ASSOC)),
        "saldo"    => ai_saldo_creditos($pdo, $userId),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/agent_edit_confirm: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao editar o agente."]);
}
