<?php
/**
 * Feed de comercio, paginado por cursor.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $limit     = max(1, min(30, (int)($_GET["limit"] ?? 10)));
    $beforeId  = (int)($_GET["before_id"] ?? 0);
    $categoria = trim((string)($_GET["categoria"] ?? ""));
    $lojaId    = (int)($_GET["loja_id"] ?? 0);

    /* Cursor e nao OFFSET, pelo mesmo motivo do feed humano: post novo no
       topo desloca as paginas e faz o item da borda repetir ou sumir. */
    $where  = ["p.ativo = 1", "l.ativo = 1"];
    $params = [];

    if ($beforeId > 0) {
        $where[]  = "p.id < ?";
        $params[] = $beforeId;
    }

    if ($categoria !== "") {
        $where[]  = "l.categoria = ?";
        $params[] = $categoria;
    }

    if ($lojaId > 0) {
        $where[]  = "p.loja_id = ?";
        $params[] = $lojaId;
    }

    $sql = "SELECT p.id, p.loja_id, p.conteudo, p.imagem, p.tipo, p.preco, p.produto_id,
                   p.created_at, l.nome AS loja_nome, l.logo AS loja_logo,
                   l.categoria AS loja_categoria,
                   (SELECT COUNT(*) FROM loja_post_likes k WHERE k.loja_post_id = p.id) AS curtidas,
                   (SELECT COUNT(*) FROM loja_post_comments c WHERE c.loja_post_id = p.id) AS comentarios,
                   (SELECT COUNT(*) FROM loja_post_likes k2 WHERE k2.loja_post_id = p.id AND k2.user_id = ?) AS eu_curti
              FROM loja_posts p
              JOIN lojas l ON l.id = p.loja_id
             WHERE " . implode(" AND ", $where) . "
             ORDER BY p.id DESC
             LIMIT " . ($limit + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId], $params));
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pede um a mais do que vai devolver: e assim que se sabe se ha
    // proxima pagina sem uma segunda consulta de contagem.
    $temMais = count($linhas) > $limit;
    $linhas  = array_slice($linhas, 0, $limit);

    $posts = [];

    foreach ($linhas as $p) {
        $posts[] = [
            "id"          => (int)$p["id"],
            "loja"        => [
                "id"        => (int)$p["loja_id"],
                "nome"      => $p["loja_nome"],
                "logo"      => $p["loja_logo"],
                "categoria" => $p["loja_categoria"],
            ],
            "conteudo"    => $p["conteudo"],
            "imagem"      => $p["imagem"],
            "tipo"        => $p["tipo"],
            "preco"       => $p["preco"] !== null ? (float)$p["preco"] : null,
            "produto_id"  => $p["produto_id"] !== null ? (int)$p["produto_id"] : null,
            "curtidas"    => (int)$p["curtidas"],
            "comentarios" => (int)$p["comentarios"],
            "eu_curti"    => (int)$p["eu_curti"] > 0,
            "created_at"  => $p["created_at"],
        ];
    }

    echo json_encode([
        "ok"             => true,
        "posts"          => $posts,
        "has_more"       => $temMais,
        "next_before_id" => $posts ? end($posts)["id"] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("lojas/feed: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o feed."]);
}
