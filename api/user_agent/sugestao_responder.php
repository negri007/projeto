<?php
/**
 * O dono aprova ou rejeita uma sugestão do agente.
 *
 * APROVAR AQUI NÃO PUBLICA NADA, e isso é decisão de projeto e não
 * pendência. Aprovar marca a sugestão como aceita e devolve o texto; quem
 * publica é a tela, pelo mesmo `posts/create.php` que a pessoa usaria
 * escrevendo à mão. Assim existe um caminho só para nascer um post, com
 * uma moderação só, um gancho de notificação só e um lugar só para
 * quebrar.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input      = json_decode(file_get_contents("php://input"), true);
    $sugestaoId = (int)($input["sugestao_id"] ?? 0);
    $acao       = trim((string)($input["acao"] ?? ""));

    if (!$sugestaoId || !in_array($acao, ["aprovar", "rejeitar"], true)) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    /* O `user_id = ?` no WHERE é o que impede alguém de responder à
       sugestão de outra pessoa chutando ids. Conferir o dono numa
       consulta separada teria uma janela entre a checagem e a escrita. */
    $stmt = $pdo->prepare(
        "SELECT id, tipo, sugestao, status FROM user_agent_sugestoes
          WHERE id = ? AND user_id = ?"
    );
    $stmt->execute([$sugestaoId, $userId]);
    $sugestao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sugestao) {
        echo json_encode(["error" => "Sugestão não encontrada."]);
        exit;
    }

    if ($sugestao["status"] !== "pendente") {
        echo json_encode(["error" => "Essa sugestão já foi respondida."]);
        exit;
    }

    $novo = $acao === "aprovar" ? "aprovada" : "rejeitada";

    $pdo->prepare("UPDATE user_agent_sugestoes SET status = ? WHERE id = ? AND user_id = ?")
        ->execute([$novo, $sugestaoId, $userId]);

    echo json_encode([
        "ok"     => true,
        "status" => $novo,
        "tipo"   => $sugestao["tipo"],
        // Devolvido só na aprovação: é o texto que a tela vai usar para
        // preencher o campo de publicar ou o de responder.
        "texto"  => $acao === "aprovar" ? $sugestao["sugestao"] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/sugestao_responder: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao responder a sugestão."]);
}
