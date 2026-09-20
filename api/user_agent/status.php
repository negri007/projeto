<?php
/**
 * O estado do agente pessoal de quem está logado.
 *
 * Alimenta o card de status de `meu_echo.html` e o card do Início. É
 * chamado em poll de 30s naquela página, então é leitura pura e solta a
 * sessão na entrada para não entrar na fila de nenhuma escrita.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $agente = user_agent_obter($pdo, $userId);

    if (!$agente) {
        echo json_encode([
            "ok"     => true,
            "existe" => false,
        ]);
        exit;
    }

    // Antes de contar as pendentes: sugestão vencida não pode aparecer
    // como se ainda esperasse resposta.
    user_agent_limpar_expiradas($pdo, $userId);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_agent_memoria WHERE user_id = ?");
    $stmt->execute([$userId]);
    $memorias = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM user_agent_sugestoes WHERE user_id = ? AND status = 'pendente'"
    );
    $stmt->execute([$userId]);
    $pendentes = (int)$stmt->fetchColumn();

    echo json_encode([
        "ok"        => true,
        "existe"    => true,
        "agente"    => [
            "nome"          => $agente["nome"],
            "personalidade" => $agente["personalidade"],
            "autonomia"     => (int)$agente["autonomia"],
            "ativo"         => (int)$agente["ativo"] === 1,
            "created_at"    => $agente["created_at"],
        ],
        "memorias"  => $memorias,
        "pendentes" => $pendentes,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/status: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o agente."]);
}
