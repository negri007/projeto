<?php
/**
 * 10:00 — filhote com 30 dias passa pra Sonnet e pode reproduzir.
 * Linha de comando, não é endpoint. Ver api/ai/reproducao.php.
 *
 *     php api/ai/check_maturacao.php
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/reproducao.php";

$maduros = check_agentes_maturing($pdo);

repro_log(count($maduros) . " agente(s) amadureceram.");

foreach ($maduros as $a) {
    repro_log("  " . $a["name"]);
}
