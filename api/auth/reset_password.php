<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data     = json_decode(file_get_contents("php://input"), true);
$token    = trim((string)($data["token"] ?? ""));
$password = (string)($data["new_password"] ?? "");

// Token inválido, expirado, já usado e ausente devolvem todos a mesma
// mensagem: quem tenta adivinhar não descobre em que pé está o token.
$erroToken = json_encode([
    "error" => "Link inválido ou expirado. Peça um novo e-mail de recuperação.",
]);

if ($token === "") {
    echo $erroToken;
    exit;
}

if (mb_strlen($password) < 8) {
    echo json_encode(["error" => "A senha precisa ter pelo menos 8 caracteres."]);
    exit;
}

if (mb_strlen($password) > 72) {
    echo json_encode(["error" => "A senha é longa demais (máx. 72 caracteres)."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.user_id
         FROM password_resets pr
         WHERE pr.token_hash = ?
           AND pr.used = 0
           AND pr.expires_at > NOW()
         ORDER BY pr.id DESC
         LIMIT 1"
    );
    $stmt->execute([hash("sha256", $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        echo $erroToken;
        exit;
    }

    $pdo->beginTransaction();

    // `session_version + 1` derruba toda sessão aberta com a senha
    // antiga, inclusive em outros navegadores: elas carregam a versão
    // velha e morrem na requisição seguinte (session_validate_version).
    $pdo->prepare(
        "UPDATE users
         SET password_hash = ?, session_version = session_version + 1
         WHERE id = ?"
    )->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset["user_id"]]);

    // Queima o token usado e todos os outros pendentes da mesma conta.
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?")
        ->execute([(int)$reset["user_id"]]);

    $pdo->commit();

    // Encerra a sessão de quem está redefinindo, para o próximo passo
    // ser um login com a senha nova. As sessões dos outros navegadores
    // caem sozinhas pelo `session_version` incrementado acima.
    destroy_user_session();

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("reset_password: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao redefinir a senha."]);
}
