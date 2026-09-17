<?php
/**
 * Confirmação da criação de um agente: grava e debita créditos.
 *
 * Não confia em nada que veio da prévia — revalida os quatro campos e
 * roda a compilação via API de novo. É a única forma de garantir que a
 * decisão de aprovar e o texto salvo vêm do mesmo julgamento; um id de
 * prévia guardado em algum estado do servidor poderia ficar velho se a
 * pessoa editasse o texto entre uma chamada e outra.
 *
 * Corpo: os mesmos quatro campos de `agent_preview.php`.
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
    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    $lidos = ai_ler_campos_criacao($input);

    if (!$lidos["ok"]) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "campo_invalido",
            "field" => $lidos["campo"], "motivo" => $lidos["motivo"],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Checagem de saldo ANTES de chamar a API: sem isso, todo pedido sem
    // crédito ainda pagaria o custo de uma chamada. Não é a garantia
    // final — essa vem do UPDATE condicional lá embaixo, que fecha a
    // corrida entre duas confirmações simultâneas.
    $saldo = ai_saldo_creditos($pdo, $userId);

    if ($saldo < AI_CREDITS_CRIAR) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "saldo_insuficiente",
            "saldo" => $saldo, "custo" => AI_CREDITS_CRIAR,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = ai_compilar_agente_usuario($lidos["campos"]);

    if (!$resultado["approved"]) {
        // Reprovado: nada debitado, nada gravado.
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => $resultado["reason"],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // O débito é a linha que decide se o resto acontece: UPDATE
    // condicional, sem trava explícita. Se o saldo mudou entre o check
    // de cima e aqui (duas confirmações da mesma pessoa ao mesmo tempo),
    // a segunda simplesmente não acha linha com saldo suficiente.
    if (!ai_debitar_creditos($pdo, $userId, AI_CREDITS_CRIAR)) {
        echo json_encode([
            "ok" => true, "approved" => false, "reason" => "saldo_insuficiente",
            "saldo" => ai_saldo_creditos($pdo, $userId), "custo" => AI_CREDITS_CRIAR,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $handle = ai_gerar_handle_unico($pdo, $lidos["campos"]["nome"]);
    // Cor determinística a partir do handle: dois agentes de usuários
    // diferentes não colidem por acaso a cada rodada, e o mesmo agente
    // sempre tem a mesma cor entre uma tela e outra.
    $paleta = ['#e0245e', '#17bf63', '#794bc4', '#f45d22', '#0f9b8e', '#c026d3', '#eab308', '#2563eb'];
    $cor    = $paleta[crc32($handle) % count($paleta)];

    $stmt = $pdo->prepare(
        "INSERT INTO ai_agents (name, handle, persona, bio, color, favorite_topics, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $lidos["campos"]["nome"],
        $handle,
        $resultado["persona"],
        $resultado["bio"],
        $cor,
        $resultado["favorite_topics"],
        $userId,
    ]);

    $agentId = (int)$pdo->lastInsertId();

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
    error_log("ai/agent_confirm: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao criar o agente."]);
}
