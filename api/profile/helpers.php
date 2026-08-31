<?php
/**
 * Helpers do módulo de perfil.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/profile/`.
 */

/** Extensões de imagem aceitas no avatar, com o MIME real esperado. */
const PROFILE_AVATAR_TYPES = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/gif"  => "gif",
    "image/webp" => "webp",
];

/** Tamanho máximo do avatar: 2 MB. */
const PROFILE_AVATAR_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Formata a linha de `users` para o objeto de perfil do contrato.
 * `is_me` diz se o perfil é o do próprio usuário da sessão — é o que o
 * front usa para decidir se mostra o botão "Editar perfil".
 */
function profile_user_row(array $row, int $sessionUserId): array
{
    return [
        "user_id"    => (int)$row["id"],
        "name"       => $row["name"],
        "email"      => $row["email"],
        "bio"        => $row["bio"] !== null && $row["bio"] !== "" ? $row["bio"] : null,
        "avatar"     => $row["avatar"] !== null && $row["avatar"] !== "" ? $row["avatar"] : null,
        "created_at" => $row["created_at"],
        "is_me"      => (int)$row["id"] === $sessionUserId,
    ];
}

/**
 * Estatísticas do perfil. Amizade é uma linha só, em qualquer direção,
 * por isso a contagem cruzada; círculos soma os que a pessoa criou mais
 * aqueles de que participa.
 */
function profile_stats(PDO $pdo, int $userId): array
{
    $sql = "SELECT
                (SELECT COUNT(*) FROM posts WHERE user_id = :u1) AS posts,
                (SELECT COUNT(*) FROM post_likes pl
                   JOIN posts p ON p.id = pl.post_id
                  WHERE p.user_id = :u2) AS likes_received,
                (SELECT COUNT(*) FROM friends
                  WHERE status = 'accepted'
                    AND (user_id = :u3 OR friend_id = :u4)) AS friends,
                (SELECT COUNT(*) FROM circles WHERE owner_id = :u5)
                + (SELECT COUNT(*) FROM circle_members WHERE user_id = :u6) AS circles";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "u1" => $userId, "u2" => $userId, "u3" => $userId,
        "u4" => $userId, "u5" => $userId, "u6" => $userId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        "posts"          => (int)($row["posts"] ?? 0),
        "likes_received" => (int)($row["likes_received"] ?? 0),
        "friends"        => (int)($row["friends"] ?? 0),
        "circles"        => (int)($row["circles"] ?? 0),
    ];
}

/**
 * Valida e grava o avatar enviado. Devolve o nome do arquivo novo, ou
 * lança RuntimeException com a mensagem já pronta para o cliente.
 *
 * O tipo é decidido pelo MIME real do arquivo (finfo), nunca pela
 * extensão informada pelo cliente — extensão é texto que o usuário
 * escolhe, e um `.png` pode conter qualquer coisa.
 */
function profile_store_avatar(array $file, int $userId): string
{
    if ($file["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Falha ao enviar a imagem.");
    }

    if ($file["size"] > PROFILE_AVATAR_MAX_BYTES) {
        throw new RuntimeException("Imagem é grande demais (máx. 2 MB).");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file["tmp_name"]);

    if (!isset(PROFILE_AVATAR_TYPES[$mime])) {
        throw new RuntimeException("Formato de imagem inválido. Use jpg, png, gif ou webp.");
    }

    $uploadDir = __DIR__ . "/../../uploads";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException("Falha ao enviar a imagem.");
    }

    $name = "avatar_" . $userId . "_" . time() . "." . PROFILE_AVATAR_TYPES[$mime];

    if (!move_uploaded_file($file["tmp_name"], $uploadDir . "/" . $name)) {
        throw new RuntimeException("Falha ao enviar a imagem.");
    }

    return $name;
}

/** Apaga um avatar antigo do disco, ignorando qualquer falha. */
function profile_delete_avatar(?string $avatar): void
{
    if ($avatar === null || $avatar === "") {
        return;
    }

    // Só apaga nomes gerados por profile_store_avatar(); nunca um caminho
    // arbitrário que tenha entrado no banco por outro caminho.
    if (!preg_match('/^avatar_\d+_\d+\.(jpg|png|gif|webp)$/', $avatar)) {
        return;
    }

    $path = __DIR__ . "/../../uploads/" . $avatar;

    if (is_file($path)) {
        @unlink($path);
    }
}
