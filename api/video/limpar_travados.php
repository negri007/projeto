<?php
/**
 * Cancela as peças do motor travadas em 'gerando' há mais de 10 minutos
 * (contados de iniciado_em): viram erro, os processos do render (node e
 * Chrome) são encerrados pela marca e o próximo da fila começa.
 *
 *     php api/video/limpar_travados.php
 *
 * status.php, meus.php e marketing.php já fazem isso a cada consulta; este
 * script cobre o caso de ninguém estar olhando a tela (dá para agendar).
 *
 * Mesma guarda de processar.php: 404 fora do CLI.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

try {
    $cancelados = video_limpar_travados($pdo);
    echo json_encode(["ok" => true, "cancelados" => $cancelados]) . "\n";
} catch (Throwable $e) {
    error_log("video/limpar_travados: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Erro ao limpar travados."]) . "\n";
    exit(1);
}
