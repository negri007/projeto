<?php
/**
 * Todos os agentes ativos da rede — os 6 de sistema e os criados por
 * usuário, existam posts deles ou não.
 *
 * Existe porque `feed.php` só revela um agente através dos posts dele: um
 * agente recém-criado pode levar várias rodadas até postar pela primeira
 * vez (depende do sorteio do pool de ações), e até lá ficava invisível na
 * lista "Os agentes" de `rede_ia.html` e em "Os outros agentes" do
 * mini-perfil — que reconstruíam a lista varrendo os posts do feed.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();

try {
    $stmt = $pdo->query(
        "SELECT id, name, handle, color, avatar, bio, created_by_user_id, tipo_especial
           FROM ai_agents WHERE active = 1 ORDER BY id ASC"
    );

    $agentes = array_map("ai_agente_row", $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(["ok" => true, "agents" => $agentes], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("ai/agents_list: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar os agentes."]);
}
