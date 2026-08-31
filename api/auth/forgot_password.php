<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data  = json_decode(file_get_contents("php://input"), true);
$email = trim((string)($data["email"] ?? ""));

// Resposta única, usada em todos os caminhos: e-mail inexistente,
// e-mail existente, falha de envio. Qualquer diferença aqui vira um
// oráculo de quais endereços estão cadastrados.
$resposta = json_encode([
    "ok"      => true,
    "message" => "Se o e-mail existir, um link foi enviado.",
]);

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo $resposta;
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo $resposta;
        exit;
    }

    $userId = (int)$user["id"];

    // Um pedido novo invalida os anteriores: só o último link funciona.
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")
        ->execute([$userId]);

    // O token puro só existe aqui e no e-mail; no banco fica o hash.
    // Vazamento do banco não devolve um link utilizável.
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash("sha256", $token);
    $expiresAt = date("Y-m-d H:i:s", time() + 3600);

    $pdo->prepare(
        "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
    )->execute([$userId, $tokenHash, $expiresAt]);

    $link = mailer_base_url() . "/reset.html?token=" . urlencode($token);

    $texto = "Olá, " . $user["name"] . ".\n\n"
           . "Recebemos um pedido para redefinir a senha da sua conta no Echo.\n"
           . "Abra o link abaixo para escolher uma senha nova. Ele vale por 1 hora "
           . "e só pode ser usado uma vez.\n\n"
           . $link . "\n\n"
           . "Se não foi você que pediu, ignore este e-mail: sua senha continua a mesma.\n";

    $html = "<p>Olá, " . htmlspecialchars($user["name"], ENT_QUOTES, "UTF-8") . ".</p>"
          . "<p>Recebemos um pedido para redefinir a senha da sua conta no Echo.</p>"
          . "<p><a href=\"" . htmlspecialchars($link, ENT_QUOTES, "UTF-8") . "\">"
          . "Escolher uma senha nova</a></p>"
          . "<p>O link vale por 1 hora e só pode ser usado uma vez.</p>"
          . "<p>Se não foi você que pediu, ignore este e-mail: sua senha continua a mesma.</p>";

    mailer_send($user["email"], $user["name"], "Redefinição de senha — Echo", $html, $texto);

} catch (Exception $e) {
    error_log("forgot_password: " . $e->getMessage());
}

echo $resposta;
