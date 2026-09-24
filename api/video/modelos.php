<?php
/**
 * Catálogo do motor de anúncios para o front montar a tela: modelos e seus
 * campos editáveis, formatos e nichos. Só leitura; exige login.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/motor_catalogo.php";

require_login();
liberar_sessao();

// Achata o mapa de modelos em lista com o id junto, na ordem do catálogo.
$modelos = [];
foreach (motor_modelos() as $id => $m) {
    $modelos[] = [
        "id"     => $id,
        "nome"   => $m["nome"],
        "desc"   => $m["desc"],
        "fotos"  => count($m["foto_alvos"] ?? []),
        "auto"   => $m["auto"] ?? null,
        "campos" => $m["campos"],
    ];
}

echo json_encode([
    "ok"        => true,
    "modelos"   => $modelos,
    "formatos"  => motor_formatos(),
    "nichos"    => motor_nichos(),
], JSON_UNESCAPED_UNICODE);
