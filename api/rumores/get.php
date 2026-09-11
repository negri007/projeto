<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();

$postId = (int)($_GET["post_id"] ?? 0);

if (!$postId) {
    echo json_encode(["error" => "post_id é obrigatório."]);
    exit;
}

try {
    $timeline = rumor_timeline($pdo, $postId);

    if ($timeline === null) {
        echo json_encode(["error" => "Este post não é origem de um boato."]);
        exit;
    }

    echo json_encode(["ok" => true, "rumor" => $timeline], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("rumores/get: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o boato."]);
}
