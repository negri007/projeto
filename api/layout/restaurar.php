<?php
/**
 * Apaga o layout por blocos da sessão para uma tela: ela volta ao layout
 * automático de sempre.
 *
 * POST JSON: { "tela": "perfil" }
 * Resposta: { ok: true, layout: null } — também se não havia layout salvo.
 * Ver docs/API_CONTRACT.md, "Layout por blocos do perfil".
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $corpo = json_decode((string)file_get_contents("php://input", false, null, 0, 1024), true);
    $tela  = is_array($corpo) ? ($corpo["tela"] ?? null) : null;

    if (!layout_tela_valida($tela)) {
        layout_erro_400("Tela desconhecida.");
    }

    layout_apagar($pdo, $userId, $tela);

    echo json_encode(["ok" => true, "layout" => null]);

} catch (Throwable $e) {
    error_log("layout/restaurar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao restaurar o layout."]);
}
