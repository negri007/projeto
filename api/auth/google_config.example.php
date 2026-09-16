<?php
/**
 * Configuração do login com Google — MODELO.
 *
 * Copie para `api/auth/google_config.php` e preencha com as credenciais
 * do Google Cloud Console (Google Auth Platform → Clientes → tipo "App
 * da Web"). O arquivo real está no `.gitignore`: **client_secret nunca
 * entra no repositório.**
 *
 * Sem `google_config.php`, api/auth/google_login.php responde erro em
 * vez de redirecionar — o botão "Entrar com Google" simplesmente não
 * funciona, o resto do sistema continua normal.
 */

return [
    "client_id"     => "",
    "client_secret" => "",

    // Tem que ser IDÊNTICO ao cadastrado em "URIs de redirecionamento
    // autorizados" no Google Cloud — path incluído, sem barra no fim.
    "redirect_uri"  => "http://127.0.0.1:8123/api/auth/google_callback.php",
];
