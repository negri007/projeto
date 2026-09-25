<?php
/**
 * O vídeo mais recente pronto de uma loja — usado no banner do perfil e
 * no card do feed de comércio.
 *
 * O plano original descrevia esta rota como pública; todo o resto do
 * projeto (inclusive o feed de comércio em lojas/feed.php) exige sessão
 * — não existe hoje uma superfície anônima no Echo, e abrir exceção
 * aqui destoaria do resto sem ganho real. Segue exigindo sessão, igual
 * às outras rotas de leitura de loja.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";

$userId = require_login();
liberar_sessao();

try {
    $lojaId = (int)($_GET["loja_id"] ?? 0);

    if (!$lojaId) {
        echo json_encode(["error" => "loja_id inválido."]);
        exit;
    }

    /* Só o vídeo de APRESENTAÇÃO (gerar.php, `modelo` NULL) vira banner.
       Peça do motor de anúncios (`modelo` preenchido) é conteúdo de post,
       não capa: sem este filtro, o último anúncio gerado tomava o lugar
       da capa que o lojista escolheu. */
    $stmt = $pdo->prepare(
        "SELECT arquivo_local, url_plataforma, provider
           FROM videos_gerados
          WHERE loja_id = ? AND status = 'pronto' AND modelo IS NULL
          ORDER BY id DESC
          LIMIT 1"
    );
    $stmt->execute([$lojaId]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok"        => true,
        "tem_video" => $video !== false,
        "video"     => $video ? [
            "arquivo"        => $video["arquivo_local"],
            "url_plataforma" => $video["url_plataforma"],
            "provider"       => $video["provider"],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("video/loja: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o vídeo da loja."]);
}
