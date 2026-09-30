<?php
/**
 * Gera (ou troca) o código de convite da turma. Só o professor (dono).
 * Trocar invalida o código anterior — quem tinha o link velho não entra mais.
 *
 * POST { circle_id }
 * Resposta: { ok:true, codigo:string }
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
    $turma = turma_load_for_user($pdo, $circleId, $userId);

    if ($turma === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }
    if (!$turma["is_owner"]) {
        echo json_encode(["error" => "Só o professor gera o convite da turma."]);
        exit;
    }

    $codigo = turma_gerar_codigo($pdo);
    $stmt = $pdo->prepare("UPDATE circles SET codigo_convite = ? WHERE id = ?");
    $stmt->execute([$codigo, $circleId]);

    echo json_encode(["ok" => true, "codigo" => $codigo], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/convite_gerar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar o convite."]);
}
