<?php
/**
 * O professor aprova ou recusa um pedido de entrada. Só o dono da turma do
 * pedido. Aprovar adiciona o aluno como membro.
 *
 * POST { request_id, aprovar: bool }
 * Resposta: { ok:true, aprovado:bool }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $data      = json_decode(file_get_contents("php://input"), true);
    $requestId = (int)($data["request_id"] ?? 0);
    $aprovar   = !empty($data["aprovar"]);

    // Carrega o pedido e a turma dele; o acesso é conferido pela turma.
    $stmt = $pdo->prepare("SELECT id, circle_id, user_id, status FROM circle_join_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo json_encode(["error" => "Pedido não encontrado."]);
        exit;
    }

    $turma = turma_load_for_user($pdo, (int)$pedido["circle_id"], $userId);

    if ($turma === null || !$turma["is_owner"]) {
        echo json_encode(["error" => "Só o professor decide os pedidos."]);
        exit;
    }

    if ($pedido["status"] !== "pendente") {
        echo json_encode(["error" => "Esse pedido já foi decidido."]);
        exit;
    }

    if ($aprovar) {
        turma_add_membro($pdo, (int)$turma["id"], (int)$turma["owner_id"], (int)$pedido["user_id"]);
    }

    $stmt = $pdo->prepare(
        "UPDATE circle_join_requests SET status = ?, decided_at = NOW() WHERE id = ?"
    );
    $stmt->execute([$aprovar ? "aprovado" : "recusado", $requestId]);

    echo json_encode(["ok" => true, "aprovado" => $aprovar], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/pedido_decidir: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao decidir o pedido."]);
}
