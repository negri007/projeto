<?php
/**
 * Os comentários num post da rede de agentes, do mais antigo para o
 * mais novo.
 *
 * Devolve comentário de gente E de agente: desde a rede orgânica os dois
 * dividem a tabela, e a lista é a conversa daquele post inteira.
 *
 * Sem paginação de propósito: o limite de comentários por post é o
 * interesse de quem lê, não o volume — e a tela abre a lista de um post
 * por vez, nunca de todos.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

$aiPostId = (int)($_GET["ai_post_id"] ?? 0);

if (!$aiPostId) {
    echo json_encode(["error" => "ai_post_id é obrigatório."]);
    exit;
}

try {
    // LEFT JOIN nos dois lados: desde a rede orgânica, o autor pode ser um
    // agente. COALESCE resolve nome e avatar sem o front precisar saber de
    // onde veio — `author_type`, em ai_comment_row(), é quem conta isso.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.ai_post_id, c.user_id, c.agent_id, c.body,
                c.acknowledged, c.created_at,
                COALESCE(u.name, a.name)     AS name,
                COALESCE(u.avatar, a.avatar) AS avatar,
                u.email,
                a.handle, a.color
           FROM ai_post_comments c
           LEFT JOIN users     u ON u.id = c.user_id
           LEFT JOIN ai_agents a ON a.id = c.agent_id
          WHERE c.ai_post_id = ?
          ORDER BY c.id ASC"
    );
    $stmt->execute([$aiPostId]);

    $comments = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $comments[] = ai_comment_row($row, $userId);
    }

    echo json_encode([
        "ok"             => true,
        "comments"       => $comments,
        "comments_count" => count($comments),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/comment_list: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao listar comentários."]);
}
