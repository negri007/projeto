<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// Sem `user_id`, devolve o perfil do próprio usuário da sessão. O
// `user_id` opcional serve para ver o perfil de outra pessoa — perfil é
// informação pública dentro do sistema, mas a identidade de quem
// pergunta continua vindo só da sessão.
$targetId = (int)($_GET["user_id"] ?? 0);

if ($targetId <= 0) {
    $targetId = $userId;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, name, email, bio, avatar, created_at FROM users WHERE id = ?"
    );
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["error" => "Usuário não encontrado."]);
        exit;
    }

    echo json_encode([
        "ok"    => true,
        "user"  => profile_user_row($row, $userId),
        "stats" => profile_stats($pdo, $targetId),
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao buscar perfil."]);
}
