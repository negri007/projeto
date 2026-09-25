<?php
/**
 * "Meus vídeos" do Comércio: as peças do motor de anúncios da loja do
 * usuário logado, das mais novas para as mais antigas, com o estado de
 * cada uma (na_fila / gerando / pronto / erro, com a posição na fila) e se
 * já virou post.
 *
 * Privado por construção: a loja vem da sessão (loja_do_usuario), não há
 * `loja_id` no pedido. É o que deixa o lojista fechar o modal enquanto o
 * vídeo renderiza — ele aparece aqui quando fica pronto.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../lojas/helpers.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    // Peça travada há mais de 10 min vira erro e libera a vaga antes de
    // responder — é por aqui que a tela para de mostrar "Gerando…" eterno.
    video_limpar_travados($pdo);

    $loja = loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Você ainda não tem loja."]);
        exit;
    }

    /* `publicado`: existe post ATIVO da loja apontando para o arquivo, no
       formato que post_criar.php grava ("video:<arquivo_local>"). */
    $stmt = $pdo->prepare(
        "SELECT vg.id, vg.modelo, vg.formato, vg.status, vg.arquivo_local, vg.erro, vg.created_at,
                " . VIDEO_SQL_POSICAO_FILA . " AS posicao_fila,
                EXISTS(SELECT 1 FROM loja_posts lp
                        WHERE lp.loja_id = vg.loja_id AND lp.ativo = 1
                          AND lp.imagem = CONCAT('video:', vg.arquivo_local)) AS publicado
           FROM videos_gerados vg
          WHERE vg.loja_id = ? AND vg.modelo IS NOT NULL
          ORDER BY vg.id DESC
          LIMIT 20"
    );
    $stmt->execute([(int)$loja["id"]]);

    $videos = array_map(fn($v) => [
        "id"         => (int)$v["id"],
        "modelo"     => $v["modelo"],
        "formato"    => $v["formato"],
        "status"     => $v["status"],
        "posicao_fila" => $v["posicao_fila"] === null ? null : (int)$v["posicao_fila"],
        "arquivo"    => $v["status"] === "pronto" ? $v["arquivo_local"] : null,
        "erro"       => $v["status"] === "erro" ? $v["erro"] : null,
        "publicado"  => (bool)$v["publicado"],
        "created_at" => $v["created_at"],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(["ok" => true, "videos" => $videos], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("video/meus: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar seus vídeos."]);
}
