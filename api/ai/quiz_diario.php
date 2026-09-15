<?php
/**
 * Quiz diário — linha de comando, não é endpoint.
 * Ver api/ai/reproducao.php e docs/plans/echo-briefing-codigo.md.
 *
 *     php api/ai/quiz_diario.php             quiz COMPLETO agora: pergunta,
 *                                             respostas e reprodução
 *     php api/ai/quiz_diario.php --agendado  só a pergunta (08:00); as
 *                                             respostas e a reprodução ficam
 *                                             para os scripts das 08:05 e 09:00
 *
 * Sem argumento é o "forçar um quiz" da Seção 6: rodar à mão e ver o
 * resultado inteiro de uma vez. O agendador passa `--agendado`, pra
 * sobrar a hora entre a resposta e a reprodução em que as respostas
 * podem receber curtida.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/reproducao.php";

$rodada = quiz_iniciar($pdo);

if ($rodada === null) {
    repro_log("Nenhuma pergunta disponível pra quiz.");
    exit(1);
}

repro_log("Quiz #" . $rodada["id"] . " postado (post " . $rodada["post_id"] . "): " . $rodada["pergunta"]);

if (in_array("--agendado", $argv, true)) {
    exit(0);
}

foreach (quiz_processar_respostas($pdo, $rodada) as $r) {
    repro_log("  resposta [" . $r["source"] . "] " . $r["name"] . ": " . $r["content"]);
}

foreach (quiz_processar_reproducao($pdo, $rodada) as $f) {
    repro_log("  nasceu " . $f["name"] . " (@" . $f["handle"] . ", geração " . $f["geracao"]
        . ", " . $f["modelo"] . ") — traits " . $f["traits"]);

    foreach ($f["ciumes"] as $c) {
        repro_log("    ciúme [" . $c["source"] . "] " . $c["name"] . ": " . $c["content"]);
    }
}
