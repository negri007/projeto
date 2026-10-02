<?php
/**
 * Entrega o arquivo de um material. Dono (professor) ou membro (aluno).
 *
 * GET ?material_id=<id>
 * Sucesso: o próprio arquivo (não JSON). Erro: JSON {"error": "..."}.
 *
 * Existe porque servir de uploads/ direto deixava o material de aula
 * público: o controle de acesso só escondia o caminho, e o nome
 * (uniqid, relógio) com o circle_id sequencial dava para adivinhar.
 * Agora `uploads/turmas/` é negada no .htaccess da raiz e o arquivo só sai
 * por aqui, com a turma conferida pela sessão a cada pedido.
 *
 * Não registra abertura: quem registra é material_abrir.php, que a tela
 * chama antes de mostrar o link.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

/** Tipo servido por extensão gravada. Texto sai como text/plain de
 *  propósito: .md servido como markdown/html não tem por que existir. */
const TURMA_ARQUIVO_TIPOS = [
    "pdf"  => "application/pdf",
    "txt"  => "text/plain; charset=utf-8",
    "md"   => "text/plain; charset=utf-8",
    "jpg"  => "image/jpeg",
    "png"  => "image/png",
    "webp" => "image/webp",
];

function material_arquivo_nao_achado(): void
{
    http_response_code(404);
    echo json_encode(["error" => "Material não encontrado."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $materialId = (int)($_GET["material_id"] ?? 0);

    // Dono e membros pela turma; admin só se o material tiver reporte
    // (é como ele verifica o conteúdo sem entrar na turma).
    $material = turma_material_load($pdo, $materialId, $userId)
             ?? turma_material_load_admin_reportado($pdo, $materialId, $userId);

    if ($material === null) {
        material_arquivo_nao_achado();
    }

    $caminho = turma_material_caminho($material);
    $tipo    = TURMA_ARQUIVO_TIPOS[strtolower(pathinfo((string)$caminho, PATHINFO_EXTENSION))] ?? null;

    if ($caminho === null || $tipo === null) {
        material_arquivo_nao_achado();
    }

    // Nome amigável para quem salvar: o título, sem nada que quebre o
    // cabeçalho. A versão UTF-8 vai em filename*.
    $ext     = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $titulo  = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', " ", (string)$material["titulo"])) ?: "material";
    $ascii   = preg_replace('/[^A-Za-z0-9 ._-]+/', "_", $titulo);

    // O buffer do bootstrap existe para trocar a resposta por um erro
    // JSON; aqui a resposta é o arquivo, que pode ter 20 MB — segurá-lo
    // inteiro na memória não serve para nada.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: " . $tipo);
    header("Content-Length: " . filesize($caminho));
    header("Content-Disposition: inline; filename=\"" . $ascii . "." . $ext . "\"; filename*=UTF-8''"
        . rawurlencode($titulo . "." . $ext));
    header("Cache-Control: private, no-store");

    readfile($caminho);
    exit;

} catch (Throwable $e) {
    error_log("turmas/material_arquivo: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erro ao abrir o arquivo."], JSON_UNESCAPED_UNICODE);
}
