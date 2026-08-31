<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

// Multipart, por causa do upload de avatar. O usuário editado é sempre o
// da sessão: não existe mais `email` no corpo.
$name = trim((string)($_POST["name"] ?? ""));
$bio  = trim((string)($_POST["bio"] ?? ""));

if ($name === "") {
    echo json_encode(["error" => "Nome é obrigatório."]);
    exit;
}

if (mb_strlen($name) > 100) {
    echo json_encode(["error" => "Nome é longo demais (máx. 100 caracteres)."]);
    exit;
}

if (mb_strlen($bio) > 500) {
    echo json_encode(["error" => "Bio é longa demais (máx. 500 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, bio, avatar, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["error" => "Usuário não encontrado."]);
        exit;
    }

    $oldAvatar = $user["avatar"];
    $avatar    = $oldAvatar;

    if (!empty($_FILES["avatar"]["name"])) {
        try {
            $avatar = profile_store_avatar($_FILES["avatar"], $userId);
        } catch (RuntimeException $e) {
            // Mesma regra de posts/create.php: a RuntimeException de
            // profile_store_avatar() carrega uma mensagem escrita para
            // o usuario final, nao um erro interno.
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET name = ?, bio = ?, avatar = ? WHERE id = ?");
    $stmt->execute([$name, $bio !== "" ? $bio : null, $avatar, $userId]);

    // Só depois do UPDATE dar certo o arquivo antigo é descartado.
    if ($avatar !== $oldAvatar) {
        profile_delete_avatar($oldAvatar);
    }

    $_SESSION["user_name"] = $name;

    $user["name"]   = $name;
    $user["bio"]    = $bio;
    $user["avatar"] = $avatar;

    echo json_encode([
        "ok"    => true,
        "user"  => profile_user_row($user, $userId),
        "stats" => profile_stats($pdo, $userId),
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao atualizar perfil."]);
}
