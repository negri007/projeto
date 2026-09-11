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

/** Quantas etiquetas (`#tag`) um post indexa. */
const POSTS_MAX_TAGS = 10;

/** Duração de um post efêmero, do momento em que a decadência zera
 *  (criação, ou último comentário) até ele ser marcado morto. */
const POSTS_EFEMERO_HORAS = 24;

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
                p.is_efemero, p.efemero_criado_em,
                TIMESTAMPDIFF(SECOND, p.efemero_criado_em, NOW()) AS efemero_decorrido_seg,
                (SELECT r.id FROM rumores r WHERE r.post_origem_id = p.id) AS rumor_id,
                u.name, u.email, u.avatar,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                (SELECT COUNT(*) FROM post_shares ps WHERE ps.post_id = p.id) AS share_count,
                (SELECT COUNT(*) FROM post_likes pl2
                  WHERE pl2.post_id = p.id AND pl2.user_id = :uid) AS liked_by_me,
                (SELECT COUNT(*) FROM post_saves psv
                  WHERE psv.post_id = p.id AND psv.user_id = :uid2) AS saved_by_me
         FROM posts p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = :pid"
    );
    $stmt->execute(["uid" => $userId, "uid2" => $userId, "pid" => $postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? posts_post_row($row) : null;
}

/** Normaliza uma linha de post para o formato do contrato. */
function posts_post_row(array $row): array
{
    $efemero = !empty($row["is_efemero"]);

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
        "saved_by_me"   => (int)($row["saved_by_me"] ?? 0) > 0 ? 1 : 0,
        "is_efemero"    => $efemero,
        // null pra post normal — o front só desenha o efeito de decadência
        // quando o campo vem preenchido.
        "decadencia"    => $efemero ? posts_nivel_decadencia((int)($row["efemero_decorrido_seg"] ?? 0)) : null,
        "morre_em_seg"  => $efemero ? posts_morre_em_segundos((int)($row["efemero_decorrido_seg"] ?? 0)) : null,
        // Presente (id do rumor) só quando este post é origem de um
        // boato — é o que liga o post à timeline de api/rumores/get.php.
        "rumor_id"      => isset($row["rumor_id"]) && $row["rumor_id"] !== null ? (int)$row["rumor_id"] : null,
    ];
}

/**
 * Nível de decadência (0 a 100) de um post efêmero, a partir de quantos
 * segundos já se passaram desde `efemero_criado_em` (criação, ou o
 * último comentário — o que resetou o relógio por último).
 */
function posts_nivel_decadencia(int $decorridoSeg): int
{
    $totalSeg = POSTS_EFEMERO_HORAS * 3600;

    return (int)min(100, max(0, round(($decorridoSeg / $totalSeg) * 100)));
}

/** Segundos que faltam para o post morrer; nunca negativo. */
function posts_morre_em_segundos(int $decorridoSeg): int
{
    return max(0, (POSTS_EFEMERO_HORAS * 3600) - $decorridoSeg);
}

/**
 * Marca como mortos os posts efêmeros cujas 24h já se esgotaram, sem
 * apagar a linha — só esconder do feed (`morto = 1`). Chamada antes de
 * listar, pra nenhuma leitura mostrar um post que já devia ter sumido;
 * idempotente, então rodar em toda listagem não tem custo de mais.
 */
function posts_expirar_efemeros(PDO $pdo): void
{
    $pdo->prepare(
        "UPDATE posts
            SET morto = 1
          WHERE is_efemero = 1
            AND morto = 0
            AND efemero_criado_em <= (NOW() - INTERVAL :horas HOUR)"
    )->execute(["horas" => POSTS_EFEMERO_HORAS]);
}

/**
 * Etiquetas de um texto (`#php`), normalizadas em minúsculas e sem
 * repetição.
 *
 * A etiqueta aceita letras (com acento), números, `_` e `-`, tem no
 * máximo 64 caracteres e nunca é só número — `#1` seria etiqueta de
 * qualquer texto com um número solto.
 */
function posts_extract_tags(string $content): array
{
    if (!preg_match_all('/(?:^|[^\w#])#([\p{L}\p{N}_-]{1,64})/u', $content, $m)) {
        return [];
    }

    $tags = [];

    foreach ($m[1] as $tag) {
        $tag = mb_strtolower($tag);

        if (preg_match('/^\d+$/u', $tag)) {
            continue;
        }

        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
    }

    // Um post não indexa mais que isso: o resto é ruído de tendência.
    return array_slice($tags, 0, POSTS_MAX_TAGS);
}

/**
 * Sincroniza as etiquetas de um post com o texto atual: cria as que
 * faltam, liga as novas e desliga as que saíram na edição.
 *
 * Falha aqui não pode derrubar a publicação — post sem etiqueta indexada
 * ainda é um post —, então o erro vai para o log e a função devolve
 * false.
 */
function posts_sync_hashtags(PDO $pdo, int $postId, string $content): bool
{
    try {
        $tags = posts_extract_tags($content);

        if ($tags) {
            // INSERT IGNORE porque `hashtags.tag` é único e duas pessoas
            // podem estrear a mesma etiqueta ao mesmo tempo.
            $insereTag = $pdo->prepare("INSERT IGNORE INTO hashtags (tag) VALUES (?)");
            $buscaTag  = $pdo->prepare("SELECT id FROM hashtags WHERE tag = ?");
            $liga      = $pdo->prepare(
                "INSERT IGNORE INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)"
            );

            $ids = [];

            foreach ($tags as $tag) {
                $insereTag->execute([$tag]);
                $buscaTag->execute([$tag]);
                $id = (int)$buscaTag->fetchColumn();

                if ($id > 0) {
                    $ids[] = $id;
                    $liga->execute([$postId, $id]);
                }
            }

            // Edição que apagou uma etiqueta desfaz a ligação.
            if ($ids) {
                $marcadores = implode(",", array_fill(0, count($ids), "?"));
                $pdo->prepare(
                    "DELETE FROM post_hashtags
                     WHERE post_id = ? AND hashtag_id NOT IN ($marcadores)"
                )->execute(array_merge([$postId], $ids));
            }

            return true;
        }

        $pdo->prepare("DELETE FROM post_hashtags WHERE post_id = ?")->execute([$postId]);

        return true;

    } catch (Exception $e) {
        error_log("posts_sync_hashtags(): " . $e->getMessage());
        return false;
    }
}
