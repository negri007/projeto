<?php
/**
 * Modelo de configuração do banco de dados.
 *
 * Copie para `api/auth/db_config.php` e preencha:
 *
 *     cp api/auth/db_config.example.php api/auth/db_config.php
 *
 * `db_config.php` está no .gitignore: senha de banco não entra no
 * repositório.
 *
 * Sem o arquivo, o sistema usa o padrão do XAMPP (root sem senha em
 * localhost/banco) SÓ em ambiente local — linha de comando (a menos que
 * ECHO_ENV=producao) ou acesso web vindo de 127.0.0.1/localhost. Num
 * servidor publicado, sem este arquivo o banco não conecta e o motivo vai
 * para o log. Ver api/auth/db_conexao.php.
 */

return [
    'host'    => 'localhost',
    'dbname'  => 'banco',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
];
