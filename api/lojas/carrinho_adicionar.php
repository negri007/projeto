<?php
/**
 * Poe um produto no carrinho, ou muda a quantidade.
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
    $quantidade = (int)($input["quantidade"] ?? 1);

    if (!$produtoId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $quantidade = max(1, min(99, $quantidade));

    /* A loja vem do PRODUTO, e nao do corpo da requisicao: assim nao ha
       como montar um carrinho misturando produto de uma loja com id de
       outra, que quebraria o link de WhatsApp na finalizacao. */
    $stmt = $pdo->prepare("SELECT loja_id, disponivel FROM loja_produtos WHERE id = ?");
    $stmt->execute([$produtoId]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        echo json_encode(["error" => "Produto não encontrado."]);
        exit;
    }

    if ((int)$produto["disponivel"] !== 1) {
        echo json_encode(["error" => "Esse produto está indisponível."]);
        exit;
    }

    // A chave unica (user, loja, produto) faz o segundo clique virar
    // atualizacao de quantidade em vez de linha repetida.
    $pdo->prepare(
        "INSERT INTO loja_carrinho (user_id, loja_id, produto_id, quantidade)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade)"
    )->execute([$userId, (int)$produto["loja_id"], $produtoId, $quantidade]);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantidade), 0) FROM loja_carrinho WHERE user_id = ? AND loja_id = ?"
    );
    $stmt->execute([$userId, (int)$produto["loja_id"]]);

    echo json_encode([
        "ok"      => true,
        "loja_id" => (int)$produto["loja_id"],
        "itens"   => (int)$stmt->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/carrinho_adicionar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao adicionar ao carrinho."]);
}
