<?php
/**
 * Salva as instrucoes e a saudacao do agente da loja.
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
    $loja = loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Você ainda não tem loja."]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $instrucoes = mb_substr(trim((string)($input["instrucoes"] ?? "")), 0, 6000);
    $saudacao   = mb_substr(trim((string)($input["saudacao"] ?? "")), 0, 500);
    $ativo      = !empty($input["ativo"]) ? 1 : 0;

    if ($saudacao === "") {
        $saudacao = "Olá! Como posso ajudar?";
    }

    /* REPLACE e nao UPDATE: a linha do agente nasce junto com a loja,
       mas uma loja criada antes desta feature nao teria. Assim o
       endpoint funciona nos dois casos sem um INSERT condicional. */
    $pdo->prepare(
        "REPLACE INTO loja_agente (loja_id, instrucoes, saudacao, ativo) VALUES (?, ?, ?, ?)"
    )->execute([(int)$loja["id"], $instrucoes, $saudacao, $ativo]);

    echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/agente_configurar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar o agente."]);
}
