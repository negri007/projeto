<?php
/**
 * Admin decide um reporte de material de turma. Só admin.
 * Só muda o status: não apaga material nem reporte. O que fazer com o
 * professor o admin decide fora do sistema.
 *
 * POST { reporte_id, decisao: "resolvido" | "descartado" }
 * Resposta: { ok:true, status }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../professor/helpers.php";

$userId = require_login();
liberar_sessao();

const REPORTE_DECISOES = ["resolvido", "descartado"];

function decidir_erro(int $http, string $msg): void
{
    http_response_code($http);
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    decidir_erro(405, "Método inválido.");
}

try {
    if (!professor_is_admin($pdo, $userId)) {
        decidir_erro(403, "Acesso restrito.");
    }

    $data      = json_decode(file_get_contents("php://input"), true);
    $reporteId = (int)($data["reporte_id"] ?? 0);
    $decisao   = (string)($data["decisao"] ?? "");

    if (!in_array($decisao, REPORTE_DECISOES, true)) {
        decidir_erro(400, "Decisão inválida.");
    }

    // O `status = 'pendente'` no WHERE fecha a corrida de dois admins
    // decidindo o mesmo reporte: só o primeiro UPDATE pega a linha.
    $stmt = $pdo->prepare(
        "UPDATE turma_reportes
            SET status = ?, decidido_por = ?, decidido_em = NOW()
          WHERE id = ? AND status = 'pendente'"
    );
    $stmt->execute([$decisao, $userId, $reporteId]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare("SELECT 1 FROM turma_reportes WHERE id = ?");
        $stmt->execute([$reporteId]);

        if (!$stmt->fetchColumn()) {
            decidir_erro(404, "Reporte não encontrado.");
        }
        decidir_erro(409, "Esse reporte já foi decidido.");
    }

    echo json_encode(["ok" => true, "status" => $decisao], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("admin/reporte_decidir: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erro ao decidir o reporte."], JSON_UNESCAPED_UNICODE);
}
