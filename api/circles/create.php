<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

$data        = json_decode(file_get_contents("php://input"), true);
$name        = trim((string)($data["name"] ?? ""));
$description = trim((string)($data["description"] ?? ""));
$tipo        = trim((string)($data["tipo"] ?? "social"));

if (!in_array($tipo, CIRCLE_TIPOS, true)) {
    $tipo = "social";
}

if ($name === "") {
    echo json_encode(["error" => "Nome do círculo é obrigatório."]);
    exit;
}

// Limites das colunas em banco.sql.
if (mb_strlen($name) > 100) {
    echo json_encode(["error" => "Nome do círculo é longo demais (máx. 100 caracteres)."]);
    exit;
}

if (mb_strlen($description) > 255) {
    echo json_encode(["error" => "Descrição é longa demais (máx. 255 caracteres)."]);
    exit;
}

try {
    // Turma só para professor verificado (o selo do admin). Conferido no
    // servidor, pela sessão: esconder o botão no front não basta. Turma que
    // já existe não passa por aqui, então segue funcionando para o dono.
    if ($tipo === "academia") {
        $stmt = $pdo->prepare("SELECT professor_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        if ($stmt->fetchColumn() !== "verificado") {
            http_response_code(403);
            echo json_encode(["error" => "Só professor verificado pode criar turma."], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO circles (owner_id, name, tipo, description) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $name, $tipo, $description !== "" ? $description : null]);

    $circleId = (int)$pdo->lastInsertId();
    $circle   = circles_load_for_user($pdo, $circleId, $userId);

    // Círculo recém-criado ainda não tem membros além do dono, que não
    // entra em circle_members.
    echo json_encode([
        "ok"     => true,
        "circle" => circles_circle_row($circle, 0)
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao criar círculo."]);
}
