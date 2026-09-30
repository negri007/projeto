<?php
/**
 * Aluno entra numa turma digitando o código de convite (forma B).
 * Identidade sempre da sessão; o código é o único "segredo".
 *
 * POST { codigo }
 * Resposta: { ok:true, circle:{ id, name }, estado:"novo"|"ja_membro" }
 *           ou { error }.
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
    $codigo = (string)($data["codigo"] ?? "");

    $turma = turma_por_codigo($pdo, $codigo);

    if ($turma === null) {
        echo json_encode(["error" => "Código inválido. Confira com o professor."]);
        exit;
    }

    $circleId = (int)$turma["id"];
    $ownerId  = (int)$turma["owner_id"];

    // Se a turma exige aprovação, o código cria um PEDIDO (forma C); senão,
    // entra na hora (forma B).
    if (!empty($turma["aceita_pedidos"])) {
        $estado = turma_criar_pedido($pdo, $circleId, $ownerId, $userId);

        if ($estado === "dono") {
            echo json_encode(["error" => "Você é o professor desta turma."]);
            exit;
        }

        // Membro entra direto na turma (não vira "pedido" à toa).
        echo json_encode([
            "ok"     => true,
            "estado" => $estado, // "pendente" | "ja_membro"
            "circle" => ["id" => $circleId, "name" => $turma["name"]],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $estado = turma_add_membro($pdo, $circleId, $ownerId, $userId);

    if ($estado === "dono") {
        echo json_encode(["error" => "Você é o professor desta turma."]);
        exit;
    }

    echo json_encode([
        "ok"     => true,
        "estado" => $estado, // "novo" | "ja_membro"
        "circle" => ["id" => $circleId, "name" => $turma["name"]],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/entrar_por_codigo: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao entrar na turma."]);
}
