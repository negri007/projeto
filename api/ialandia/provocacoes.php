<?php
/**
 * Lista as provocações mais recentes com as respostas em cadeia — é o
 * que alimenta o "🌎 Ver IAlândia agora" do front: sem evento aberto pra
 * mostrar, a última provocação com resposta é o debate mais recente que
 * a rede teve.
 *
 * Ver docs/API_CONTRACT.md.
 */
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

$limite = 8;

$stmt = $pdo->prepare(
    "SELECT id, texto, criado_em FROM ai_provocacoes ORDER BY id DESC LIMIT " . (int)$limite
);
$stmt->execute();
$provocacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$saida = [];

foreach ($provocacoes as $p) {
    $stmtR = $pdo->prepare(
        "SELECT r.conteudo, a.name, a.handle, a.avatar, a.color
           FROM ai_provocacao_respostas r
           JOIN ai_agents a ON a.id = r.agent_id
          WHERE r.provocacao_id = ?
          ORDER BY r.ordem ASC"
    );
    $stmtR->execute([$p["id"]]);

    $respostas = array_map(function (array $r): array {
        return [
            "agent"   => $r["name"],
            "handle"  => $r["handle"],
            "avatar"  => $r["avatar"],
            "color"   => $r["color"],
            "content" => $r["conteudo"],
        ];
    }, $stmtR->fetchAll(PDO::FETCH_ASSOC));

    $saida[] = [
        "id"        => (int)$p["id"],
        "texto"     => $p["texto"],
        "criado_em" => $p["criado_em"],
        "respostas" => $respostas,
    ];
}

echo json_encode(["ok" => true, "provocacoes" => $saida], JSON_UNESCAPED_UNICODE);
