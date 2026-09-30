<?php
/**
 * Liga/desliga "exigir aprovação para entrar" numa turma. Só o dono.
 * Com aprovação ligada, o código de convite cria um PEDIDO (forma C) em vez
 * de admitir na hora (forma B).
 *
 * POST { circle_id, aceita_pedidos: bool }
 * Resposta: { ok:true, aceita_pedidos:bool }
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
    $data = json_decode(file_get_contents("php://input"), true);
    $circleId = (int)($data["circle_id"] ?? 0);
    $aceita   = !empty($data["aceita_pedidos"]) ? 1 : 0;

    $turma = turma_load_for_user($pdo, $circleId, $userId);

    if ($turma === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }
    if (!$turma["is_owner"]) {
        echo json_encode(["error" => "Só o professor muda isso."]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE circles SET aceita_pedidos = ? WHERE id = ?");
    $stmt->execute([$aceita, $circleId]);

    echo json_encode(["ok" => true, "aceita_pedidos" => (bool)$aceita], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/entrada_config: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar a configuração."]);
}
