<?php
/**
 * Grava o layout por blocos da sessão para uma tela.
 *
 * POST JSON: { "tela": "perfil", "layout": { "versao": 1, "blocos": [...] } }
 * Só essas duas chaves: o dono é sempre a sessão, nunca um campo do corpo.
 *
 * Resposta: { ok: true, layout: {...normalizado...} }. Erros de entrada:
 * 400. Mais de LAYOUT_MAX_SALVAR_HORA salvamentos na hora: 429.
 * Ver docs/API_CONTRACT.md, "Layout por blocos do perfil".
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../auth/rate_limit.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $bruto = file_get_contents("php://input", false, null, 0, LAYOUT_MAX_BYTES + 1);

    if (strlen((string)$bruto) > LAYOUT_MAX_BYTES) {
        layout_erro_400("Layout grande demais.");
    }

    $corpo = json_decode((string)$bruto, true, 8);

    if (!is_array($corpo) || array_is_list($corpo)) {
        layout_erro_400("Corpo inválido: envie um objeto JSON com tela e layout.");
    }

    // Só tela e layout. Um `user_id` (ou qualquer outra chave) aqui é erro,
    // e não ignorado em silêncio: deixa claro que o dono não vem do corpo.
    $sobra = array_diff(array_keys($corpo), ["tela", "layout"]);
    if ($sobra) {
        layout_erro_400("Campo não permitido: " . layout_nome_seguro(reset($sobra)) . ". O layout é sempre o da sua sessão.");
    }

    $tela = $corpo["tela"] ?? null;

    if (!layout_tela_valida($tela)) {
        layout_erro_400("Tela desconhecida.");
    }

    $r = layout_validar($tela, $corpo["layout"] ?? null);

    if (!$r["ok"]) {
        layout_erro_400($r["erro"]);
    }

    // Freio só para quem vai gravar de fato (layout inválido não conta).
    $espera = acao_bloqueada_por($pdo, "layout_salvar", (string)$userId, LAYOUT_MAX_SALVAR_HORA);

    if ($espera > 0) {
        http_response_code(429);
        echo json_encode([
            "error" => "Muitos salvamentos de layout. Tente de novo em " . login_tempo_legivel($espera) . ".",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    acao_registrar($pdo, "layout_salvar", (string)$userId);
    layout_gravar($pdo, $userId, $tela, $r["layout"]);

    echo json_encode(["ok" => true, "layout" => $r["layout"]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("layout/salvar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar o layout."]);
}
