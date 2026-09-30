<?php
/**
 * Pedido para virar "professor verificado". Qualquer usuário logado pede;
 * um admin decide depois. Grupo de estudo informal continua livre — isto é
 * só pro selo/nível verificado.
 *
 * POST { area, justificativa }
 * Resposta: { ok:true, status:"pendente" } ou { error }.
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
    $area = trim((string)($data["area"] ?? ""));
    $just = trim((string)($data["justificativa"] ?? ""));

    if (mb_strlen($area) < 2 || mb_strlen($area) > 80) {
        echo json_encode(["error" => "Diga a área que você quer ensinar (2 a 80 caracteres)."]);
        exit;
    }
    if (mb_strlen($just) < 20 || mb_strlen($just) > 2000) {
        echo json_encode(["error" => "Escreva uma justificativa de 20 a 2000 caracteres."]);
        exit;
    }

    // Já verificado não precisa pedir de novo.
    $stmt = $pdo->prepare("SELECT professor_status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() === "verificado") {
        echo json_encode(["error" => "Você já é professor verificado."]);
        exit;
    }

    // Triagem do agente (best-effort; null se a IA não estiver disponível).
    $triagem = professor_triagem($area, $just);

    // Uma solicitação por pessoa; pedir de novo reabre a mesma linha.
    $stmt = $pdo->prepare(
        "INSERT INTO professor_solicitacoes (user_id, area, justificativa, status, triagem_ia)
         VALUES (:u, :a, :j, 'pendente', :t)
         ON DUPLICATE KEY UPDATE
           area = VALUES(area), justificativa = VALUES(justificativa),
           status = 'pendente', triagem_ia = VALUES(triagem_ia),
           created_at = NOW(), decided_at = NULL, decided_by = NULL"
    );
    $stmt->execute(["u" => $userId, "a" => $area, "j" => $just, "t" => $triagem]);

    $pdo->prepare("UPDATE users SET professor_status = 'pendente' WHERE id = ?")->execute([$userId]);

    echo json_encode(["ok" => true, "status" => "pendente"], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("professor/solicitar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao enviar a solicitação."]);
}
