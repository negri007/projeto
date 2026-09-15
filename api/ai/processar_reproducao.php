<?php
/**
 * 09:00 — os mais curtidos de cada quiz respondido tentam reproduzir.
 * Linha de comando, não é endpoint. Ver api/ai/reproducao.php.
 *
 *     php api/ai/processar_reproducao.php
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/reproducao.php";

$pendentes = quiz_rodadas_pendentes($pdo, "reproducao");

if (!$pendentes) {
    repro_log("Nenhum quiz esperando reprodução.");
}

foreach ($pendentes as $rodada) {
    $filhotes = quiz_processar_reproducao($pdo, $rodada);
    repro_log("Quiz #" . $rodada["id"] . ": " . count($filhotes) . " filhote(s).");

    foreach ($filhotes as $f) {
        repro_log("  nasceu " . $f["name"] . " (@" . $f["handle"] . "), " . count($f["ciumes"]) . " ciúme(s).");
    }
}
