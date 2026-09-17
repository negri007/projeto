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

// Trata erro não capturado antes de qualquer coisa: sem isto um fatal vira
// stack trace no corpo da resposta. Ver api/bootstrap.php.
require_once __DIR__ . "/../bootstrap.php";

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
 *
 * `$sessionVersion` é a versão que valia no momento do login (coluna
 * `users.session_version`). Ver session_validate_version().
 */
function start_user_session(int $userId, string $userName, int $sessionVersion = 1): void
{
    session_regenerate_id(true);
    $_SESSION["user_id"]         = $userId;
    $_SESSION["user_name"]       = $userName;
    $_SESSION["session_version"] = $sessionVersion;
}

/**
 * Derruba a sessão quando ela ficou para trás da versão gravada no
 * banco.
 *
 * Trocar a senha incrementa `users.session_version`; as sessões abertas
 * em outros navegadores continuam existindo no disco, mas carregam a
 * versão antiga e morrem na primeira requisição que fizerem. É o que
 * torna "trocar a senha" um gesto que expulsa quem estava logado com a
 * senha velha.
 *
 * É chamada por `api/auth/db.php`, logo depois de abrir a conexão, para
 * valer em todo endpoint sem precisar de uma linha em cada um.
 *
 * Falha na consulta (banco fora do ar, coluna ainda não migrada) não
 * derruba ninguém: a sessão segue como estava e o erro vai para o log.
 */
function session_validate_version(PDO $pdo): void
{
    $userId = current_user_id();

    if ($userId === null) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("session_validate_version(): " . $e->getMessage());
        return;
    }

    // Usuário apagado: quem trata é o me.php, que sabe responder 401.
    if (!$row) {
        return;
    }

    $versaoAtual = (int)$row["session_version"];

    // Sessão aberta antes desta coluna existir: adota a versão atual em
    // vez de expulsar todo mundo que estava logado na hora do deploy.
    if (!isset($_SESSION["session_version"])) {
        $_SESSION["session_version"] = $versaoAtual;
        return;
    }

    if ((int)$_SESSION["session_version"] !== $versaoAtual) {
        destroy_user_session();
    }
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

/**
 * Solta o arquivo de sessão sem perder quem está logado.
 *
 * O PHP segura um lock EXCLUSIVO no arquivo da sessão de `session_start()`
 * até o fim do script. Duas requisições do mesmo navegador nunca rodam ao
 * mesmo tempo por causa disso: a segunda fica parada no `session_start()`
 * esperando a primeira terminar — mesmo em Apache, mesmo com processos
 * sobrando, porque o gargalo é o arquivo e não o servidor.
 *
 * Isso apareceu como "trocar de página está pesado". Medido: `tick.php`
 * leva 1,5s (chama a API da Anthropic no meio), e é disparado em
 * fire-and-forget por `inicio.html`, `explorar.html` e `rede_ia.html`. Os
 * `posts/list.php`, `hashtags/trending.php` e `circles/list.php` da MESMA
 * página começavam em 54ms e só terminavam em 4,4s — não porque fossem
 * lentos (via curl, os cinco juntos dão 146ms), mas porque estavam na fila
 * atrás do lock que o tick segurava.
 *
 * Chamar isto logo depois de `require_login()` resolve: o `$_SESSION` já
 * foi lido, `$userId` já está na mão, e a página inteira volta a carregar
 * em paralelo. Só serve para endpoint que LÊ a sessão e não escreve nela
 * depois — escrever em `$_SESSION` depois daqui não é gravado.
 *
 * Cuidado com a ordem: `api/auth/db.php` chama `session_validate_version()`,
 * que pode gravar em `$_SESSION`. Sempre inclua o db.php ANTES de soltar.
 */
function liberar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
