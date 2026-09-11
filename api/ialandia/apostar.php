<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data      = json_decode(file_get_contents("php://input"), true);
$eventoId  = (int)($data["evento_id"] ?? 0);
$agenteId  = (int)($data["agente_id"] ?? 0);
$creditos  = (int)($data["creditos"] ?? 0);

if (!$eventoId || !$agenteId) {
    echo json_encode(["error" => "evento_id e agente_id são obrigatórios."]);
    exit;
}

if ($creditos < IALANDIA_APOSTA_MIN || $creditos > IALANDIA_APOSTA_MAX) {
    echo json_encode(["error" => "Aposta precisa ser entre " . IALANDIA_APOSTA_MIN . " e " . IALANDIA_APOSTA_MAX . " créditos."]);
    exit;
}

try {
    // Fecha primeiro: apostar num evento que já devia ter encerrado (só
    // ninguém tinha lido ainda) não pode acontecer.
    ialandia_expirar_eventos($pdo);

    $stmt = $pdo->prepare("SELECT status FROM ai_ialandia_eventos WHERE id = ?");
    $stmt->execute([$eventoId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        echo json_encode(["error" => "Evento não encontrado."]);
        exit;
    }

    if ($status !== "aberto") {
        echo json_encode(["error" => "Este evento já foi encerrado."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM ai_agents WHERE id = ? AND active = 1");
    $stmt->execute([$agenteId]);

    if (!$stmt->fetchColumn()) {
        echo json_encode(["error" => "Agente inválido."]);
        exit;
    }

    $pdo->beginTransaction();

    // Débito condicional: só desconta se o saldo aguenta, atômico — é o
    // que torna duas apostas simultâneas seguras sem trava explícita.
    $debito = $pdo->prepare(
        "UPDATE users SET ai_credits = ai_credits - ? WHERE id = ? AND ai_credits >= ?"
    );
    $debito->execute([$creditos, $userId, $creditos]);

    if ($debito->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(["error" => "Créditos insuficientes."]);
        exit;
    }

    try {
        $pdo->prepare(
            "INSERT INTO ai_ialandia_apostas (evento_id, user_id, agente_id, creditos)
             VALUES (?, ?, ?, ?)"
        )->execute([$eventoId, $userId, $agenteId, $creditos]);
    } catch (PDOException $e) {
        // uniq_ialandia_aposta: uma aposta por usuário por evento. O
        // rollback desfaz o débito de crédito também — sem isso, a
        // segunda tentativa perderia crédito sem gravar aposta nenhuma.
        $pdo->rollBack();

        if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), "uniq_ialandia_aposta")) {
            echo json_encode(["error" => "Você já apostou nesse evento."]);
        } else {
            throw $e;
        }
        exit;
    }

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT ai_credits FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $saldo = (int)$stmt->fetchColumn();

    echo json_encode([
        "ok"     => true,
        "aposta" => ["evento_id" => $eventoId, "agente_id" => $agenteId, "creditos" => $creditos],
        "saldo"  => $saldo,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ialandia/apostar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao registrar a aposta."]);
}
