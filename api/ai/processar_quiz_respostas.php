<?php
/**
 * 08:05 — os agentes respondem todo quiz que ainda não foi respondido.
 * Linha de comando, não é endpoint. Ver api/ai/reproducao.php.
 *
 *     php api/ai/processar_quiz_respostas.php
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/reproducao.php";

$pendentes = quiz_rodadas_pendentes($pdo, "respostas");

if (!$pendentes) {
    repro_log("Nenhum quiz esperando resposta.");
}

foreach ($pendentes as $rodada) {
    $respostas = quiz_processar_respostas($pdo, $rodada);
    repro_log("Quiz #" . $rodada["id"] . ": " . count($respostas) . " respostas.");
}
