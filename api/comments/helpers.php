<?php
/**
 * Helpers do módulo de comentários.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/comments/`.
 */

/**
 * Normaliza a linha de comentário para o formato do contrato.
 *
 * `can_delete` já vem resolvido pelo servidor: o autor do comentário e o
 * dono do post podem apagar. O front não precisa refazer essa conta —
 * e não deve, porque quem decide é o back.
 */
function comments_comment_row(array $row, int $sessionUserId, int $postOwnerId): array
{
    $autorId = (int)$row["user_id"];

    return [
        "id"         => (int)$row["id"],
        "post_id"    => (int)$row["post_id"],
        "user_id"    => $autorId,
        "body"       => $row["body"],
        "created_at" => $row["created_at"],
        "name"       => $row["name"],
        "email"      => $row["email"],
        "avatar"     => !empty($row["avatar"]) ? $row["avatar"] : null,
        "can_delete" => $autorId === $sessionUserId || $postOwnerId === $sessionUserId,
    ];
}
