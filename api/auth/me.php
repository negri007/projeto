<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";

$userId = current_user_id();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

require __DIR__ . "/db.php";

// db.php confere a versão da sessão e pode tê-la derrubado no caminho
// (senha trocada em outro lugar). Reler aqui é o que faz esta rota
// responder 401 em vez de confirmar uma sessão que já não vale.
if (current_user_id() === null) {
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, ai_credits FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Erro ao carregar usuário."]);
    exit;
}

// Sessão apontando para um usuário que não existe mais: derruba a sessão.
if (!$user) {
    destroy_user_session();
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

/* Todo mundo ganha um agente na primeira visita, em autonomia 0 -- o
   nivel em que ele SO OBSERVA e nao faz nada. Criar aqui, e nao numa
   tela de cadastro, e o que faz o agente ter memoria do dono desde o
   primeiro post, em vez de comecar vazio no dia em que a pessoa
   descobrir a aba.

   `user_agent_criar()` e idempotente (INSERT IGNORE sobre a chave unica
   de user_id): duas abas abertas ao mesmo tempo nao viram erro. E nunca
   lanca, entao banco fora do ar nao derruba o login. */
require_once __DIR__ . "/../user_agent/helpers.php";

$temAgente = user_agent_existe($pdo, $userId);

if (!$temAgente) {
    $temAgente = user_agent_criar($pdo, $userId, (string)$user["name"]);
}

echo json_encode([
    "authenticated" => true,
    // Campo NOVO; o resto do contrato desta rota nao mudou.
    "tem_agente"    => (bool)$temAgente,
    "user" => [
        "id"         => (int)$user["id"],
        "name"       => $user["name"],
        "email"      => $user["email"],
        // Saldo da moeda da rede de IA — não é dado sensível, e expor
        // aqui evita uma chamada própria só pra tela mostrar o saldo.
        "ai_credits" => (int)$user["ai_credits"]
    ]
]);
