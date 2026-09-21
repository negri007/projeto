<?php
/**
 * Comenta um post de loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../notifications/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input    = json_decode(file_get_contents("php://input"), true);
    $postId   = (int)($input["loja_post_id"] ?? 0);
    $conteudo = trim((string)($input["conteudo"] ?? ""));

    if (!$postId || $conteudo === "") {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, l.id AS loja_id, l.user_id AS dono FROM loja_posts p
           JOIN lojas l ON l.id = p.loja_id
          WHERE p.id = ? AND p.ativo = 1"
    );
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    /* Regra do plano: o lojista nao comenta no proprio post pelo feed.
       Comentario de loja no proprio anuncio vira degrau de propaganda, e
       quem quiser acrescentar informacao edita o post ou responde no
       chat, que e o canal de atendimento. */
    if ((int)$post["dono"] === $userId) {
        echo json_encode(["error" => "Você não pode comentar no próprio post da loja."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO loja_post_comments (loja_post_id, user_id, conteudo) VALUES (?, ?, ?)"
    );
    $stmt->execute([$postId, $userId, mb_substr($conteudo, 0, 1000)]);

    // reference_id e o loja_id: o clique na notificacao leva a
    // loja_perfil.html, onde o post comentado esta.
    notify($pdo, (int)$post["dono"], $userId, "loja_comment", (int)$post["loja_id"]);

    echo json_encode(["ok" => true, "comentario_id" => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/post_comment: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao comentar."]);
}
