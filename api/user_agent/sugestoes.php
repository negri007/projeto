<?php
/**
 * As sugestões que o agente fez e ainda esperam decisão do dono.
 *
 * Só as pendentes, e só as de quem pediu: não existe parâmetro de usuário.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    // Vencidas saem da lista antes de ela ser montada.
    user_agent_limpar_expiradas($pdo, $userId);

    $stmt = $pdo->prepare(
        "SELECT id, tipo, contexto, sugestao, referencia_id, created_at, expires_at,
                TIMESTAMPDIFF(MINUTE, NOW(), expires_at) AS expira_em_min
           FROM user_agent_sugestoes
          WHERE user_id = ? AND status = 'pendente'
          ORDER BY id DESC
          LIMIT 50"
    );
    $stmt->execute([$userId]);

    $sugestoes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sugestoes[] = [
            "id"            => (int)$row["id"],
            "tipo"          => $row["tipo"],
            "contexto"      => $row["contexto"],
            "sugestao"      => $row["sugestao"],
            "referencia_id" => $row["referencia_id"] !== null ? (int)$row["referencia_id"] : null,
            "created_at"    => $row["created_at"],
            // Em minutos, e não a data crua: a tela quer dizer "expira em
            // 3h", e fazer essa conta no cliente esbarraria no relógio do
            // navegador, que não é o do servidor.
            "expira_em_min" => max(0, (int)$row["expira_em_min"]),
        ];
    }

    echo json_encode([
        "ok"        => true,
        "sugestoes" => $sugestoes,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/sugestoes: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar as sugestões."]);
}
