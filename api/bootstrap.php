<?php
/**
 * Bootstrap de erro dos endpoints.
 *
 * A convenção do projeto (CLAUDE.md) é nunca devolver `$e->getMessage()` ao
 * cliente. Ela vale para exceção CAPTURADA — e é seguida em todo lugar. O que
 * passava por baixo dela era o erro NÃO capturado: com `display_errors` ligado,
 * um TypeError virava resposta HTTP.
 *
 * Aconteceu de verdade, e está no `php_server.log` de 10/09:
 *
 *     [200]: POST /api/comments/create.php - Uncaught TypeError:
 *     comments_comment_row(): Argument #1 ($row) must be of type array, bool
 *     given, called in C:\Users\...\api\comments\create.php on line 66
 *
 * Três coisas erradas numa linha: o caminho absoluto do disco foi para o
 * cliente, o corpo da resposta deixou de ser JSON, e o status saiu 200 — o
 * front não tinha como saber que aquilo era falha.
 *
 * Este arquivo fecha os três. É incluído por `auth/session.php` e por
 * `auth/db.php`, que juntos cobrem todo endpoint do projeto.
 */

if (defined("ECHO_BOOTSTRAP")) {
    return;
}
define("ECHO_BOOTSTRAP", 1);

// O detalhe vai para o log do PHP, nunca para a resposta.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
error_reporting(E_ALL);

/**
 * Resposta de falha, no mesmo formato de erro do resto da API.
 *
 * O buffer aberto no fim deste arquivo é descartado antes: se o fatal
 * aconteceu no meio de um `echo`, o que já tinha saído é lixo, e emendar JSON
 * nele daria um corpo impossível de ler dos dois lados.
 */
function echo_falhar(string $detalhe, string $publico = "Erro inesperado no servidor."): void
{
    error_log("[echo] " . $detalhe);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
    }

    echo json_encode(["error" => $publico], JSON_UNESCAPED_UNICODE);
}

set_exception_handler(function (Throwable $e): void {
    echo_falhar(
        get_class($e) . ": " . $e->getMessage()
        . " em " . $e->getFile() . ":" . $e->getLine()
    );
});

/**
 * Fatal de verdade (E_ERROR e parentes) não passa pelo handler de exceção:
 * só o shutdown vê. Erro não fatal segue como sempre — vai para o log e a
 * resposta continua normal.
 */
register_shutdown_function(function (): void {
    $e = error_get_last();
    $fatais = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    if ($e !== null && ($e["type"] & $fatais)) {
        echo_falhar($e["message"] . " em " . $e["file"] . ":" . $e["line"]);
        return;
    }

    // Caminho normal: entrega o que o endpoint escreveu.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
});

// Segurar a saída é o que permite trocar o corpo e ainda mandar o status 500
// quando o erro aparece no meio da resposta.
ob_start();
