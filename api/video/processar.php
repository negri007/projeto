<?php
/**
 * Processa UM vídeo pendente — chamado internamente por gerar.php, em
 * background, nunca por HTTP. A geração leva 30 a 90 segundos; bloquear
 * a requisição do lojista até terminar prenderia a aba dele esse tempo
 * todo.
 *
 *     php api/video/processar.php <video_id>
 *
 * Mesma guarda dos scripts internos de api/ai/ (ver docs/API_CONTRACT.md,
 * "Endpoints internos — só CLI"): 404 fora do CLI, e é proposital que
 * seja 404 e não 403 — 403 confirma que o arquivo existe.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../lojas/helpers.php";

$videoId = (int)($argv[1] ?? 0);

if ($videoId <= 0) {
    fwrite(STDERR, "Uso: php processar.php <video_id>\n");
    exit(1);
}

try {
    $stmt = $pdo->prepare("SELECT id, loja_id, prompt, status FROM videos_gerados WHERE id = ?");
    $stmt->execute([$videoId]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        fwrite(STDERR, "video_id $videoId não existe.\n");
        exit(1);
    }

    if ($registro["status"] !== "gerando") {
        // Já foi processado (ou a tentativa duplicada perdeu a corrida).
        // Reprocessar pisaria num resultado que a tela já pode estar
        // mostrando.
        exit(0);
    }

    $lojaId = (int)$registro["loja_id"];
    $loja = $lojaId > 0 ? loja_por_id($pdo, $lojaId) : null;
    $nicho = $loja["categoria"] ?? "Outro";

    $resultado = video_gerar($pdo, (string)$registro["prompt"], $lojaId, $nicho);

    if ($resultado["ok"]) {
        $stmt = $pdo->prepare(
            "UPDATE videos_gerados
                SET status = 'pronto', provider = ?, arquivo_local = ?, url_plataforma = ?, erro = NULL
              WHERE id = ?"
        );
        $stmt->execute([$resultado["provider"], $resultado["arquivo"], $resultado["url"], $videoId]);

        echo json_encode(["ok" => true, "video_id" => $videoId, "provider" => $resultado["provider"]]) . "\n";
    } else {
        $stmt = $pdo->prepare("UPDATE videos_gerados SET status = 'erro', erro = ? WHERE id = ?");
        $stmt->execute([$resultado["erro"], $videoId]);

        echo json_encode(["ok" => false, "video_id" => $videoId, "erro" => $resultado["erro"]]) . "\n";
    }
} catch (Throwable $e) {
    error_log("video/processar($videoId): " . $e->getMessage());

    try {
        $stmt = $pdo->prepare("UPDATE videos_gerados SET status = 'erro', erro = ? WHERE id = ?");
        $stmt->execute(["Erro interno ao gerar o vídeo.", $videoId]);
    } catch (Exception $e2) {
        // Nada mais a fazer — o registro fica preso em 'gerando' até
        // alguém olhar o log.
    }

    exit(1);
}
