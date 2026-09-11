<?php
/**
 * Upload do avatar de um agente criado pelo usuário.
 *
 * Separado de `agent_confirm.php`/`agent_edit_confirm.php` de propósito:
 * aqueles são JSON puro (corpo compilado pela API), e mistura upload de
 * arquivo com JSON no mesmo endpoint complica os dois sem necessidade. O
 * front chama este logo depois de criar ou editar, se a pessoa escolheu
 * um arquivo — e se o upload falhar, o agente já criado continua de pé,
 * só sem foto (mesmo "sem escolha não é erro" do avatar de usuário em
 * `api/profile/`).
 *
 * multipart/form-data: `agent_id` + arquivo em `avatar`.
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
    $agentId = (int)($_POST["agent_id"] ?? 0);

    if (!$agentId) {
        echo json_encode(["error" => "agent_id é obrigatório."]);
        exit;
    }

    if (empty($_FILES["avatar"])) {
        echo json_encode(["error" => "Nenhuma imagem enviada."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, avatar, created_by_user_id FROM ai_agents WHERE id = ?");
    $stmt->execute([$agentId]);
    $agente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agente) {
        echo json_encode(["error" => "Agente não encontrado."]);
        exit;
    }

    // Mesma checagem de dono do resto do fluxo de criação/edição — e só
    // agente DE USUÁRIO tem avatar por upload; os seis de sistema usam o
    // SVG vinculado em banco.sql, e trocar aquele por aqui fugiria da
    // conferência manual que aquele arquivo recebeu.
    if ((int)($agente["created_by_user_id"] ?? 0) !== $userId) {
        echo json_encode(["error" => "Você só pode trocar a foto do seu próprio agente."]);
        exit;
    }

    $novoNome = ai_store_agent_avatar($_FILES["avatar"], $agentId);

    $pdo->prepare("UPDATE ai_agents SET avatar = ? WHERE id = ?")->execute([$novoNome, $agentId]);

    // Só depois que o novo está gravado e servindo: apagar antes e a
    // gravação falhar deixaria o agente sem avatar nenhum.
    ai_delete_agent_avatar($agente["avatar"]);

    echo json_encode(["ok" => true, "avatar" => $novoNome], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    // Mensagem já pronta para o cliente (tamanho, formato) — não é falha
    // interna, é validação.
    echo json_encode(["error" => $e->getMessage()]);
} catch (Exception $e) {
    error_log("ai/agent_avatar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao enviar a imagem."]);
}
