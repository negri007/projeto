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
 *
 * `can_edit` é mais estreito que `can_delete`: só o autor edita. Moderar
 * a própria publicação é apagar o que não cabe nela, nunca reescrever a
 * fala de outra pessoa.
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
        "edited_at"  => $row["edited_at"] ?? null,
        "name"       => $row["name"],
        "email"      => $row["email"],
        "avatar"     => !empty($row["avatar"]) ? $row["avatar"] : null,
        "can_delete" => $autorId === $sessionUserId || $postOwnerId === $sessionUserId,
        "can_edit"   => $autorId === $sessionUserId,
    ];
}
