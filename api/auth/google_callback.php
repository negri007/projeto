<?php
/**
 * Volta do consentimento do Google. Troca o `code` por token, busca
 * e-mail/nome/id na Google, acha ou cria o usuário local e abre a sessão
 * — mesmo formato de sessão que login.php usa (start_user_session()).
 *
 * Navegação de página inteira: sempre termina em Location, nunca em
 * JSON. Falha aqui manda de volta pro login com `?google_error=1`, e o
 * detalhe real vai só pro log (mesma regra do resto da API — nunca
 * expor causa interna na resposta).
 */

require_once __DIR__ . "/session.php";
require __DIR__ . "/db.php";
require_once __DIR__ . "/google_vinculo.php";

function google_falhar(string $motivo): void
{
    error_log("[echo] google_callback: " . $motivo);
    header("Location: /index.html?google_error=1");
    exit;
}

$configPath = __DIR__ . "/google_config.php";

if (!file_exists($configPath)) {
    google_falhar("google_config.php ausente");
}

$config = require $configPath;

// Usuário cancelou o consentimento, ou a Google recusou.
if (!empty($_GET["error"])) {
    header("Location: /index.html");
    exit;
}

$state = $_GET["state"] ?? "";
$stateEsperado = $_SESSION["google_oauth_state"] ?? "";
unset($_SESSION["google_oauth_state"]);

if (!$state || !$stateEsperado || !hash_equals($stateEsperado, $state)) {
    google_falhar("state inválido ou ausente");
}

$code = $_GET["code"] ?? "";

if (!$code) {
    google_falhar("sem code");
}

// --- Troca o code por token de acesso ---------------------------------
$ch = curl_init("https://oauth2.googleapis.com/token");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        "code"          => $code,
        "client_id"     => $config["client_id"],
        "client_secret" => $config["client_secret"],
        "redirect_uri"  => $config["redirect_uri"],
        "grant_type"    => "authorization_code",
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$tokenRaw  = curl_exec($ch);
$tokenHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tokenErro = curl_error($ch);
curl_close($ch);

if ($tokenRaw === false) {
    google_falhar("curl token: " . $tokenErro);
}

$token = json_decode((string)$tokenRaw, true);

if ($tokenHttp !== 200 || empty($token["access_token"])) {
    google_falhar("token exchange HTTP {$tokenHttp}: " . $tokenRaw);
}

// --- Busca e-mail/nome/id na Google -------------------------------------
$ch = curl_init("https://www.googleapis.com/oauth2/v3/userinfo");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . $token["access_token"]],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$infoRaw  = curl_exec($ch);
$infoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$infoErro = curl_error($ch);
curl_close($ch);

if ($infoRaw === false) {
    google_falhar("curl userinfo: " . $infoErro);
}

$info = json_decode((string)$infoRaw, true);

if ($infoHttp !== 200 || empty($info["sub"]) || empty($info["email"])) {
    google_falhar("userinfo HTTP {$infoHttp}: " . $infoRaw);
}

if (empty($info["email_verified"])) {
    google_falhar("e-mail não verificado na Google: " . $info["email"]);
}

$googleId = (string)$info["sub"];
$email    = (string)$info["email"];
$name     = (string)($info["name"] ?? explode("@", $email)[0]);

try {
    $user = google_vincular_usuario($pdo, $googleId, $email, $name);

    if ($user === null) {
        google_falhar("e-mail já vinculado a outra conta Google: " . $email);
    }

    start_user_session($user["id"], $user["name"], $user["session_version"]);

    header("Location: /inicio.html");
    exit;

} catch (Exception $e) {
    google_falhar("erro de banco: " . $e->getMessage());
}
