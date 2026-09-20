<?php
/**
 * Edita os dados da loja. So o dono.
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
    /* A loja vem da SESSAO, nunca de um `loja_id` no corpo. E a diferenca
       entre "edito a minha" e "edito a de quem eu quiser". */
    $loja = loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Você ainda não tem loja."]);
        exit;
    }

    $nome     = trim((string)($_POST["nome"] ?? $loja["nome"]));
    $whatsapp = trim((string)($_POST["whatsapp"] ?? $loja["whatsapp"]));

    if ($nome === "") {
        echo json_encode(["error" => "Dê um nome à sua loja."]);
        exit;
    }

    if (loja_whatsapp_digitos($whatsapp) === null) {
        echo json_encode(["error" => "Informe um WhatsApp válido, com DDD."]);
        exit;
    }

    $logo   = $loja["logo"];
    $banner = $loja["banner"];

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

    $pdo->prepare(
        "UPDATE lojas SET nome = ?, descricao = ?, categoria = ?, cnpj = ?,
                telefone = ?, whatsapp = ?, site = ?, logo = ?, banner = ?
          WHERE id = ? AND user_id = ?"
    )->execute([
        mb_substr($nome, 0, 150),
        mb_substr(trim((string)($_POST["descricao"] ?? $loja["descricao"])), 0, 2000),
        mb_substr(trim((string)($_POST["categoria"] ?? $loja["categoria"])), 0, 100),
        mb_substr(trim((string)($_POST["cnpj"] ?? $loja["cnpj"])), 0, 18) ?: null,
        mb_substr(trim((string)($_POST["telefone"] ?? $loja["telefone"])), 0, 20) ?: null,
        mb_substr($whatsapp, 0, 20),
        mb_substr(trim((string)($_POST["site"] ?? $loja["site"])), 0, 255) ?: null,
        $logo,
        $banner,
        (int)$loja["id"],
        $userId,
    ]);

    echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/atualizar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao atualizar a loja."]);
}
