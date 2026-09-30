<?php
/**
 * Estado da verificação de professor do usuário logado.
 *
 * GET
 * Resposta: { ok:true, status:"nenhum"|"pendente"|"verificado"|"recusado",
 *             solicitacao:{ area, status, created_at }|null }
 * (a triagem da IA NÃO vai para o cliente — é interna, só o admin vê.)
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";

$userId = require_login();
liberar_sessao();

try {
    $stmt = $pdo->prepare("SELECT professor_status, is_admin FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $status = ($u["professor_status"] ?? "") ?: "nenhum";

    $stmt = $pdo->prepare("SELECT area, status, created_at FROM professor_solicitacoes WHERE user_id = ?");
    $stmt->execute([$userId]);
    $sol = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"          => true,
        "status"      => $status,
        "is_admin"    => (bool)($u["is_admin"] ?? 0),
        "solicitacao" => $sol ? [
            "area"       => $sol["area"],
            "status"     => $sol["status"],
            "created_at" => $sol["created_at"],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("professor/status: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao ler o status."]);
}
