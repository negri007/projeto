<?php
/**
 * Reporta um post de loja ou a loja inteira.
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
    $input    = json_decode(file_get_contents("php://input"), true);
    $postId   = (int)($input["loja_post_id"] ?? 0) ?: null;
    $lojaId   = (int)($input["loja_id"] ?? 0) ?: null;
    $motivo   = (string)($input["motivo"] ?? "");
    $descricao = trim((string)($input["descricao"] ?? ""));

    $motivos = ["spam", "conteudo_inapropriado", "produto_falso", "golpe", "outro"];

    if (!in_array($motivo, $motivos, true) || ($postId === null && $lojaId === null)) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    /* Report de post guarda tambem a loja: quando o post for apagado, a
       chave vira NULL (ON DELETE SET NULL) e sem a loja o registro
       perderia o unico dado que permite investigar depois. */
    if ($postId !== null && $lojaId === null) {
        $stmt = $pdo->prepare("SELECT loja_id FROM loja_posts WHERE id = ?");
        $stmt->execute([$postId]);
        $achou = $stmt->fetchColumn();
        $lojaId = $achou ? (int)$achou : null;
    }

    $pdo->prepare(
        "INSERT INTO loja_reports (loja_post_id, loja_id, user_id, motivo, descricao)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$postId, $lojaId, $userId, $motivo, $descricao !== "" ? mb_substr($descricao, 0, 1000) : null]);

    // Sem painel de moderacao nesta fase (ver o plano): o report fica
    // registrado e a resposta e so a confirmacao de que foi recebido.
    echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/report: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao enviar o report."]);
}
