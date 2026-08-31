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
];

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
