<?php
/**
 * Sessão do Echo.
 *
 * Incluir no topo de todo endpoint protegido:
 *     require_once __DIR__ . "/../auth/session.php";
 *     $userId = require_login();
 *
 * O usuário autenticado vem sempre da sessão (cookie PHPSESSID). Nenhum
 * endpoint deve mais aceitar `email` ou `user_id` vindos do cliente como
 * identidade — ver docs/API_CONTRACT.md.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "httponly" => true,
        "samesite" => "Lax",
        "secure"   => !empty($_SERVER["HTTPS"]),
    ]);
    session_start();
}

/**
 * Id do usuário logado, ou null se não houver sessão.
 */
function current_user_id(): ?int
{
    return isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : null;
}

/**
 * Nome do usuário logado, ou null.
 */
function current_user_name(): ?string
{
    return $_SESSION["user_name"] ?? null;
}

/**
 * Exige sessão ativa. Sem sessão: HTTP 401 + {"error": "Não autenticado."}
 * e encerra a requisição. Com sessão: devolve o id do usuário.
 */
function require_login(): int
{
    $userId = current_user_id();

    if ($userId === null) {
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=utf-8");
        }
        http_response_code(401);
        echo json_encode(["error" => "Não autenticado."]);
        exit;
    }

    return $userId;
}

/**
 * Grava a identidade do usuário na sessão (usado pelo login).
 * Regenera o id de sessão para evitar fixação de sessão.
 */
function start_user_session(int $userId, string $userName): void
{
    session_regenerate_id(true);
    $_SESSION["user_id"]   = $userId;
    $_SESSION["user_name"] = $userName;
}

/**
 * Destrói a sessão atual e apaga o cookie correspondente.
 */
function destroy_user_session(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            [
                "expires"  => time() - 42000,
                "path"     => $params["path"],
                "domain"   => $params["domain"],
                "secure"   => $params["secure"],
                "httponly" => $params["httponly"],
                "samesite" => $params["samesite"] ?? "Lax",
            ]
        );
    }

    session_destroy();
}
