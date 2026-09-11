<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../notifications/helpers.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ai/helpers.php";
require_once __DIR__ . "/../rumores/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $content = trim($_POST["content"] ?? "");

    if ($content === "" && empty($_FILES["image"]["name"])) {
        echo json_encode(["error" => "Envie texto ou uma imagem."]);
        exit;
    }

    if (mb_strlen($content) > 5000) {
        echo json_encode(["error" => "Post é longo demais (máx. 5000 caracteres)."]);
        exit;
    }

    $imageName = null;

    if (!empty($_FILES["image"]["name"])) {
        try {
            $imageName = posts_store_image($_FILES["image"]);
        } catch (RuntimeException $e) {
            // RuntimeException aqui e sempre uma mensagem escrita
            // por posts_store_image() para o usuario final ("Formato
            // de imagem invalido."), nunca um erro interno — por isso
            // esta e a unica getMessage() que vai para a resposta.
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }

    // Post efêmero começa a decair a partir de agora (NOW() do banco, a
    // mesma referência que TIMESTAMPDIFF() usa depois pra calcular a
    // decadência — nunca o relógio do PHP). `efemero_criado_em` é o mesmo
    // campo que cada comentário novo reinicia.
    $efemero = ($_POST["is_efemero"] ?? "") === "1";

    $stmt = $pdo->prepare(
        "INSERT INTO posts (user_id, content, image, is_efemero, efemero_criado_em)
         VALUES (?, ?, ?, ?, " . ($efemero ? "NOW()" : "NULL") . ")"
    );
    $stmt->execute([$userId, $content, $imageName, $efemero ? 1 : 0]);

    $postId = (int)$pdo->lastInsertId();

    // Etiquetas e menções são efeitos do texto, não parte da gravação do
    // post: falha em qualquer uma delas não desfaz a publicação.
    posts_sync_hashtags($pdo, $postId, $content);
    notify_mentions($pdo, $content, $userId, $postId);

    // Crédito da rede de IA: +1 por post, até 5/dia. Efeito do post, não
    // parte da gravação dele — falhar aqui não pode desfazer a publicação.
    ai_creditar_post($pdo, $userId);

    // Post marcado como origem de boato. Só faz sentido com texto — um
    // post só de imagem não tem o que telefone-sem-fio distorcer.
    if (($_POST["is_rumor"] ?? "") === "1" && $content !== "") {
        try {
            $pdo->prepare("INSERT INTO rumores (post_origem_id) VALUES (?)")->execute([$postId]);
        } catch (Exception $e) {
            error_log("posts/create (rumores): " . $e->getMessage());
        }
    }

    // Devolve o post pronto para o front inserir no topo do feed sem
    // recarregar a lista inteira.
    echo json_encode([
        "ok"   => true,
        "post" => posts_load($pdo, $postId, $userId),
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao criar post."]);
}
