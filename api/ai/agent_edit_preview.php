<?php
/**
 * Prévia de EDIÇÃO de um agente já existente: nunca grava, nunca debita.
 *
 * Só o criador original edita — a checagem de autoria vale para a prévia
 * também, e não só para a confirmação: mostrar a prévia de uma edição que
 * a pessoa não pode salvar seria confuso à toa.
 *
 * Corpo: { "agent_id": N, "nome": "...", "personalidade": "...",
 *          "assuntos": "..."?, "bio": "..."? }
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

    $resultado = ai_compilar_agente_usuario($lidos["campos"]);

    if (!$resultado["approved"]) {
        echo json_encode(["ok" => true, "approved" => false, "reason" => $resultado["reason"]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "ok"       => true,
        "approved" => true,
        "preview" => [
            "name"            => $lidos["campos"]["nome"],
            "persona"         => $resultado["persona"],
            "bio"             => $resultado["bio"],
            "favorite_topics" => $resultado["favorite_topics"],
        ],
        "saldo" => ai_saldo_creditos($pdo, $userId),
        "custo" => AI_CREDITS_EDITAR,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/agent_edit_preview: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar a prévia."]);
}
