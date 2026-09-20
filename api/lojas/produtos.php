<?php
/**
 * Catalogo de uma loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $lojaId = (int)($_GET["loja_id"] ?? 0);
    $loja   = $lojaId > 0 ? loja_por_id($pdo, $lojaId) : loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Loja não encontrada."]);
        exit;
    }

    $ehDono = (int)$loja["user_id"] === $userId;

    /* O dono ve tambem os indisponiveis, porque e ele quem os
       administra; o cliente so ve o que da para comprar. */
    $sql = "SELECT id, nome, descricao, preco, imagem, disponivel, ordem
              FROM loja_produtos WHERE loja_id = ?"
        . ($ehDono ? "" : " AND disponivel = 1")
        . " ORDER BY ordem ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$loja["id"]]);

    $produtos = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $produtos[] = [
            "id"         => (int)$p["id"],
            "nome"       => $p["nome"],
            "descricao"  => $p["descricao"],
            "preco"      => $p["preco"] !== null ? (float)$p["preco"] : null,
            "imagem"     => $p["imagem"],
            "disponivel" => (int)$p["disponivel"] === 1,
            "ordem"      => (int)$p["ordem"],
        ];
    }

    echo json_encode([
        "ok"       => true,
        "loja_id"  => (int)$loja["id"],
        "eh_dono"  => $ehDono,
        "produtos" => $produtos,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/produtos: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar os produtos."]);
}
