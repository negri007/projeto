<?php
/**
 * Helpers de notificação.
 *
 * Incluído pelos módulos que geram eventos (`posts/`, `comments/`,
 * `friends/`, `messages/`) e pelos endpoints de `api/notifications/`.
 */

/** Tipos aceitos — espelham o ENUM da coluna `notifications.type`. */
const NOTIFICATION_TYPES = [
    "like", "comment", "share", "friend_request", "friend_accept", "message",
    "mention",
];

/** Quantas pessoas um único texto pode notificar por menção. */
const MENTION_MAX_PER_TEXT = 10;

/**
 * Cria uma notificação para `$userId`, disparada por `$actorId`.
 *
 * Regras:
 * - ninguém é notificado da própria ação (`$userId === $actorId` é no-op);
 * - falha ao gravar nunca derruba a ação principal — curtir um post tem
 *   de funcionar mesmo que a notificação não seja gravada. O erro vai
 *   para o log do PHP, não para a resposta.
 *
 * Devolve true se gravou.
 */
function notify(PDO $pdo, int $userId, int $actorId, string $type, ?int $referenceId = null): bool
{
    if ($userId === $actorId || $userId <= 0 || $actorId <= 0) {
        return false;
    }

    if (!in_array($type, NOTIFICATION_TYPES, true)) {
        error_log("notify(): tipo de notificação desconhecido: " . $type);
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, actor_id, type, reference_id)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $actorId, $type, $referenceId]);

        return true;

    } catch (Exception $e) {
        error_log("notify(): falha ao gravar notificação: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove uma notificação já gerada — usado quando a ação é desfeita
 * (descurtir, cancelar pedido de amizade), para o sino não acumular
 * aviso de algo que não vale mais.
 */
function notify_undo(PDO $pdo, int $userId, int $actorId, string $type, ?int $referenceId = null): void
{
    if ($userId === $actorId) {
        return;
    }

    try {
        $sql = "DELETE FROM notifications
                WHERE user_id = ? AND actor_id = ? AND type = ?
                  AND reference_id " . ($referenceId === null ? "IS NULL" : "= ?");

        $params = [$userId, $actorId, $type];

        if ($referenceId !== null) {
            $params[] = $referenceId;
        }

        $pdo->prepare($sql)->execute($params);

    } catch (Exception $e) {
        error_log("notify_undo(): falha ao remover notificação: " . $e->getMessage());
    }
}

/**
 * Extrai os handles citados num texto (`@fulano`), em minúsculas e sem
 * repetição.
 *
 * O handle é a parte do e-mail antes do `@` — o mesmo que o front já
 * mostra ao lado do nome. Um e-mail inteiro escrito no texto
 * (`fulano@echo.local`) não vira menção: a expressão exige que o `@`
 * venha no começo do texto ou depois de um separador.
 */
function mention_handles(string $text): array
{
    if (!preg_match_all('/(?:^|[^\w@.])@([a-z0-9._-]{2,64})/iu', $text, $m)) {
        return [];
    }

    $handles = [];

    foreach ($m[1] as $handle) {
        // Ponto final da frase colado no handle não faz parte dele.
        $handle = mb_strtolower(rtrim($handle, "."));

        if ($handle !== "" && !in_array($handle, $handles, true)) {
            $handles[] = $handle;
        }
    }

    return array_slice($handles, 0, MENTION_MAX_PER_TEXT);
}

/**
 * Notifica quem foi citado em `$text` com `@handle`.
 *
 * `$referenceId` é sempre o **post** onde a menção apareceu — inclusive
 * quando ela veio num comentário —, para o clique na notificação levar
 * ao lugar em que o texto está.
 *
 * Handle ambíguo (duas contas com o mesmo nome antes do `@`, em domínios
 * diferentes) não notifica ninguém: é melhor perder a menção do que
 * avisar a pessoa errada.
 *
 * Devolve os ids notificados. Como todo `notify()`, falha aqui nunca
 * derruba a ação principal.
 */
function notify_mentions(PDO $pdo, string $text, int $actorId, ?int $referenceId): array
{
    $handles = mention_handles($text);

    if (!$handles) {
        return [];
    }

    try {
        $marcadores = implode(",", array_fill(0, count($handles), "?"));

        // O handle sai do próprio e-mail; não existe coluna separada.
        $stmt = $pdo->prepare(
            "SELECT id, LOWER(SUBSTRING_INDEX(email, '@', 1)) AS handle
             FROM users
             WHERE LOWER(SUBSTRING_INDEX(email, '@', 1)) IN ($marcadores)"
        );
        $stmt->execute($handles);

        $porHandle = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porHandle[$row["handle"]][] = (int)$row["id"];
        }

        $notificados = [];

        // Já existe aviso de menção minha para esta pessoa neste post?
        // Citar alguém três vezes no mesmo post é um aviso, não três.
        $jaAvisado = $pdo->prepare(
            "SELECT 1 FROM notifications
             WHERE user_id = ? AND actor_id = ? AND type = 'mention'
               AND reference_id " . ($referenceId === null ? "IS NULL" : "= ?") . "
             LIMIT 1"
        );

        foreach ($handles as $handle) {
            $ids = $porHandle[$handle] ?? [];

            if (count($ids) !== 1) {
                continue;
            }

            $params = [$ids[0], $actorId];

            if ($referenceId !== null) {
                $params[] = $referenceId;
            }

            $jaAvisado->execute($params);

            if ($jaAvisado->fetchColumn()) {
                continue;
            }

            if (notify($pdo, $ids[0], $actorId, "mention", $referenceId)) {
                $notificados[] = $ids[0];
            }
        }

        return $notificados;

    } catch (Exception $e) {
        error_log("notify_mentions(): " . $e->getMessage());
        return [];
    }
}

/** Formata uma linha de `notifications` (já com JOIN em users) para a resposta. */
function notifications_row(array $row): array
{
    return [
        "id"           => (int)$row["id"],
        "type"         => $row["type"],
        "actor_id"     => (int)$row["actor_id"],
        "actor_name"   => $row["actor_name"],
        "actor_avatar" => $row["actor_avatar"] !== null && $row["actor_avatar"] !== "" ? $row["actor_avatar"] : null,
        "reference_id" => $row["reference_id"] !== null ? (int)$row["reference_id"] : null,
        "is_read"      => (bool)(int)$row["is_read"],
        "created_at"   => $row["created_at"],
    ];
}
