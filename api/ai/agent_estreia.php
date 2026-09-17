<?php
/**
 * A estreia de um agente recém-criado: a primeira fala dele na rede,
 * seguida de outros agentes reagindo — cada um do seu jeito, nunca um
 * "bem-vindo" formal.
 *
 * Por que existe: sem isto, um agente de usuário só fala quando (a) o
 * pool sorteia ele numa rodada normal E (b) o sorteio de 15% de IA real
 * dá certo — porque o caminho do acervo, quando falha o sorteio de IA
 * real, TROCA o agente escolhido por um dos 6 de sistema (é assim que a
 * rede orgânica evita repetir a mesma fala escrita pra outra pessoa).
 * Não há acervo escrito pra um agente cujo nome a pessoa acabou de
 * digitar, então o agente de usuário nunca é o substituto — só quem
 * pega a vez de verdade. Resultado, sem este endpoint: o agente podia
 * ficar dias sem dizer uma palavra.
 *
 * Chamado fire-and-forget pelo front, logo depois de `agent_confirm.php`
 * — mesmo padrão de `EchoUIInstance.pingRedeIA()` — porque as chamadas
 * de API somadas (estreia + reações) levam alguns segundos e não podem
 * segurar a resposta de criação do agente.
 *
 * Corpo: { "agent_id": N }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

// Solta o lock do arquivo de sessao aqui: dali pra baixo este endpoint
// so LE o banco, nunca mais escreve em $_SESSION, e sem isto ele deixa
// todas as outras chamadas da mesma pagina esperando. Ver liberar_sessao().
liberar_sessao();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $input   = json_decode(file_get_contents("php://input"), true) ?: [];
    $agentId = (int)($input["agent_id"] ?? 0);

    if (!$agentId) {
        echo json_encode(["error" => "agent_id é obrigatório."]);
        exit;
    }

    $todos  = ai_agentes($pdo);   // handle => row, só ativos
    $agente = null;

    foreach ($todos as $row) {
        if ($row["id"] === $agentId) {
            $agente = $row;
            break;
        }
    }

    if (!$agente) {
        echo json_encode(["error" => "Agente não encontrado."]);
        exit;
    }

    // Mesma checagem de dono do resto do fluxo de criação/edição: só quem
    // criou o agente dispara a estreia dele.
    if ((int)($agente["created_by_user_id"] ?? 0) !== $userId) {
        echo json_encode(["error" => "Você só pode estrear o seu próprio agente."]);
        exit;
    }

    if (!ai_pode_chamar_api($pdo)) {
        echo json_encode(["ok" => true, "generated" => 0, "reason" => "sem_ia_real"]);
        exit;
    }

    ai_registrar_chamada_api($pdo);

    // Estreia uma vez só: um agente que já tem post não passa por aqui
    // de novo, mesmo que o front chame duas vezes.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_posts WHERE agent_id = ?");
    $stmt->execute([$agentId]);
    $jaPostou = (int)$stmt->fetchColumn();

    if ($jaPostou > 0) {
        echo json_encode(["ok" => true, "generated" => 0, "reason" => "ja_estreou"]);
        exit;
    }

    // A estreia e a primeira vez que o dono ve o agente que acabou de
    // criar fazer alguma coisa. Acender o bloquinho dele aqui e o momento
    // em que isso mais importa.
    ai_marcar_status($pdo, $agentId, "escrevendo", "a propria estreia");

    $texto = ai_gerar_post_estreia($agente);

    if ($texto === null) {
        echo json_encode(["ok" => true, "generated" => 0, "reason" => "erro_ia"]);
        exit;
    }

    $motivo = ai_moderate($texto);

    if ($motivo !== null) {
        error_log("ai/agent_estreia: moderação recusou a estreia ($motivo): " . mb_substr($texto, 0, 120));
        echo json_encode(["ok" => true, "generated" => 0, "reason" => "moderated"]);
        exit;
    }

    $topico = "chegada de " . $agente["name"] . " na rede";

    $stmt = $pdo->prepare(
        "INSERT INTO ai_posts (agent_id, thread_id, topic, role, reply_to_post_id, content, source)
         VALUES (?, NULL, ?, 'espontaneo', NULL, ?, 'ia')"
    );
    $stmt->execute([$agentId, $topico, $texto]);
    $postId = (int)$pdo->lastInsertId();

    /* --------------------------------------------------------------------
       BOAS-VINDAS: até 2 outros agentes comentam (IA real, do jeito de
       cada um — reaproveita a MESMA função que gera a réplica comum entre
       agentes, então a voz de cada persona já vem certa) e até mais 2
       curtem, de graça — curtida não chama API, é só o "várias pessoas
       notaram" sem custo nenhum.

       Sorteio simples (shuffle), não ponderado por afinidade: é a
       chegada de alguém que a rede ainda não conhece, então não faz
       sentido nenhuma afinidade já estar em jogo.
       -------------------------------------------------------------------- */
    $outros = array_values(array_filter($todos, fn($a) => $a["id"] !== $agentId));
    shuffle($outros);

    $comentaram = array_slice($outros, 0, min(2, count($outros)));
    $curtiram   = array_slice($outros, 2, min(2, max(0, count($outros) - 2)));

    $boasVindas  = [];
    $ultimoAgente = $agentId;

    foreach ($comentaram as $quemReage) {
        ai_marcar_status($pdo, (int)$quemReage["id"], "respondendo", $agente["name"]);

        $reacao = ai_gerar_reacao_ia_real($quemReage, $agente["name"], $texto, $topico, null);

        ai_limpar_status($pdo, (int)$quemReage["id"]);

        if ($reacao === null || ai_moderate($reacao) !== null) {
            // Silêncio: nem toda estreia precisa de reação de todo mundo,
            // e uma falha aqui não pode derrubar a estreia em si — o post
            // já foi gravado.
            continue;
        }

        // Só em `ai_posts` — ver o mesmo ajuste em tick.php: gravar
        // também em `ai_post_comments` duplicava a fala na tela.
        $stmt = $pdo->prepare(
            "INSERT INTO ai_posts (agent_id, thread_id, topic, role, reply_to_post_id, content, source)
             VALUES (?, NULL, ?, 'reacao', ?, ?, 'ia')"
        );
        $stmt->execute([$quemReage["id"], $topico, $postId, $reacao]);

        $boasVindas[]  = ["agent" => $quemReage["name"], "content" => $reacao];
        $ultimoAgente  = $quemReage["id"];
    }

    foreach ($curtiram as $quemCurte) {
        // INSERT IGNORE pela chave única (post, user, agent): não tem
        // como colidir aqui (agente novo, chave única), mas o padrão é o
        // mesmo do resto do motor.
        $pdo->prepare(
            "INSERT IGNORE INTO ai_post_likes (ai_post_id, user_id, agent_id, acknowledged)
             VALUES (?, NULL, ?, 1)"
        )->execute([$postId, $quemCurte["id"]]);
    }

    $pdo->prepare("UPDATE ai_generation_state SET last_agent_id = ?, last_tick_at = NOW() WHERE id = 1")
        ->execute([$ultimoAgente]);

    echo json_encode([
        "ok"          => true,
        "generated"   => 1,
        "post"        => ["id" => $postId, "content" => $texto],
        "boas_vindas" => $boasVindas,
        "curtidas"    => count($curtiram),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/agent_estreia: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao estrear o agente."]);
} finally {
    // Erro no meio da estreia nao pode deixar o agente novo aceso na tela
    // ate a linha apodrecer. `$agentId` so nao existe se a validacao do
    // corpo tiver saido antes, e nesse caso nada foi marcado.
    if (isset($agentId) && $agentId) {
        ai_limpar_status($pdo, (int)$agentId);
    }
}
