<?php
/**
 * Edita um produto. So o dono da loja dele.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_once __DIR__ . "/../posts/helpers.php";

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

    $produtoId = (int)($_POST["produto_id"] ?? 0);

    /* `loja_id = ?` no WHERE e o que impede editar produto de outra loja
       chutando id. Conferir o dono numa consulta a parte deixaria uma
       janela entre a checagem e a escrita. */
    $stmt = $pdo->prepare("SELECT * FROM loja_produtos WHERE id = ? AND loja_id = ?");
    $stmt->execute([$produtoId, (int)$loja["id"]]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        echo json_encode(["error" => "Produto não encontrado."]);
        exit;
    }

    $nome = trim((string)($_POST["nome"] ?? $produto["nome"]));

    if ($nome === "") {
        echo json_encode(["error" => "Dê um nome ao produto."]);
        exit;
    }

    $precoBruto = $_POST["preco"] ?? null;

    if ($precoBruto === null) {
        $preco = $produto["preco"] !== null ? (float)$produto["preco"] : null;
    } else {
        $precoBruto = trim((string)$precoBruto);
        $preco      = $precoBruto === "" ? null : (float)str_replace(",", ".", $precoBruto);
    }

    if ($preco !== null && $preco < 0) {
        echo json_encode(["error" => "Preço inválido."]);
        exit;
    }

    $imagem = $produto["imagem"];

    if (!empty($_FILES["imagem"]["name"])) {
        try {
            $imagem = posts_store_image($_FILES["imagem"]);
        } catch (RuntimeException $e) {
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }

    $disponivel = array_key_exists("disponivel", $_POST)
        ? ($_POST["disponivel"] === "0" ? 0 : 1)
        : (int)$produto["disponivel"];

    $pdo->prepare(
        "UPDATE loja_produtos SET nome = ?, descricao = ?, preco = ?, imagem = ?,
                disponivel = ?, ordem = ?
          WHERE id = ? AND loja_id = ?"
    )->execute([
        mb_substr($nome, 0, 200),
        mb_substr(trim((string)($_POST["descricao"] ?? $produto["descricao"])), 0, 2000),
        $preco,
        $imagem,
        $disponivel,
        (int)($_POST["ordem"] ?? $produto["ordem"]),
        $produtoId,
        (int)$loja["id"],
    ]);

    echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/produto_editar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao editar o produto."]);
}
