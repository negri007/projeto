<?php
/**
 * Envio de e-mail do Echo.
 *
 * Dois drivers:
 * - "log"  — grava a mensagem em `logs/mail.log` e não envia nada. É o
 *            padrão quando `api/auth/mail_config.php` não existe, para o
 *            sistema rodar sem credencial de SMTP nenhuma.
 * - "smtp" — envia de verdade, via PHPMailer (`lib/PHPMailer/`).
 *
 * Em qualquer um dos dois, `mailer_send()` devolve bool e **nunca**
 * propaga exceção: quem chama não pode revelar ao cliente se o envio
 * funcionou (ver forgot_password.php).
 */

/** Configuração efetiva, com os padrões de desenvolvimento aplicados. */
function mailer_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaults = [
        "driver"     => "log",
        "host"       => "",
        "port"       => 587,
        "username"   => "",
        "password"   => "",
        "encryption" => "tls",
        "from_email" => "nao-responda@echo.local",
        "from_name"  => "Echo",
        "base_url"   => "",
    ];

    $path = __DIR__ . "/mail_config.php";
    $file = is_file($path) ? require $path : [];

    $config = array_merge($defaults, is_array($file) ? $file : []);

    // Sem host ou sem usuário, SMTP não tem como funcionar: cai para o
    // log em vez de estourar erro no meio do fluxo do usuário.
    if ($config["driver"] === "smtp" && ($config["host"] === "" || $config["username"] === "")) {
        error_log("mailer: driver smtp sem host/usuário configurado; usando driver log.");
        $config["driver"] = "log";
    }

    return $config;
}

/**
 * Base pública do site (sem barra no fim), usada para montar links.
 * Se não estiver configurada, deduz da requisição atual.
 */
function mailer_base_url(): string
{
    $config = mailer_config();

    if ($config["base_url"] !== "") {
        return rtrim($config["base_url"], "/");
    }

    $scheme = !empty($_SERVER["HTTPS"]) ? "https" : "http";
    $host   = $_SERVER["HTTP_HOST"] ?? "127.0.0.1";

    return $scheme . "://" . $host;
}

/** Envia (ou registra) um e-mail. Devolve true em caso de sucesso. */
function mailer_send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $config = mailer_config();

    if ($config["driver"] === "log") {
        return mailer_log($toEmail, $toName, $subject, $text);
    }

    try {
        require_once __DIR__ . "/../../lib/PHPMailer/Exception.php";
        require_once __DIR__ . "/../../lib/PHPMailer/PHPMailer.php";
        require_once __DIR__ . "/../../lib/PHPMailer/SMTP.php";

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host     = $config["host"];
        $mail->Port     = (int)$config["port"];
        $mail->CharSet  = "UTF-8";

        if ($config["username"] !== "") {
            $mail->SMTPAuth = true;
            $mail->Username = $config["username"];
            $mail->Password = $config["password"];
        }

        if ($config["encryption"] === "tls") {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config["encryption"] === "ssl") {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->setFrom($config["from_email"], $config["from_name"]);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->send();

        return true;

    } catch (Throwable $e) {
        error_log("mailer: falha ao enviar e-mail: " . $e->getMessage());
        return false;
    }
}

/** Driver de desenvolvimento: grava a mensagem em `logs/mail.log`. */
function mailer_log(string $toEmail, string $toName, string $subject, string $text): bool
{
    $dir = __DIR__ . "/../../logs";

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log("mailer: não foi possível criar o diretório de log.");
        return false;
    }

    $entry = str_repeat("=", 70) . "\n"
           . "Data:     " . date("Y-m-d H:i:s") . "\n"
           . "Para:     " . $toName . " <" . $toEmail . ">\n"
           . "Assunto:  " . $subject . "\n"
           . str_repeat("-", 70) . "\n"
           . $text . "\n\n";

    return file_put_contents($dir . "/mail.log", $entry, FILE_APPEND | LOCK_EX) !== false;
}
