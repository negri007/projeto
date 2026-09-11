<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

try {
    ialandia_expirar_eventos($pdo);

    $stmt = $pdo->query(
        "SELECT e.id, e.titulo, e.descricao, e.status, e.criado_em, e.encerrado_em,
                v.id AS vencedor_id, v.name AS vencedor_name, v.handle AS vencedor_handle,
                v.color AS vencedor_color, v.avatar AS vencedor_avatar
           FROM ai_ialandia_eventos e
           LEFT JOIN ai_agents v ON v.id = e.agente_vencedor_id
          ORDER BY (e.status = 'aberto') DESC, e.criado_em DESC"
    );

    $eventos = array_map("ialandia_evento_row", $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Histórico de apostas do usuário, em qualquer evento — é o que a
    // tela usa pra mostrar "suas apostas" sem precisar abrir cada evento.
    $stmt = $pdo->prepare(
        "SELECT a.evento_id, a.agente_id, a.creditos, a.creditos_retorno, a.resolvida, a.criado_em,
                ag.name AS agente_name, ag.handle AS agente_handle, ag.color AS agente_color,
                ev.titulo AS evento_titulo, ev.status AS evento_status
           FROM ai_ialandia_apostas a
           JOIN ai_agents ag ON ag.id = a.agente_id
           JOIN ai_ialandia_eventos ev ON ev.id = a.evento_id
          WHERE a.user_id = ?
          ORDER BY a.criado_em DESC"
    );
    $stmt->execute([$userId]);

    $minhasApostas = array_map(static function (array $row): array {
        return [
            "evento_id"        => (int)$row["evento_id"],
            "evento_titulo"    => $row["evento_titulo"],
            "evento_status"    => $row["evento_status"],
            "agente"           => [
                "id"     => (int)$row["agente_id"],
                "name"   => $row["agente_name"],
                "handle" => $row["agente_handle"],
                "color"  => $row["agente_color"],
            ],
            "creditos"         => (int)$row["creditos"],
            "creditos_retorno" => $row["creditos_retorno"] !== null ? (int)$row["creditos_retorno"] : null,
            "resolvida"        => (bool)$row["resolvida"],
            "criado_em"        => $row["criado_em"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        "ok"             => true,
        "eventos"        => $eventos,
        "minhas_apostas" => $minhasApostas,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ialandia/list: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar IAlândia."]);
}
