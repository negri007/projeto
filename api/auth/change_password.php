<?php
/**
 * Troca de senha de quem já está logado.
 *
 * Diferente de `reset_password.php`, aqui não há token: a prova de que a
 * pessoa é dona da conta é a senha atual, que continua sendo exigida
 * mesmo com a sessão aberta — sessão roubada não deve virar conta
 * roubada.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require __DIR__ . "/db.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// Nenhuma das duas passa por trim: espaço no começo ou no fim é parte da
// senha, como em login.php e register.php.
$atual = (string)($data["current_password"] ?? "");
$nova  = (string)($data["new_password"] ?? "");

if ($atual === "" || $nova === "") {
    echo json_encode(["error" => "Informe a senha atual e a nova senha."]);
    exit;
}

if (mb_strlen($nova) < 8) {
    echo json_encode(["error" => "A senha precisa ter pelo menos 8 caracteres."]);
    exit;
}

if (mb_strlen($nova) > 72) {
    echo json_encode(["error" => "A senha é longa demais (máx. 72 caracteres)."]);
    exit;
}

if ($nova === $atual) {
    echo json_encode(["error" => "A nova senha precisa ser diferente da atual."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT name, password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($atual, $user["password_hash"])) {
        echo json_encode(["error" => "Senha atual incorreta."]);
        exit;
    }

    // Incrementar a versão derruba todas as sessões da conta — inclusive
    // esta. Por isso, logo abaixo, a sessão atual é reemitida já na
    // versão nova: quem trocou a senha continua logado aqui, e só aqui.
    $pdo->prepare(
        "UPDATE users
         SET password_hash = ?, session_version = session_version + 1
         WHERE id = ?"
    )->execute([password_hash($nova, PASSWORD_DEFAULT), $userId]);

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $versao = (int)$stmt->fetchColumn();

    start_user_session($userId, $user["name"], $versao);

    echo json_encode([
        "ok"      => true,
        "message" => "Senha alterada. As sessões abertas em outros navegadores foram encerradas.",
    ]);

} catch (Exception $e) {
    error_log("auth/change_password: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao alterar a senha."]);
}
