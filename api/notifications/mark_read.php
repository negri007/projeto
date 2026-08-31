<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data           = json_decode(file_get_contents("php://input"), true);
$markAll        = !empty($data["mark_all"]);
$notificationId = (int)($data["notification_id"] ?? 0);

if (!$markAll && $notificationId <= 0) {
    echo json_encode(["error" => "Notificação não encontrada."]);
    exit;
}

try {
    if ($markAll) {
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$userId]);
    } else {
        // O `AND user_id = ?` é o que impede marcar notificação alheia:
        // sem ele, bastaria varrer ids.
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$notificationId, $userId]);

        if ($stmt->rowCount() === 0) {
            // Id inexistente e id de outra pessoa devolvem o mesmo erro,
            // de propósito.
            $check = $pdo->prepare(
                "SELECT id FROM notifications WHERE id = ? AND user_id = ?"
            );
            $check->execute([$notificationId, $userId]);

            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(["error" => "Notificação não encontrada."]);
                exit;
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->execute([$userId]);

    echo json_encode(["ok" => true, "unread_count" => (int)$stmt->fetchColumn()]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao marcar notificação."]);
}
