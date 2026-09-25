<?php
/**
 * Limpeza da fila de vídeo — para agendar (cron / Agendador de Tarefas).
 *
 *     php api/video/limpar_travados.php
 *
 * Marca 'erro' o que está em 'gerando' há mais de 10 min (encerrando os
 * processos órfãos do render) e dispara o próximo 'na_fila' se houver
 * vaga. O mesmo já roda sozinho a cada consulta de status.php / meus.php;
 * agendar só garante que a fila anda mesmo sem ninguém com a tela aberta.
 *
 * Só CLI: 404 fora dela, mesma guarda de processar.php.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$marcados = video_limpar_travados($pdo);
video_fila_despachar($pdo);

$fila = (int)$pdo->query("SELECT COUNT(*) FROM videos_gerados WHERE status = 'na_fila'")->fetchColumn();
$rodando = (int)$pdo->query("SELECT COUNT(*) FROM videos_gerados WHERE status = 'gerando' AND modelo IS NOT NULL")->fetchColumn();

echo json_encode([
    "ok"                => true,
    "marcados_erro"     => $marcados,
    "renders_rodando"   => $rodando,
    "na_fila"           => $fila,
    "max_renders"       => video_max_renders(),
]) . "\n";
