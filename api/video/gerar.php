<?php
/**
 * O lojista dispara a geração do vídeo de apresentação.
 *
 * Devolve na hora (`status: 'gerando'`) e a geração de verdade roda em
 * background via `processar.php` — ver `video_gerar()` em helpers.php.
 * O front faz poll em `status.php` até `pronto` ou `erro`.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../lojas/helpers.php";
require_once __DIR__ . "/../ai/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    // A loja vem sempre da sessão — o `loja_id` que o corpo eventualmente
    // mande não decide nada, mesma regra de produto_criar.php e do resto
    // das rotas de escrita do lojista (CLAUDE.md: nenhum endpoint aceita
    // identidade vinda do cliente).
    $loja = loja_do_usuario($pdo, $userId);

    if ($loja === null) {
        echo json_encode(["error" => "Você ainda não tem loja."]);
        exit;
    }

    $lojaId = (int)$loja["id"];

    $input  = json_decode(file_get_contents("php://input"), true);
    $prompt = trim((string)($input["prompt"] ?? ""));

    if ($prompt === "") {
        echo json_encode(["error" => "Descreva o vídeo antes de gerar."]);
        exit;
    }

    $prompt = mb_substr($prompt, 0, 500);

    $motivo = ai_moderate($prompt);

    if ($motivo !== null) {
        echo json_encode(["error" => "Esse texto não pode ser usado. Tente descrever a loja de outra forma."]);
        exit;
    }

    /* O SELETOR DE MODO VALE AQUI TAMBÉM.

       Gerar vídeo chama a API paga do Kling (fallback grátis no Pexels).
       Com o seletor em "só acervo" — o botão que desliga a geração por IA
       do app inteiro — não se gasta nada. É o mesmo freio já aplicado às
       sugestões do agente, à provocação da IAlândia e ao seed; vídeo
       custa mais caro que texto, então aqui pega com ainda mais razão.

       Devolve `modo_acervo` para a tela dar a mensagem certa (ligue o
       seletor) em vez de um erro genérico. Sai ANTES de gravar o registro
       'gerando' e de disparar o processo. */
    if ((ai_estado($pdo)["mode"] ?? "hibrido") === "acervo") {
        echo json_encode([
            "ok"          => false,
            "modo_acervo" => true,
            "error"       => "A geração por IA está desligada. Ligue em Rede IA, no seletor \"Modo de geração\", para gerar vídeo.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Uma geração por loja por hora. A mesma consulta cobre o freio de
       abuso E o "não gera dois ao mesmo tempo": um registro 'gerando'
       recente já conta, então a segunda tentativa cai aqui antes de
       disparar outro processo. */
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM videos_gerados
          WHERE loja_id = ? AND status != 'erro' AND created_at > NOW() - INTERVAL 1 HOUR"
    );
    $stmt->execute([$lojaId]);

    if ((int)$stmt->fetchColumn() > 0) {
        http_response_code(429);
        echo json_encode(["error" => "Você já gerou um vídeo na última hora. Tente novamente mais tarde."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO videos_gerados (loja_id, prompt, status) VALUES (?, ?, 'gerando')");
    $stmt->execute([$lojaId, $prompt]);
    $videoId = (int)$pdo->lastInsertId();

    video_disparar_processamento($videoId);

    echo json_encode(["ok" => true, "video_id" => $videoId, "status" => "gerando"], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("video/gerar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao iniciar a geração do vídeo."]);
}
