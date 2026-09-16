<?php
/**
 * Início do login com Google (Authorization Code flow).
 *
 * Navegação de página inteira, não fetch: o front só faz
 * `window.location = "api/auth/google_login.php"`. Redireciona para o
 * consentimento do Google, que devolve o controle para
 * google_callback.php com o `code`.
 *
 * `state` é o CSRF do OAuth: aleatório, guardado na sessão aqui, e
 * conferido no callback antes de trocar qualquer coisa com o Google.
 */

require_once __DIR__ . "/session.php";

$configPath = __DIR__ . "/google_config.php";

if (!file_exists($configPath)) {
    http_response_code(503);
    header("Content-Type: text/plain; charset=utf-8");
    echo "Login com Google não configurado neste ambiente.";
    exit;
}

$config = require $configPath;

if (empty($config["client_id"]) || empty($config["client_secret"]) || empty($config["redirect_uri"])) {
    http_response_code(503);
    header("Content-Type: text/plain; charset=utf-8");
    echo "Login com Google não configurado neste ambiente.";
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION["google_oauth_state"] = $state;

$params = http_build_query([
    "client_id"     => $config["client_id"],
    "redirect_uri"  => $config["redirect_uri"],
    "response_type" => "code",
    "scope"         => "openid email profile",
    "state"         => $state,
    "prompt"        => "select_account",
]);

header("Location: https://accounts.google.com/o/oauth2/v2/auth?" . $params);
exit;
