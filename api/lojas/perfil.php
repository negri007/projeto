<?php
/**
 * Dados publicos de uma loja, por loja_id.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $lojaId = (int)($_GET["loja_id"] ?? 0);

    // Sem `loja_id`, devolve a do proprio usuario: e o que a tela de
    // edicao precisa, e evita um endpoint so para isso.
    $loja = $lojaId > 0 ? loja_por_id($pdo, $lojaId) : loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Loja não encontrada."]);
        exit;
    }

    $lojaId = (int)$loja["id"];
    $ehDono = (int)$loja["user_id"] === $userId;

    $stmt = $pdo->prepare("SELECT instrucoes, saudacao, modelo, ativo FROM loja_agente WHERE loja_id = ?");
    $stmt->execute([$lojaId]);
    $agente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM loja_produtos WHERE loja_id = :l1) AS produtos,
            (SELECT COUNT(*) FROM loja_posts WHERE loja_id = :l2 AND ativo = 1) AS posts,
            (SELECT COUNT(*) FROM loja_post_likes k JOIN loja_posts p ON p.id = k.loja_post_id
              WHERE p.loja_id = :l3) AS curtidas,
            (SELECT COUNT(*) FROM loja_post_comments c JOIN loja_posts p ON p.id = c.loja_post_id
              WHERE p.loja_id = :l4) AS comentarios,
            (SELECT COUNT(*) FROM loja_chats WHERE loja_id = :l5) AS chats"
    );
    $stmt->execute(["l1" => $lojaId, "l2" => $lojaId, "l3" => $lojaId, "l4" => $lojaId, "l5" => $lojaId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"   => true,
        "loja" => [
            "id"        => $lojaId,
            "nome"      => $loja["nome"],
            "descricao" => $loja["descricao"],
            "categoria" => $loja["categoria"],
            "telefone"  => $loja["telefone"],
            "whatsapp"  => $loja["whatsapp"],
            "site"      => $loja["site"],
            "logo"      => $loja["logo"],
            "banner"    => $loja["banner"],
            // `cnpj` so para o dono: e dado de cadastro, nao vitrine.
            "cnpj"      => $ehDono ? ($loja["cnpj"] ?? null) : null,
            "eh_dono"   => $ehDono,
        ],
        // As instrucoes do agente sao o "segredo" do atendimento e so
        // aparecem para quem as escreveu.
        "agente" => $agente ? [
            "saudacao"   => $agente["saudacao"],
            "modelo"     => $agente["modelo"],
            "ativo"      => (int)$agente["ativo"] === 1,
            "instrucoes" => $ehDono ? $agente["instrucoes"] : null,
        ] : null,
        "stats" => array_map("intval", $stats),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/perfil: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar a loja."]);
}
