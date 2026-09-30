<?php
/**
 * Helpers da verificação de professor (Fase 3). Não é endpoint.
 *
 * Segurança (CLAUDE.md): identidade sempre da sessão; quem aprova é um
 * admin (users.is_admin), conferido no servidor a cada chamada — nunca por
 * dado do cliente.
 */

/** O usuário é admin da plataforma? (users.is_admin) */
function professor_is_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Triagem opcional da solicitação pelo agente (IA): lê área + justificativa
 * e devolve uma linha curta de avaliação para AJUDAR o admin — nunca decide
 * sozinho. Best-effort: sem chave/config ou em erro, devolve null e o fluxo
 * segue (o admin decide na mão).
 */
function professor_triagem(string $area, string $justificativa): ?string
{
    if (!function_exists("ai_config")) {
        $f = __DIR__ . "/../ai/helpers.php";
        if (!is_file($f)) {
            return null;
        }
        require_once $f;
    }
    if (!function_exists("ai_config") || !function_exists("ai_api_mensagens")) {
        return null;
    }

    $config = ai_config();
    if ($config === null) {
        return null;
    }

    $sys = "Você avalia pedidos para virar 'professor verificado' numa rede social educacional. "
        . "Não decide nada — só dá ao moderador humano uma leitura curta e honesta. Em UMA frase "
        . "(máx. 30 palavras), diga se o pedido parece legítimo e coerente (área + justificativa) ou "
        . "se soa vago, genérico, brincadeira ou abuso. Comece com [OK], [DÚVIDA] ou [SUSPEITO].";

    $texto = "Área: " . mb_substr($area, 0, 80) . "\nJustificativa: " . mb_substr($justificativa, 0, 1500);

    try {
        $r = ai_api_mensagens([
            "model"      => $config["model"],
            "max_tokens" => 120,
            "system"     => $sys,
            "messages"   => [["role" => "user", "content" => $texto]],
        ], [
            "timeout" => max(20, (int)($config["timeout"] ?? 15)),
            "rotulo"  => "professor_triagem",
        ]);
    } catch (Throwable $e) {
        return null;
    }

    if (empty($r["ok"]) || trim((string)($r["texto"] ?? "")) === "") {
        return null;
    }

    return mb_substr(trim($r["texto"]), 0, 400);
}
