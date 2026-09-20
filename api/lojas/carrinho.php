<?php
/**
 * Os itens do carrinho do usuario naquela loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $lojaId = (int)($_GET["loja_id"] ?? 0);

    if (!$lojaId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT c.produto_id, c.quantidade, p.nome, p.preco, p.imagem, p.disponivel
           FROM loja_carrinho c
           JOIN loja_produtos p ON p.id = c.produto_id
          WHERE c.user_id = ? AND c.loja_id = ?
          ORDER BY c.id ASC"
    );
    $stmt->execute([$userId, $lojaId]);

    $itens = [];
    $total = 0.0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $preco = $i["preco"] !== null ? (float)$i["preco"] : null;
        $qtd   = (int)$i["quantidade"];

        if ($preco !== null) {
            $total += $preco * $qtd;
        }

        $itens[] = [
            "produto_id" => (int)$i["produto_id"],
            "nome"       => $i["nome"],
            "preco"      => $preco,
            "imagem"     => $i["imagem"],
            "quantidade" => $qtd,
            // O produto pode ter saido de linha depois de entrar no
            // carrinho; a tela precisa avisar em vez de deixar finalizar.
            "disponivel" => (int)$i["disponivel"] === 1,
        ];
    }

    echo json_encode([
        "ok"    => true,
        "itens" => $itens,
        "total" => round($total, 2),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/carrinho: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o carrinho."]);
}
