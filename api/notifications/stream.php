<?php
/**
 * Notificações em tempo real via Server-Sent Events.
 *
 * Troca o polling de 20s do sino por uma conexão longa: o servidor manda
 * um evento assim que aparece notificação nova, em vez do cliente ficar
 * perguntando. A conexão fica aberta por no máximo `DURACAO_MAX` segundos
 * e termina sozinha — o `EventSource` do navegador reconecta automático
 * (é o comportamento nativo dele), então não precisa de reconexão manual
 * no front. `Last-Event-ID` (mandado pelo próprio navegador a cada
 * reconexão) é o cursor: só manda notificação com id maior que esse.
 *
 * Ambiente local com `php -S` (ver ajustes.md): esse servidor é
 * single-thread, então uma conexão SSE aberta bloqueia QUALQUER outra
 * requisição ao mesmo processo (inclusive de outro usuário) até fechar.
 * `DURACAO_MAX` curto existe por causa disso — em produção, atrás de
 * Apache/PHP-FPM de verdade (múltiplos workers), isso deixa de ser
 * problema e dá pra alongar.
 */

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// Libera o lock do arquivo de sessão agora: sem isto, qualquer outra
// chamada do MESMO usuário (postar, curtir) fica parada até este stream
// fechar, porque o handler de sessão do PHP serializa por sessão.
session_write_close();

header("Content-Type: text/event-stream; charset=utf-8");
header("Cache-Control: no-cache");
header("X-Accel-Buffering: no");
header("Connection: keep-alive");

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

set_time_limit(40);

$lastId = (int)($_SERVER["HTTP_LAST_EVENT_ID"] ?? $_GET["last_id"] ?? 0);

// Curto de propósito: no ambiente local (php -S, single-thread — ver
// comentário no topo do arquivo), CADA segundo que esta conexão fica
// aberta é um segundo em que nenhuma outra requisição do servidor
// inteiro é atendida. 25s dava uma "eternidade" percebida em qualquer
// clique feito nesse meio-tempo. 6s ainda economiza muito polling
// (contra os 20s do polling antigo) sem travar tanto. Em produção, atrás
// de Apache/PHP-FPM de verdade (múltiplos workers), isso deixa de ser
// problema e dá pra alongar de novo.
const DURACAO_MAX = 6;
$inicio = time();

while (true) {
    if (connection_aborted()) {
        break;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT n.id, n.type, n.reference_id, n.is_read, n.created_at,
                    n.actor_id, u.name AS actor_name, u.avatar AS actor_avatar
             FROM notifications n
             JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ? AND n.id > ?
             ORDER BY n.id ASC
             LIMIT 20"
        );
        $stmt->execute([$userId, $lastId]);
        $novas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($novas) {
            foreach ($novas as $row) {
                $lastId = max($lastId, (int)$row["id"]);
            }

            $stmtCount = $pdo->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
            );
            $stmtCount->execute([$userId]);

            echo "id: {$lastId}\n";
            echo "event: notification\n";
            echo "data: " . json_encode([
                "notifications" => array_map("notifications_row", $novas),
                "unread_count"  => (int)$stmtCount->fetchColumn(),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
    } catch (Exception $e) {
        error_log("notifications/stream.php: " . $e->getMessage());
        break;
    }

    if (time() - $inicio >= DURACAO_MAX) {
        // Comentário SSE (ignorado pelo EventSource) só pra fechar a
        // conexão de um jeito que o navegador reconhece como "acabou
        // normal, pode reconectar" em vez de erro.
        echo ": bye\n\n";
        flush();
        break;
    }

    sleep(1);
}
