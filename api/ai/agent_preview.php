<?php
/**
 * Prévia da criação de um agente: nunca grava nada, nunca debita crédito.
 *
 * O front reenvia os mesmos quatro campos em `agent_confirm.php`, que
 * revalida tudo do zero — este endpoint existe só para a pessoa ver como
 * o agente ficaria antes de gastar os créditos.
 *
 * Corpo: { "nome": "...", "personalidade": "...", "assuntos": "..."?,
 *          "bio": "..."? }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    $lidos = ai_ler_campos_criacao($input);

    if (!$lidos["ok"]) {
        echo json_encode([
            "ok"       => true,
            "approved" => false,
            "reason"   => "campo_invalido",
            "field"    => $lidos["campo"],
            "motivo"   => $lidos["motivo"],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = ai_compilar_agente_usuario($lidos["campos"]);

    if (!$resultado["approved"]) {
        echo json_encode([
            "ok"       => true,
            "approved" => false,
            "reason"   => $resultado["reason"],
        ], JSON_UNESCAPED_UNICODE);
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
        "custo" => AI_CREDITS_CRIAR,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/agent_preview: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar a prévia."]);
}
