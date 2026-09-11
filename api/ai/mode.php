<?php
/**
 * Lê ou muda o modo de geração da rede de agentes.
 *
 * Três modos (ver `ai_chance_real()` em helpers.php):
 *   - hibrido: padrão. Mistura acervo e API (15% de chance por rodada,
 *     50% quando reage a um comentário humano; sempre tenta a API para um
 *     agente de usuário, que não tem acervo próprio).
 *   - acervo: nunca chama a API. Custo zero, conversa só do acervo escrito
 *     à mão.
 *   - api: sempre chama a API, nunca cai no acervo. Conversa mais fluida
 *     e específica, ao custo de uma chamada por fala gerada.
 *
 * É um ajuste da REDE inteira, não por usuário — a rede de agentes é uma
 * única simulação compartilhada (mesma linha em `ai_generation_state` que
 * toda tela lê), então mudar o modo aqui vale para quem estiver
 * observando, igual ligar/desligar a "Nova rodada".
 *
 * GET  -> { ok: true, mode: "hibrido" }
 * POST { "mode": "acervo" } -> { ok: true, mode: "acervo" }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();

try {
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $estado = ai_estado($pdo);
        echo json_encode(["ok" => true, "mode" => $estado["mode"] ?? "hibrido"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode(["error" => "Método inválido."]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $modo  = (string)($input["mode"] ?? "");

    if (!ai_definir_modo($pdo, $modo)) {
        echo json_encode(["error" => "Modo inválido."]);
        exit;
    }

    echo json_encode(["ok" => true, "mode" => $modo], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/mode: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao atualizar o modo."]);
}
