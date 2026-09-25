<?php
/**
 * Estado de UM vídeo — o front faz poll aqui a cada 3s enquanto
 * `status` for 'na_fila' ou 'gerando'. `posicao_fila` (1 = próximo) só
 * vem preenchida com 'na_fila'.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    // Peça travada há mais de 10 min vira erro e libera a vaga antes de
    // responder — é por aqui que a tela para de mostrar "Gerando…" eterno.
    video_limpar_travados($pdo);

    $videoId = (int)($_GET["video_id"] ?? 0);

    if (!$videoId) {
        echo json_encode(["error" => "video_id inválido."]);
        exit;
    }

    // O vídeo só existe para quem é dono da loja dele — join com lojas
    // em vez de aceitar loja_id do cliente para conferir posse.
    $stmt = $pdo->prepare(
        "SELECT vg.id, vg.status, vg.arquivo_local, vg.url_plataforma, vg.provider, vg.erro,
                " . VIDEO_SQL_POSICAO_FILA . " AS posicao_fila
           FROM videos_gerados vg
           JOIN lojas l ON l.id = vg.loja_id
          WHERE vg.id = ? AND l.user_id = ?"
    );
    $stmt->execute([$videoId, $userId]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$video) {
        echo json_encode(["error" => "Vídeo não encontrado."]);
        exit;
    }

    echo json_encode([
        "ok"             => true,
        "video_id"       => (int)$video["id"],
        "status"         => $video["status"],
        "posicao_fila"   => $video["posicao_fila"] === null ? null : (int)$video["posicao_fila"],
        "arquivo"        => $video["arquivo_local"],
        "url_plataforma" => $video["url_plataforma"],
        "provider"       => $video["provider"],
        "erro"           => $video["erro"],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("video/status: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao consultar o vídeo."]);
}
