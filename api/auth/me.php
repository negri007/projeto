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
    $stmt = $pdo->prepare("SELECT id, name, email, is_admin, professor_status FROM users WHERE id = ?");
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

echo json_encode([
    "authenticated" => true,
    "user" => [
        "id"         => (int)$user["id"],
        "name"       => $user["name"],
        "email"      => $user["email"],
        // Papel de plataforma: admin (aprova professores) e o selo de
        // professor verificado. O front usa pra mostrar o selo e a tela
        // de admin — a autorização real fica sempre no servidor.
        "is_admin"         => (bool)$user["is_admin"],
        "professor_status" => $user["professor_status"] ?? "nenhum"
    ]
]);
