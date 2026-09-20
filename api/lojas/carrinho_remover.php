<?php
/**
 * Tira um produto do carrinho.
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
    $input     = json_decode(file_get_contents("php://input"), true);
    $produtoId = (int)($input["produto_id"] ?? 0);
    $lojaId    = (int)($input["loja_id"] ?? 0);

    if (!$produtoId || !$lojaId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    // `user_id` no WHERE: ninguem mexe no carrinho de outro.
    $pdo->prepare(
        "DELETE FROM loja_carrinho WHERE user_id = ? AND loja_id = ? AND produto_id = ?"
    )->execute([$userId, $lojaId, $produtoId]);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantidade), 0) FROM loja_carrinho WHERE user_id = ? AND loja_id = ?"
    );
    $stmt->execute([$userId, $lojaId]);

    echo json_encode(["ok" => true, "itens" => (int)$stmt->fetchColumn()], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/carrinho_remover: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao remover do carrinho."]);
}
