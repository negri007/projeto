<?php
/**
 * O pulso do Echo: o que a barra lateral precisa saber, numa consulta só.
 *
 * Existe para preencher os 381px que sobravam entre o menu e o cartão do
 * usuário na barra lateral — em TODAS as páginas, porque a barra é a
 * mesma nas doze. Media: sidebar de 340 x 381 vazios, medidos na tela.
 *
 * UM ENDPOINT, E NÃO TRÊS, de propósito. Os números vêm de lugares
 * diferentes (mensagens, amizades, posts), e cada um tem endpoint próprio
 * que devolve a LISTA daquilo. Chamar os três de toda página só para
 * extrair um contador de cada seria pagar três idas ao servidor, e trazer
 * conversas e pedidos inteiros para mostrar dois números.
 *
 * Leitura pura, sem chamada de API de IA nenhuma.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/auth/session.php";
require __DIR__ . "/auth/db.php";

$userId = require_login();

// É chamado no carregamento de toda página, junto de tudo o mais: não
// pode entrar na fila do lock da sessão. Ver liberar_sessao().
liberar_sessao();

/** Quantas horas o gráfico de atividade cobre. */
const PULSO_HORAS = 12;

try {
    /* ------------------------------------------------------------------
       OS CONTADORES DO MENU

       Viram bolinha em cima do item de menu. É a informação que o menu
       já devia dar e não dava: hoje a pessoa só descobre que tem pedido
       de amizade entrando na página de amigos.
       ------------------------------------------------------------------ */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    $mensagens = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $amigos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_saves WHERE user_id = ?");
    $stmt->execute([$userId]);
    $salvos = (int)$stmt->fetchColumn();

    /* ------------------------------------------------------------------
       A ATIVIDADE, HORA A HORA

       Duas séries separadas, e não uma soma: metade da graça do Echo é
       justamente a rede de agentes falando sozinha ao lado da rede
       humana. Somar as duas num número só apagaria a comparação.

       A consulta agrupa por hora no SQL e não em PHP porque o relógio do
       PHP desta instalação está adiantado em relação ao do MySQL — a
       mesma pegadinha já documentada em rate_limit.php e no tick.
       ------------------------------------------------------------------ */
    $serie = [];

    for ($h = PULSO_HORAS - 1; $h >= 0; $h--) {
        $serie[$h] = ["humano" => 0, "ia" => 0];
    }

    $sql = "SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) AS atras, COUNT(*) AS n
              FROM %s
             WHERE created_at > NOW() - INTERVAL " . PULSO_HORAS . " HOUR
             GROUP BY atras";

    foreach ($pdo->query(sprintf($sql, "posts")) as $row) {
        $h = (int)$row["atras"];
        if (isset($serie[$h])) {
            $serie[$h]["humano"] = (int)$row["n"];
        }
    }

    foreach ($pdo->query(sprintf($sql, "ai_posts")) as $row) {
        $h = (int)$row["atras"];
        if (isset($serie[$h])) {
            $serie[$h]["ia"] = (int)$row["n"];
        }
    }

    // Da hora mais antiga para a mais nova, que é como um gráfico se lê.
    $atividade = [];

    for ($h = PULSO_HORAS - 1; $h >= 0; $h--) {
        $atividade[] = [
            "ha_horas" => $h,
            "humano"   => $serie[$h]["humano"],
            "ia"       => $serie[$h]["ia"],
        ];
    }

    echo json_encode([
        "ok"        => true,
        "contadores" => [
            "mensagens" => $mensagens,
            "amigos"    => $amigos,
            "salvos"    => $salvos,
        ],
        "atividade" => $atividade,
        "horas"     => PULSO_HORAS,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("pulso: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o pulso."]);
}
