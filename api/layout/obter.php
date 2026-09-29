<?php
/**
 * Layout por blocos de uma tela. Sessão obrigatória.
 *
 * GET ?tela=perfil[&user_id=N]
 * Sem user_id, o layout da sessão; com, o do perfil daquela pessoa (é o
 * que a tela de perfil alheio precisa para se desenhar).
 *
 * Resposta: { ok, tela, user_id, editavel, layout: null | {...} }.
 * Ver docs/API_CONTRACT.md, "Layout por blocos do perfil".
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $tela = $_GET["tela"] ?? null;

    if (!layout_tela_valida($tela)) {
        layout_erro_400("Tela desconhecida.");
    }

    // O dono do layout pedido: a sessão, ou o user_id da URL. Ler o layout
    // de outra pessoa é só leitura — o perfil dela já é visível.
    $donoId = isset($_GET["user_id"]) ? (int)$_GET["user_id"] : $userId;

    if ($donoId <= 0) {
        layout_erro_400("Usuário inválido.");
    }

    echo json_encode([
        "ok"       => true,
        "tela"     => $tela,
        "user_id"  => $donoId,
        "editavel" => $donoId === $userId,
        "layout"   => layout_ler($pdo, $donoId, $tela),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("layout/obter: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o layout."]);
}
