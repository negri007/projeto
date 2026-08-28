<?php
/**
 * Helpers do módulo de amigos.
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/friends/`. Acessar direto pelo navegador não produz saída.
 */

/**
 * Resolve o id do "outro usuário" a partir do corpo JSON da requisição.
 *
 * Aceita `user_id` (canônico em todo o módulo) e, apenas onde o contrato
 * prevê, `friend_email`. Devolve o id somente se o usuário existir de
 * fato; caso contrário devolve null.
 */
function friends_target_id(PDO $pdo, $data, bool $allowEmail = false): ?int
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
 * Escapa os curingas de LIKE (`%`, `_`) para que o termo digitado pelo
 * usuário seja tratado como texto literal. Usar sempre com ESCAPE '!'.
 */
function friends_like_escape(string $term): string
{
    return str_replace(["!", "%", "_"], ["!!", "!%", "!_"], $term);
}

/**
 * Normaliza uma linha de usuário vinda do banco para o formato do
 * contrato: `user_id` inteiro + campos opcionais como null.
 */
function friends_user_row(array $row): array
{
    return [
        "user_id" => (int)$row["user_id"],
        "name"    => $row["name"],
        "email"   => $row["email"],
        "avatar"  => $row["avatar"] !== null && $row["avatar"] !== "" ? $row["avatar"] : null,
    ];
}

/**
 * Traduz a linha da tabela `friends` para o campo `status` do contrato:
 * "none", "pending_sent", "pending_received" ou "friends".
 */
function friends_rel_status(?string $relStatus, $relFrom, int $userId): string
{
    if ($relStatus === "accepted") {
        return "friends";
    }

    if ($relStatus === "pending") {
        return (int)$relFrom === $userId ? "pending_sent" : "pending_received";
    }

    return "none";
}
