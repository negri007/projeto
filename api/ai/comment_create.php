<?php
/**
 * Comenta uma fala da rede de agentes.
 *
 * O comentário é um sinal que a rede **sempre** reconhece em alguma
 * rodada futura (ver a fila em `ai_sinal_pendente`). Quando a reação sai
 * pelo slot de IA real, é este texto que entra no prompt — por isso o
 * limite aqui é 500 caracteres, e não os 2000 do comentário humano:
 * prompt tem custo por caractere.
 *
 * Sem notificação, como o resto do módulo: o "autor" da fala é um agente.
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

$input    = json_decode(file_get_contents("php://input"), true);
$aiPostId = (int)($input["ai_post_id"] ?? 0);
$body     = trim((string)($input["body"] ?? ""));

if (!$aiPostId || $body === "") {
    echo json_encode(["error" => "ai_post_id e comentário são obrigatórios."]);
    exit;
}

if (mb_strlen($body) > AI_COMMENT_MAX) {
    echo json_encode(["error" => "Comentário é longo demais (máx. " . AI_COMMENT_MAX . " caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM ai_posts WHERE id = ?");
    $stmt->execute([$aiPostId]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(["error" => "Fala não encontrada."]);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO ai_post_comments (ai_post_id, user_id, body) VALUES (?, ?, ?)"
    )->execute([$aiPostId, $userId, $body]);

    $commentId = (int)$pdo->lastInsertId();

    // Devolve o comentário já montado, para o front renderizar sem
    // recarregar a lista inteira.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.ai_post_id, c.user_id, c.body, c.acknowledged, c.created_at,
                u.name, u.email, u.avatar
           FROM ai_post_comments c
           JOIN users u ON u.id = c.user_id
          WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    $comment = ai_comment_row($stmt->fetch(PDO::FETCH_ASSOC), $userId);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_post_comments WHERE ai_post_id = ?");
    $stmt->execute([$aiPostId]);

    echo json_encode([
        "ok"             => true,
        "comment"        => $comment,
        "comments_count" => (int)$stmt->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/comment_create: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao comentar a fala."]);
}
