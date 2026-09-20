<?php
/**
 * Monta o pedido, gera o link de WhatsApp e esvazia o carrinho.
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
    $input  = json_decode(file_get_contents("php://input"), true);
    $lojaId = (int)($input["loja_id"] ?? 0);

    if (!$lojaId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $loja = loja_por_id($pdo, $lojaId);

    if ($loja === null) {
        echo json_encode(["error" => "Loja não encontrada."]);
        exit;
    }

    /* Os precos saem do BANCO na hora de fechar, nunca do que o cliente
       mandou. O total tambem e recalculado aqui: a tela recalcula em JS
       para responder na hora ao +/-, mas o numero que vai para o lojista
       tem de sair da mesma fonte que o catalogo. */
    $stmt = $pdo->prepare(
        "SELECT c.quantidade, p.nome, p.preco
           FROM loja_carrinho c
           JOIN loja_produtos p ON p.id = c.produto_id
          WHERE c.user_id = ? AND c.loja_id = ?
          ORDER BY c.id ASC"
    );
    $stmt->execute([$userId, $lojaId]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$linhas) {
        echo json_encode(["error" => "Seu carrinho está vazio."]);
        exit;
    }

    $itens = [];
    $total = 0.0;

    foreach ($linhas as $l) {
        $preco = $l["preco"] !== null ? (float)$l["preco"] : null;
        $qtd   = (int)$l["quantidade"];

        if ($preco !== null) {
            $total += $preco * $qtd;
        }

        $itens[] = ["nome" => $l["nome"], "preco" => $preco, "quantidade" => $qtd];
    }

    $link = loja_gerar_link_whatsapp($loja["whatsapp"], $itens);

    if ($link === null) {
        echo json_encode([
            "error" => "Esta loja não tem WhatsApp cadastrado. Fale com ela pelo chat.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* O carrinho e esvaziado so DEPOIS de o link existir. Limpar antes
       deixaria o cliente sem carrinho e sem pedido se a geracao do link
       falhasse. */
    $pdo->prepare("DELETE FROM loja_carrinho WHERE user_id = ? AND loja_id = ?")
        ->execute([$userId, $lojaId]);

    echo json_encode([
        "ok"    => true,
        "link"  => $link,
        "total" => round($total, 2),
        "itens" => count($itens),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/carrinho_finalizar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao finalizar o pedido."]);
}
