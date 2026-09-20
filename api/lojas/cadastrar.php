<?php
/**
 * Cria a loja de quem esta logado. Uma por usuario.
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

/* Multipart e nao JSON: o cadastro ja aceita logo e banner, e mandar
   imagem por JSON exigiria base64 -- 33% mais bytes e um caminho de
   upload diferente do resto do projeto. */
try {
    if (loja_do_usuario($pdo, $userId) !== null) {
        echo json_encode(["error" => "Você já tem uma loja."]);
        exit;
    }

    $nome      = trim((string)($_POST["nome"] ?? ""));
    $categoria = trim((string)($_POST["categoria"] ?? ""));
    $descricao = trim((string)($_POST["descricao"] ?? ""));
    $whatsapp  = trim((string)($_POST["whatsapp"] ?? ""));
    $telefone  = trim((string)($_POST["telefone"] ?? ""));
    $site      = trim((string)($_POST["site"] ?? ""));
    $cnpj      = trim((string)($_POST["cnpj"] ?? ""));

    if ($nome === "") {
        echo json_encode(["error" => "Dê um nome à sua loja."]);
        exit;
    }

    /* O WhatsApp e obrigatorio porque e o unico jeito de a compra
       terminar: o carrinho nao cobra nada, ele monta o pedido e manda
       para la. Loja sem WhatsApp vira vitrine sem caixa. */
    if (loja_whatsapp_digitos($whatsapp) === null) {
        echo json_encode(["error" => "Informe um WhatsApp válido, com DDD."]);
        exit;
    }

    $logo   = null;
    $banner = null;

    // Mesma validacao por MIME real do upload de post e de avatar.
    foreach (["logo" => &$logo, "banner" => &$banner] as $campo => &$destino) {
        if (!empty($_FILES[$campo]["name"])) {
            try {
                $destino = posts_store_image($_FILES[$campo]);
            } catch (RuntimeException $e) {
                echo json_encode(["error" => $e->getMessage()]);
                exit;
            }
        }
    }
    unset($destino);

    $stmt = $pdo->prepare(
        "INSERT INTO lojas (user_id, nome, descricao, categoria, cnpj, telefone, whatsapp, site, logo, banner)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $userId,
        mb_substr($nome, 0, 150),
        mb_substr($descricao, 0, 2000),
        mb_substr($categoria, 0, 100),
        $cnpj !== "" ? mb_substr($cnpj, 0, 18) : null,
        $telefone !== "" ? mb_substr($telefone, 0, 20) : null,
        mb_substr($whatsapp, 0, 20),
        $site !== "" ? mb_substr($site, 0, 255) : null,
        $logo,
        $banner,
    ]);

    $lojaId = (int)$pdo->lastInsertId();

    // O agente nasce junto: loja sem agente nao atende ninguem, e deixar
    // a criacao para um segundo passo criaria loja quebrada pela metade.
    $pdo->prepare(
        "INSERT INTO loja_agente (loja_id, instrucoes, saudacao) VALUES (?, ?, ?)"
    )->execute([
        $lojaId,
        mb_substr(trim((string)($_POST["instrucoes"] ?? "")), 0, 6000),
        mb_substr(trim((string)($_POST["saudacao"] ?? "")) ?: "Olá! Como posso ajudar?", 0, 500),
    ]);

    echo json_encode(["ok" => true, "loja_id" => $lojaId], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/cadastrar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao cadastrar a loja."]);
}
