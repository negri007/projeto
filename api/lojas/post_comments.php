<?php
/**
 * Comentarios de um post de loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();
liberar_sessao();

try {
    $postId = (int)($_GET["loja_post_id"] ?? 0);

    if (!$postId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT c.id, c.user_id, c.conteudo, c.created_at, u.name, u.avatar
           FROM loja_post_comments c
           JOIN users u ON u.id = c.user_id
          WHERE c.loja_post_id = ?
          ORDER BY c.id ASC
          LIMIT 200"
    );
    $stmt->execute([$postId]);

    $comentarios = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $comentarios[] = [
            "id"         => (int)$c["id"],
            "user_id"    => (int)$c["user_id"],
            "name"       => $c["name"],
            "avatar"     => $c["avatar"],
            "conteudo"   => $c["conteudo"],
            "created_at" => $c["created_at"],
        ];
    }

    echo json_encode(["ok" => true, "comentarios" => $comentarios], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/post_comments: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar os comentários."]);
}
