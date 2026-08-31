<?php
/**
 * Helpers do módulo de mensagens privadas.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/messages/`. Acessar direto pelo navegador não produz saída.
 */

/**
 * Resolve o id do outro usuário da conversa a partir de um array de
 * parâmetros (corpo JSON em `send.php`, query string em `list.php`).
 *
 * Aceita, nesta ordem de precedência: `user_id`, `friend` (id — nome
 * usado na query de `list.php`, conforme o contrato) e `friend_email`.
 * Devolve o id somente se o usuário existir de fato; senão, null.
 */
function messages_target_id(PDO $pdo, $data, bool $allowEmail = true): ?int
{
    if (!is_array($data)) {
        return null;
    }

    $id = (int)($data["user_id"] ?? 0);

    if ($id <= 0) {
        $id = (int)($data["friend"] ?? 0);
    }

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
 * Os dois usuários são amigos (amizade aceita, em qualquer direção)?
 *
 * A amizade é uma linha só na tabela `friends`, criada em qualquer uma
 * das duas direções — por isso a checagem cruzada.
 */
function messages_are_friends(PDO $pdo, int $a, int $b): bool
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

/**
 * Carrega o interlocutor da conversa e valida a regra de acesso: só é
 * possível conversar com um amigo. Devolve o usuário formatado no
 * padrão do contrato, ou null quando não há amizade aceita.
 */
function messages_load_friend(PDO $pdo, int $targetId, int $userId): ?array
{
    if (!messages_are_friends($pdo, $targetId, $userId)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, name, email, avatar FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        "user_id" => (int)$row["id"],
        "name"    => $row["name"],
        "email"   => $row["email"],
        "avatar"  => $row["avatar"] !== null && $row["avatar"] !== "" ? $row["avatar"] : null,
    ];
}

/** Formata uma linha de `messages` para a resposta. */
function messages_message_row(array $row): array
{
    return [
        "id"          => (int)$row["id"],
        "user_id"     => (int)$row["sender_id"],
        "receiver_id" => (int)$row["receiver_id"],
        "body"        => $row["body"],
        "created_at"  => $row["created_at"],
        "read_at"     => $row["read_at"] ?? null,
        "name"        => $row["name"],
        "email"       => $row["email"],
    ];
}
