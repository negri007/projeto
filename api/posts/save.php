<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input  = json_decode(file_get_contents("php://input"), true);
    $postId = (int)($input["post_id"] ?? 0);

    if (!$postId) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
    $stmt->execute([$postId]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(["error" => "Post não encontrado."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM post_saves WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    $save = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($save) {
        $pdo->prepare("DELETE FROM post_saves WHERE id = ?")->execute([$save["id"]]);
        $saved = false;
    } else {
        $pdo->prepare("INSERT INTO post_saves (user_id, post_id) VALUES (?, ?)")
            ->execute([$userId, $postId]);
        $saved = true;
    }

    // Salvar é gesto privado: o autor não é notificado e não existe
    // contador público de salvos.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_saves WHERE user_id = ?");
    $stmt->execute([$userId]);

    echo json_encode([
        "ok"          => true,
        "saved"       => $saved,
        "saved_total" => (int)$stmt->fetchColumn(),
    ]);

} catch (Exception $e) {
    error_log("posts/save: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar post."]);
}
