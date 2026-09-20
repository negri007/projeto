<?php
/**
 * O lojista publica no feed de comercio.
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

    $conteudo = trim((string)($_POST["conteudo"] ?? ""));
    $tipo     = (string)($_POST["tipo"] ?? "produto");

    if ($conteudo === "") {
        echo json_encode(["error" => "Escreva alguma coisa."]);
        exit;
    }

    if (!in_array($tipo, ["produto", "promocao", "novidade", "info"], true)) {
        $tipo = "produto";
    }

    $precoBruto = trim((string)($_POST["preco"] ?? ""));
    $preco      = $precoBruto === "" ? null : (float)str_replace(",", ".", $precoBruto);

    if ($preco !== null && $preco < 0) {
        echo json_encode(["error" => "Preço inválido."]);
        exit;
    }

    /* Produto ligado tem de ser DESTA loja: sem esta checagem, um lojista
       poderia pendurar o post dele no produto de outro. */
    $produtoId = (int)($_POST["produto_id"] ?? 0) ?: null;

    if ($produtoId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM loja_produtos WHERE id = ? AND loja_id = ?");
        $stmt->execute([$produtoId, (int)$loja["id"]]);

        if (!$stmt->fetchColumn()) {
            $produtoId = null;
        }
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
        "INSERT INTO loja_posts (loja_id, conteudo, imagem, tipo, preco, produto_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int)$loja["id"],
        mb_substr($conteudo, 0, 3000),
        $imagem,
        $tipo,
        $preco,
        $produtoId,
    ]);

    echo json_encode(["ok" => true, "post_id" => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/post_criar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao publicar."]);
}
