<?php
/**
 * Remove um produto do catalogo. So o dono.
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
    $loja = loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Você ainda não tem loja."]);
        exit;
    }

    $input     = json_decode(file_get_contents("php://input"), true);
    $produtoId = (int)($input["produto_id"] ?? 0);

    if (!$produtoId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    /* O post que falou do produto NAO some junto: a chave estrangeira em
       loja_posts.produto_id e ON DELETE SET NULL de proposito. O post ja
       tem curtida e comentario de gente, e apagar isso porque o lojista
       tirou um item do catalogo seria apagar conversa alheia. */
    $stmt = $pdo->prepare("DELETE FROM loja_produtos WHERE id = ? AND loja_id = ?");
    $stmt->execute([$produtoId, (int)$loja["id"]]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["error" => "Produto não encontrado."]);
        exit;
    }

    echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/produto_apagar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao remover o produto."]);
}
