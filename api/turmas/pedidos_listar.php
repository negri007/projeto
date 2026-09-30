<?php
/**
 * Pedidos de entrada PENDENTES de uma turma. Só o dono.
 *
 * GET ?circle_id=<id>
 * Resposta: { ok:true, pedidos:[{ id, user_id, name, created_at }] }
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
        echo json_encode(["error" => "Só o professor vê os pedidos."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT r.id, r.user_id, u.name, r.created_at
           FROM circle_join_requests r
           JOIN users u ON u.id = r.user_id
          WHERE r.circle_id = ? AND r.status = 'pendente'
          ORDER BY r.created_at"
    );
    $stmt->execute([$circleId]);

    $pedidos = array_map(fn($p) => [
        "id"         => (int)$p["id"],
        "user_id"    => (int)$p["user_id"],
        "name"       => $p["name"],
        "created_at" => $p["created_at"],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(["ok" => true, "pedidos" => $pedidos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/pedidos_listar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar os pedidos."]);
}
