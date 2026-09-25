<?php

// Ver api/bootstrap.php: erro não capturado vira JSON de erro com 500, e não
// stack trace com caminho absoluto no corpo da resposta.
require_once __DIR__ . "/../bootstrap.php";

header("Content-Type: application/json; charset=utf-8");

// Credenciais: api/auth/db_config.php (fora do git). Sem ele, só em local
// cai no padrão do XAMPP — ver api/auth/db_credenciais.php.
require_once __DIR__ . "/db_credenciais.php";

try {
    $cred = echo_db_credenciais();

    if ($cred === null) {
        // O motivo já foi para o log; o cliente recebe o mesmo erro genérico.
        throw new RuntimeException("sem credenciais de banco");
    }

    $pdo = new PDO($cred["dsn"], $cred["user"], $cred["pass"]);
    unset($cred);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {
    error_log("db: não conectou — " . $e->getMessage());
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
