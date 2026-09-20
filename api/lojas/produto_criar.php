<?php
/**
 * Acrescenta um produto ao catalogo. So o dono.
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

    $nome = trim((string)($_POST["nome"] ?? ""));

    if ($nome === "") {
        echo json_encode(["error" => "Dê um nome ao produto."]);
        exit;
    }

    /* Preco em branco e legitimo ("sob consulta"), preco negativo nao.
       Vazio vira null; texto que nao e numero tambem, em vez de virar
       zero silenciosamente. */
    $precoBruto = trim((string)($_POST["preco"] ?? ""));
    $preco      = $precoBruto === "" ? null : (float)str_replace(",", ".", $precoBruto);

    if ($preco !== null && $preco < 0) {
        echo json_encode(["error" => "Preço inválido."]);
        exit;
    }

    $imagem = null;

    if (!empty($_FILES["imagem"]["name"])) {
        try {
            $imagem = posts_store_image($_FILES["imagem"]);
        } catch (RuntimeException $e) {
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO loja_produtos (loja_id, nome, descricao, preco, imagem, disponivel, ordem)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int)$loja["id"],
        mb_substr($nome, 0, 200),
        mb_substr(trim((string)($_POST["descricao"] ?? "")), 0, 2000),
        $preco,
        $imagem,
        isset($_POST["disponivel"]) && $_POST["disponivel"] === "0" ? 0 : 1,
        (int)($_POST["ordem"] ?? 0),
    ]);

    echo json_encode(["ok" => true, "produto_id" => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/produto_criar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar o produto."]);
}
