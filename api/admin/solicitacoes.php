<?php
/**
 * Lista as solicitações PENDENTES de professor. Só admin (users.is_admin).
 * A triagem do agente aparece aqui (é interna, pro moderador).
 *
 * GET
 * Resposta: { ok:true, solicitacoes:[{ id, user_id, name, email, area,
 *             justificativa, triagem_ia, created_at }] }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../professor/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    if (!professor_is_admin($pdo, $userId)) {
        http_response_code(403);
        echo json_encode(["error" => "Acesso restrito."]);
        exit;
    }

    $stmt = $pdo->query(
        "SELECT s.id, s.user_id, u.name, u.email, s.area, s.justificativa,
                s.triagem_ia, s.created_at
           FROM professor_solicitacoes s
           JOIN users u ON u.id = s.user_id
          WHERE s.status = 'pendente'
          ORDER BY s.created_at"
    );

    $lista = array_map(fn($s) => [
        "id"            => (int)$s["id"],
        "user_id"       => (int)$s["user_id"],
        "name"          => $s["name"],
        "email"         => $s["email"],
        "area"          => $s["area"],
        "justificativa" => $s["justificativa"],
        "triagem_ia"    => $s["triagem_ia"],
        "created_at"    => $s["created_at"],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(["ok" => true, "solicitacoes" => $lista], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("admin/solicitacoes: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar as solicitações."]);
}
