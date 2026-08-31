<?php
/**
 * Helpers do módulo de posts.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/posts/`.
 */

/** Extensões aceitas na imagem do post, com o MIME real esperado. */
const POSTS_IMAGE_TYPES = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/gif"  => "gif",
    "image/webp" => "webp",
];

/** Tamanho máximo da imagem do post: 5 MB. */
const POSTS_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

/**
 * Valida e grava a imagem enviada. Devolve o nome do arquivo, ou lança
 * RuntimeException com a mensagem pronta para o cliente.
 *
 * O tipo vem do MIME real (finfo), nunca da extensão informada pelo
 * cliente: extensão é texto escolhido por quem envia.
 */
function posts_store_image(array $file): string
{
    if ($file["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Erro ao salvar a imagem.");
    }

    if ($file["size"] > POSTS_IMAGE_MAX_BYTES) {
        throw new RuntimeException("Imagem é grande demais (máx. 5 MB).");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file["tmp_name"]);

    if (!isset(POSTS_IMAGE_TYPES[$mime])) {
        throw new RuntimeException("Formato de imagem inválido.");
    }

    $uploadDir = __DIR__ . "/../../uploads";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException("Erro ao salvar a imagem.");
    }

    $name = uniqid("img_", true) . "." . POSTS_IMAGE_TYPES[$mime];

    if (!move_uploaded_file($file["tmp_name"], $uploadDir . "/" . $name)) {
        throw new RuntimeException("Erro ao salvar a imagem.");
    }

    return $name;
}

/**
 * Carrega um post no formato do contrato (mesmos campos de
 * `posts/list.php`), já com contadores e `liked_by_me` do usuário da
 * sessão. Devolve null se o post não existir.
 */
function posts_load(PDO $pdo, int $postId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.user_id, p.content, p.image, p.created_at, p.edited_at,
                u.name, u.email, u.avatar,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                (SELECT COUNT(*) FROM post_likes pl2
                  WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me
         FROM posts p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = :pid"
    );
    $stmt->execute(["uid" => $userId, "pid" => $postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? posts_post_row($row) : null;
}

/** Normaliza uma linha de post para o formato do contrato. */
function posts_post_row(array $row): array
{
    return [
        "id"            => (int)$row["id"],
        "user_id"       => (int)$row["user_id"],
        "content"       => $row["content"],
        "image"         => $row["image"] !== null && $row["image"] !== "" ? $row["image"] : null,
        "created_at"    => $row["created_at"],
        "edited_at"     => $row["edited_at"] ?? null,
        "name"          => $row["name"],
        "email"         => $row["email"],
        "avatar"        => !empty($row["avatar"]) ? $row["avatar"] : null,
        "comment_count" => (int)$row["comment_count"],
        "like_count"    => (int)$row["like_count"],
        "share_count"   => (int)$row["share_count"],
        "liked_by_me"   => (int)$row["liked_by_me"] > 0 ? 1 : 0,
    ];
}
