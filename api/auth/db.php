<?php

header("Content-Type: application/json; charset=utf-8");

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=banco;charset=utf8mb4",
        "root",
        "" // SEM senha, igual ao test_db.php
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {
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
