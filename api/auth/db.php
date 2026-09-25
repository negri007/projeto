<?php

// Ver api/bootstrap.php: erro não capturado vira JSON de erro com 500, e não
// stack trace com caminho absoluto no corpo da resposta.
require_once __DIR__ . "/../bootstrap.php";

require_once __DIR__ . "/db_conexao.php";

header("Content-Type: application/json; charset=utf-8");

try {
    // Credenciais de api/auth/db_config.php; sem ele, padrão do XAMPP só em
    // ambiente local (ver db_conexao.php).
    $pdo = echo_db_conectar();

} catch (Exception $e) {
    error_log("db.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Erro ao conectar ao banco de dados."
        // se quiser ver o erro real pra debug:
        // "debug" => $e->getMessage()
    ]);
    exit;
}

// Sessão de senha antiga morre aqui, antes de qualquer endpoint agir.
// Fica neste arquivo, e não em cada endpoint, porque todo endpoint que
// toca o banco já inclui este — uma linha esquecida numa rota nova seria
// uma rota que aceita sessão revogada.
if (function_exists("session_validate_version")) {
    session_validate_version($pdo);
}
