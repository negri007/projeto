<?php
/**
 * Estado de entrada de uma turma, para o PROFESSOR: o código de convite
 * atual (ou null) e se a turma aceita pedidos. Só o dono.
 *
 * GET ?circle_id=<id>
 * Resposta: { ok:true, codigo:string|null, aceita_pedidos:bool }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $circleId = (int)($_GET["circle_id"] ?? 0);
    $turma = turma_load_for_user($pdo, $circleId, $userId);

    if ($turma === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }
    if (!$turma["is_owner"]) {
        echo json_encode(["error" => "Só o professor vê o convite da turma."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT codigo_convite, aceita_pedidos FROM circles WHERE id = ?");
    $stmt->execute([$circleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"             => true,
        "codigo"         => ($row["codigo_convite"] ?? "") !== "" ? $row["codigo_convite"] : null,
        "aceita_pedidos" => (bool)($row["aceita_pedidos"] ?? 0),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/convite_ver: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao ler o convite."]);
}
