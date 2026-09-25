<?php
/**
 * Configuração do banco de dados — MODELO.
 *
 * Copie para `api/auth/db_config.php` e preencha. O arquivo real está no
 * `.gitignore`: **credencial de banco nunca entra no repositório.**
 *
 *     cp api/auth/db_config.example.php api/auth/db_config.php
 *
 * Sem `db_config.php`:
 *   - em ambiente LOCAL (linha de comando, ou servidor e visitante em
 *     127.0.0.1/::1), usa o padrão do XAMPP — `root` sem senha no banco
 *     `banco` — e avisa no log;
 *   - fora dele, NÃO conecta: registra no log que o arquivo falta e a API
 *     responde "Erro ao conectar ao banco de dados." (500).
 * Para forçar o modo de produção mesmo numa máquina local, defina a
 * variável de ambiente ECHO_ENV com qualquer valor diferente de "local".
 */

return [
    "host"    => "localhost",
    "port"    => 3306,
    "dbname"  => "banco",
    "user"    => "root",
    "pass"    => "",
    "charset" => "utf8mb4",
];
