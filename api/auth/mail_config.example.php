<?php
/**
 * Configuração de envio de e-mail — MODELO.
 *
 * Copie para `api/auth/mail_config.php` e preencha. O arquivo real está
 * no `.gitignore`: **credenciais de SMTP nunca entram no repositório.**
 *
 * Sem `mail_config.php`, o sistema roda no driver "log": o e-mail não é
 * enviado, é gravado em `logs/mail.log`. É o suficiente para testar o
 * fluxo inteiro de recuperação de senha em desenvolvimento — o link com
 * o token aparece no arquivo.
 */

return [
    // "log"  = grava em logs/mail.log (padrão de desenvolvimento)
    // "smtp" = envia de verdade via PHPMailer
    "driver" => "smtp",

    "host"       => "sandbox.smtp.mailtrap.io",
    "port"       => 2525,
    "username"   => "",
    "password"   => "",
    // "tls", "ssl" ou "" (sem criptografia)
    "encryption" => "tls",

    "from_email" => "nao-responda@echo.local",
    "from_name"  => "Echo",

    // Base pública do site, usada para montar o link do e-mail.
    // Sem barra no fim.
    "base_url"   => "http://127.0.0.1:8123",
];
