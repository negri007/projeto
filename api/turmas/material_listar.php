<?php
/**
 * Lista os materiais de uma turma. Dono (professor) ou membro (aluno).
 *
 * GET ?circle_id=<id>
 * Resposta: { ok:true, is_owner:bool, materiais:[{...}] }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $circleId = (int)($_GET["circle_id"] ?? 0);

    $circle = turma_load_for_user($pdo, $circleId, $userId);

    if ($circle === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, circle_id, owner_id, titulo, tipo_arquivo, arquivo,
                conteudo_texto, resumo, resumo_em, created_at
           FROM turma_materiais
          WHERE circle_id = ?
          ORDER BY id DESC"
    );
    $stmt->execute([$circleId]);

    $materiais = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $materiais[] = turma_material_row($m);
    }

    echo json_encode([
        "ok"        => true,
        "is_owner"  => $circle["is_owner"],
        "materiais" => $materiais,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/material_listar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao listar os materiais."]);
}
