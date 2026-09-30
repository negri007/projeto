<?php
/**
 * Admin aprova ou recusa uma solicitação de professor. Só admin.
 * Aprovar dá o selo (users.professor_status='verificado'); recusar marca
 * 'recusado' (a pessoa pode pedir de novo, que reabre como pendente).
 *
 * POST { solicitacao_id, aprovar: bool }
 * Resposta: { ok:true, aprovado:bool }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../professor/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    if (!professor_is_admin($pdo, $userId)) {
        http_response_code(403);
        echo json_encode(["error" => "Acesso restrito."]);
        exit;
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $solId   = (int)($data["solicitacao_id"] ?? 0);
    $aprovar = !empty($data["aprovar"]);

    $stmt = $pdo->prepare("SELECT id, user_id, status FROM professor_solicitacoes WHERE id = ?");
    $stmt->execute([$solId]);
    $sol = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sol) {
        echo json_encode(["error" => "Solicitação não encontrada."]);
        exit;
    }
    if ($sol["status"] !== "pendente") {
        echo json_encode(["error" => "Essa solicitação já foi decidida."]);
        exit;
    }

    $novo = $aprovar ? "aprovado" : "recusado";
    $pStatus = $aprovar ? "verificado" : "recusado";

    $stmt = $pdo->prepare(
        "UPDATE professor_solicitacoes SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?"
    );
    $stmt->execute([$novo, $userId, $solId]);

    $pdo->prepare("UPDATE users SET professor_status = ? WHERE id = ?")
        ->execute([$pStatus, (int)$sol["user_id"]]);

    echo json_encode(["ok" => true, "aprovado" => $aprovar], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("admin/solicitacao_decidir: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao decidir a solicitação."]);
}
