<?php
/**
 * Helpers do módulo de círculos.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/circles/`.
 */

/**
 * Resolve o id do usuário-alvo (o membro) a partir do corpo JSON.
 * Aceita `user_id` (canônico) e, onde o contrato prevê, `friend_email`.
 * Devolve o id só se o usuário existir de fato; senão, null.
 */
function circles_target_user_id(PDO $pdo, $data, bool $allowEmail = true): ?int
{
    if (!is_array($data)) {
        return null;
    }

    $id = (int)($data["user_id"] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row["id"] : null;
    }

    if (!$allowEmail) {
        return null;
    }

    $email = trim((string)($data["friend_email"] ?? ""));

    if ($email === "") {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (int)$row["id"] : null;
}

/**
 * Carrega o círculo se o usuário tiver acesso a ele (é dono OU membro).
 * Devolve ["id" => int, "owner_id" => int, "name" => string,
 * "description" => ?string, "created_at" => string, "is_owner" => bool]
 * ou null quando o círculo não existe ou o usuário não participa dele.
 *
 * Círculo inexistente e círculo de terceiros devolvem o mesmo null de
 * propósito: quem não participa não descobre que o círculo existe.
 */
function circles_load_for_user(PDO $pdo, int $circleId, int $userId): ?array
{
    if ($circleId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT c.id, c.owner_id, c.name, c.description, c.created_at
         FROM circles c
         LEFT JOIN circle_members cm
           ON cm.circle_id = c.id AND cm.user_id = :me1
         WHERE c.id = :cid
           AND (c.owner_id = :me2 OR cm.user_id IS NOT NULL)
         LIMIT 1"
    );
    $stmt->execute(["me1" => $userId, "cid" => $circleId, "me2" => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        "id"          => (int)$row["id"],
        "owner_id"    => (int)$row["owner_id"],
        "name"        => $row["name"],
        "description" => $row["description"] !== null && $row["description"] !== "" ? $row["description"] : null,
        "created_at"  => $row["created_at"],
        "is_owner"    => (int)$row["owner_id"] === $userId,
    ];
}

/**
 * Formata o círculo para a resposta, no formato do contrato:
 * `user_id` é o dono (coluna `owner_id` no banco).
 */
function circles_circle_row(array $circle, int $memberCount): array
{
    return [
        "id"           => $circle["id"],
        "user_id"      => $circle["owner_id"],
        "name"         => $circle["name"],
        "description"  => $circle["description"],
        "created_at"   => $circle["created_at"],
        "member_count" => $memberCount,
        "is_owner"     => $circle["is_owner"],
    ];
}

/** Formata uma linha de membro para a resposta. */
function circles_member_row(array $row): array
{
    return [
        "user_id"   => (int)$row["user_id"],
        "name"      => $row["name"],
        "email"     => $row["email"],
        "avatar"    => $row["avatar"] !== null && $row["avatar"] !== "" ? $row["avatar"] : null,
        "joined_at" => $row["joined_at"],
    ];
}

/** Os dois usuários são amigos (amizade aceita, em qualquer direção)? */
function circles_are_friends(PDO $pdo, int $a, int $b): bool
{
    $stmt = $pdo->prepare(
        "SELECT id FROM friends
         WHERE status = 'accepted'
           AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
         LIMIT 1"
    );
    $stmt->execute([$a, $b, $b, $a]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}
