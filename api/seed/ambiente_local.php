<?php
/**
 * Trava dos seeds: só rodam em ambiente local.
 *
 * Os seeds criam 20 contas com a senha `senha123`, posts, lojas e vídeos
 * de demonstração. Rodados contra um banco de produção, deixariam contas
 * com senha conhecida por qualquer um que leia o repositório (público).
 * A trava de CLI (`PHP_SAPI`) impede a execução pela web, mas não impede
 * alguém de rodar `php api/seed/seed_completo.php` no servidor errado.
 *
 * Local, aqui, são as duas coisas juntas:
 *   - `ECHO_ENV` ausente ou "local" (qualquer outro valor é declaração de
 *     que o ambiente não é local — mesma convenção de auth/db_conexao.php);
 *   - o banco configurado está em loopback (localhost, 127.0.0.1, ::1).
 * Não há como forçar: quem precisa semear outro lugar semeia de dentro dele,
 * com o banco dele em loopback.
 *
 * Incluído no topo de api/seed/helpers_seed.php e de seed_demo_videos.php,
 * antes de qualquer conexão.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../auth/db_conexao.php";

/**
 * Motivo para recusar, ou null se o ambiente é local. Função pura: recebe
 * o config do banco e o valor de ECHO_ENV (false = variável ausente), para
 * poder ser testada sem mexer em arquivo nem em variável de ambiente.
 */
function seed_motivo_nao_local(?array $configBanco, string|false $echoEnv): ?string
{
    if ($echoEnv !== false && $echoEnv !== "" && strtolower($echoEnv) !== "local") {
        return "ECHO_ENV=\"{$echoEnv}\" declara que este ambiente não é local";
    }

    if ($configBanco === null) {
        return "não há configuração de banco utilizável (ver api/auth/db_config.php)";
    }

    $host = strtolower(trim((string)($configBanco["host"] ?? ""), "[] "));

    if (!in_array($host, ["localhost", "127.0.0.1", "::1"], true)) {
        return "o banco configurado (host \"{$host}\") não está nesta máquina";
    }

    return null;
}

/** Encerra o seed (código 1) se o ambiente não for local. */
function seed_exigir_ambiente_local(): void
{
    $motivo = seed_motivo_nao_local(echo_db_config(), getenv("ECHO_ENV"));

    if ($motivo !== null) {
        fwrite(STDERR, "Seed recusado: {$motivo}. Os seeds só rodam em ambiente local.\n");
        exit(1);
    }
}
