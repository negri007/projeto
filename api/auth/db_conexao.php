<?php
/**
 * Conexão com o banco — de onde vêm host, banco, usuário e senha.
 *
 * As credenciais moram em `api/auth/db_config.php` (fora do git, modelo em
 * `db_config.example.php`), mesmo padrão de ai_config/video_config.
 *
 * Sem o arquivo, cai no padrão do XAMPP (root sem senha em localhost/banco),
 * mas SÓ em ambiente local:
 *   - linha de comando: local, a menos que ECHO_ENV=producao;
 *   - web: só com REMOTE_ADDR e SERVER_NAME de loopback / "localhost".
 * Fora disso, não conecta: grava o motivo no log e o chamador responde o erro
 * padrão de banco. Um servidor publicado sem o arquivo nunca tenta root sem
 * senha.
 *
 * Usado por api/auth/db.php (todos os endpoints) e api/ai/validar_corpus.php.
 */

/** O padrão do XAMPP, válido só em ambiente local. */
const ECHO_DB_PADRAO_LOCAL = [
    "host"    => "localhost",
    "dbname"  => "banco",
    "user"    => "root",
    "pass"    => "",
    "charset" => "utf8mb4",
];

function echo_db_ambiente_local(): bool
{
    if (getenv("ECHO_ENV") === "producao") {
        return false;
    }

    if (PHP_SAPI === "cli") {
        return true;
    }

    $loopback = ["127.0.0.1", "::1", "::ffff:127.0.0.1"];
    $remoto   = (string)($_SERVER["REMOTE_ADDR"] ?? "");
    $servidor = strtolower((string)($_SERVER["SERVER_NAME"] ?? ""));

    return in_array($remoto, $loopback, true)
        && (in_array($servidor, $loopback, true) || $servidor === "localhost" || $servidor === "[::1]");
}

/**
 * Configuração do banco, ou null quando não há arquivo e o ambiente não é
 * local (o motivo vai para o log).
 */
function echo_db_config(): ?array
{
    $arquivo = __DIR__ . "/db_config.php";

    if (is_file($arquivo)) {
        $config = require $arquivo;
        if (!is_array($config)) {
            error_log("echo_db_config: api/auth/db_config.php não devolve um array");
            return null;
        }
        return array_merge(ECHO_DB_PADRAO_LOCAL, $config);
    }

    if (echo_db_ambiente_local()) {
        return ECHO_DB_PADRAO_LOCAL;
    }

    error_log("echo_db_config: api/auth/db_config.php ausente fora do ambiente local "
        . "(copie db_config.example.php e preencha); a conexão com o banco foi recusada");
    return null;
}

/** Abre a conexão. Lança exceção se não houver configuração ou o banco recusar. */
function echo_db_conectar(): PDO
{
    $c = echo_db_config();
    if ($c === null) {
        throw new RuntimeException("sem configuração de banco");
    }

    $pdo = new PDO(
        "mysql:host={$c["host"]};dbname={$c["dbname"]};charset={$c["charset"]}",
        (string)$c["user"],
        (string)$c["pass"]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
