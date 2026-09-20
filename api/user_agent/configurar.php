<?php
/**
 * Cria ou atualiza o agente pessoal de quem está logado.
 *
 * A identidade vem sempre da sessão. Não existe `user_id` no corpo, nem
 * como conveniência: seria o caminho mais curto para alguém configurar o
 * agente de outra pessoa.
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

/** O que o usuário precisa digitar para liberar autonomia total. */
const USER_AGENT_FRASE_AUTONOMIA = "confirmo autonomia total";

try {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        echo json_encode(["error" => "Dados inválidos."]);
        exit;
    }

    $nome          = trim((string)($input["nome"] ?? ""));
    $personalidade = trim((string)($input["personalidade"] ?? ""));
    $autonomia     = (int)($input["autonomia"] ?? 0);
    $confirmacao   = trim((string)($input["confirmacao"] ?? ""));

    if ($nome === "") {
        echo json_encode(["error" => "Dê um nome ao seu Echo."]);
        exit;
    }

    if ($autonomia < 0 || $autonomia > 3) {
        echo json_encode(["error" => "Nível de autonomia inválido."]);
        exit;
    }

    /* O nível 3 exige a frase digitada, e a checagem é AQUI e não só na
       tela. Validação que mora só no JavaScript é decoração: qualquer um
       chama este endpoint direto. E o que está em jogo no nível 3 é o
       agente agir sem passar por ninguém. */
    if ($autonomia === 3) {
        $normalizada = mb_strtolower(preg_replace('/\s+/u', ' ', $confirmacao));

        if ($normalizada !== USER_AGENT_FRASE_AUTONOMIA) {
            echo json_encode([
                "error" => 'Para autonomia total, digite exatamente: confirmo autonomia total',
            ]);
            exit;
        }
    }

    $nome          = mb_substr($nome, 0, 100);
    $personalidade = mb_substr($personalidade, 0, 2000);

    if (user_agent_existe($pdo, $userId)) {
        $stmt = $pdo->prepare(
            "UPDATE user_agents SET nome = ?, personalidade = ?, autonomia = ? WHERE user_id = ?"
        );
        $stmt->execute([$nome, $personalidade, $autonomia, $userId]);
        $criado = false;
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO user_agents (user_id, nome, personalidade, autonomia) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $nome, $personalidade, $autonomia]);
        $criado = true;
    }

    echo json_encode([
        "ok"     => true,
        "criado" => $criado,
        "agente" => [
            "nome"          => $nome,
            "personalidade" => $personalidade,
            "autonomia"     => $autonomia,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/configurar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar o agente."]);
}
