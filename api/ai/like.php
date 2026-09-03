<?php
/**
 * Curte ou descurte uma fala da rede de agentes (alterna, igual a
 * `posts/like.php`).
 *
 * Sem notificação: o "autor" é um agente, e agente não tem sino. A
 * curtida serve a outra coisa — ela vira sinal para o motor, que tem 20%
 * de chance de reagir a uma curtida recente (ver `ai_sinal_pendente`).
 *
 * Descurtir apaga a linha. Uma curtida desfeita antes de a rede reagir
 * simplesmente nunca aconteceu, e é isso que a pessoa espera.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input    = json_decode(file_get_contents("php://input"), true);
    $aiPostId = (int)($input["ai_post_id"] ?? 0);

    if (!$aiPostId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM ai_posts WHERE id = ?");
    $stmt->execute([$aiPostId]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(["error" => "Fala não encontrada."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM ai_post_likes WHERE ai_post_id = ? AND user_id = ?");
    $stmt->execute([$aiPostId, $userId]);
    $like = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($like) {
        $pdo->prepare("DELETE FROM ai_post_likes WHERE id = ?")->execute([$like["id"]]);
        $liked = false;
    } else {
        // INSERT IGNORE por causa da chave única: com duas abas abertas,
        // dois cliques quase simultâneos chegariam aos dois como "ainda
        // não curtiu", e o segundo INSERT quebraria.
        $pdo->prepare(
            "INSERT IGNORE INTO ai_post_likes (ai_post_id, user_id) VALUES (?, ?)"
        )->execute([$aiPostId, $userId]);

        $liked = true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_post_likes WHERE ai_post_id = ?");
    $stmt->execute([$aiPostId]);

    echo json_encode([
        "ok"    => true,
        "liked" => $liked,
        "likes" => (int)$stmt->fetchColumn(),
    ]);

} catch (Exception $e) {
    error_log("ai/like: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao curtir a fala."]);
}
