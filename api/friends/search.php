<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

$q = trim($_GET["q"] ?? "");

if ($q === "") {
    echo json_encode(["ok" => true, "users" => []]);
    exit;
}

try {
    $like = "%" . friends_like_escape($q) . "%";

    // O LEFT JOIN traz a relação existente (em qualquer direção) para
    // que o front saiba se já é amigo, se há pedido pendente e de quem.
    $sql = "SELECT u.id AS user_id, u.name, u.email, u.avatar,
                   f.user_id AS rel_from, f.status AS rel_status
            FROM users u
            LEFT JOIN friends f
              ON (f.user_id = :me1 AND f.friend_id = u.id)
              OR (f.friend_id = :me2 AND f.user_id = u.id)
            WHERE u.id <> :me3
              AND (u.name LIKE :like1 ESCAPE '!' OR u.email LIKE :like2 ESCAPE '!')
            ORDER BY u.name ASC
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "me1"   => $userId,
        "me2"   => $userId,
        "me3"   => $userId,
        "like1" => $like,
        "like2" => $like
    ]);

    $users = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $user = friends_user_row($row);
        $user["status"] = friends_rel_status($row["rel_status"], $row["rel_from"], $userId);
        $users[] = $user;
    }

    echo json_encode(["ok" => true, "users" => $users]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao buscar usuários."]);
}
