<?php
/**
 * O aluno reporta um material do professor que está fora do tema da turma.
 * O reporte vai para os admins (users.is_admin), que decidem fora do
 * sistema. Sem IA: é reporte humano (ver `origem` em banco.sql).
 *
 * POST { material_id, motivo? }
 * Resposta: { ok:true }
 *
 * Ordem das recusas (contrato): 404 material inexistente; 400 é o próprio
 * professor; 403 não é membro; 400 motivo longo; 429 muitos reportes na
 * última hora; 409 já reportou. O dono vem ANTES do "é membro" porque o
 * professor não está em circle_members -- na ordem inversa ele levaria 403
 * em vez do aviso certo.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../notifications/helpers.php";

$userId = require_login();
liberar_sessao();

/** Reportes por aluno por hora: um aluno irritado não enche a fila do admin. */
const REPORTE_MAX_POR_HORA = 5;
const REPORTE_MOTIVO_MAX   = 500;

function reportar_erro(int $http, string $msg): void
{
    http_response_code($http);
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reportar_erro(405, "Método inválido.");
}

try {
    $data       = json_decode(file_get_contents("php://input"), true);
    $materialId = (int)($data["material_id"] ?? 0);
    $motivo     = trim((string)($data["motivo"] ?? ""));

    $stmt = $pdo->prepare("SELECT id, circle_id, owner_id FROM turma_materiais WHERE id = ?");
    $stmt->execute([$materialId]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$material) {
        reportar_erro(404, "Material não encontrado.");
    }

    $circleId    = (int)$material["circle_id"];
    $professorId = (int)$material["owner_id"];

    if ($professorId === $userId) {
        reportar_erro(400, "Você não pode reportar o próprio material.");
    }

    if (!turma_ja_membro($pdo, $circleId, $userId)) {
        reportar_erro(403, "Só alunos da turma podem reportar este material.");
    }

    if (mb_strlen($motivo) > REPORTE_MOTIVO_MAX) {
        reportar_erro(400, "O motivo pode ter no máximo " . REPORTE_MOTIVO_MAX . " caracteres.");
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM turma_reportes
          WHERE reporter_id = ? AND created_at > NOW() - INTERVAL 1 HOUR"
    );
    $stmt->execute([$userId]);

    if ((int)$stmt->fetchColumn() >= REPORTE_MAX_POR_HORA) {
        reportar_erro(429, "Você fez muitos reportes seguidos. Tente de novo mais tarde.");
    }

    // A chave única (material_id, reporter_id) é quem barra o duplicado:
    // um SELECT antes não fecha a corrida de dois cliques simultâneos.
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO turma_reportes (material_id, circle_id, professor_id, reporter_id, motivo, origem)
             VALUES (?, ?, ?, ?, ?, 'aluno')"
        );
        $stmt->execute([$materialId, $circleId, $professorId, $userId, $motivo === "" ? null : $motivo]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            reportar_erro(409, "Você já reportou este material.");
        }
        throw $e;
    }

    $reporteId = (int)$pdo->lastInsertId();

    // Todos os admins; notify() já ignora quando o admin é o próprio autor.
    $admins = $pdo->query("SELECT id FROM users WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($admins as $adminId) {
        notify($pdo, (int)$adminId, $userId, "turma_reporte", $reporteId);
    }

    echo json_encode(["ok" => true]);

} catch (Throwable $e) {
    error_log("turmas/material_reportar: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erro ao enviar o reporte."], JSON_UNESCAPED_UNICODE);
}
