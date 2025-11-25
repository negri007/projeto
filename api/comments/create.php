<?php
header("Content-Type: application/json; charset=utf-8");

// conexão com o mesmo db.php do auth
require_once __DIR__ . '/../auth/db.php';

// só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método inválido.']);
    exit;
}

// lê JSON enviado
$data = json_decode(file_get_contents("php://input"), true);

$post_id = (int)($data['post_id'] ?? 0);
$body    = trim($data['body'] ?? '');
$email   = trim($data['email'] ?? ''); // vamos usar o email do usuário logado

if (!$post_id || !$body || !$email) {
    echo json_encode(['error' => 'post_id, comentário e email são obrigatórios.']);
    exit;
}

// busca o user_id pelo email
$stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

$user_id = (int)$user['id'];

// insere comentário
$sql = "INSERT INTO comments (post_id, user_id, body, created_at) 
        VALUES (:post_id, :user_id, :body, NOW())";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':post_id', $post_id);
$stmt->bindParam(':user_id', $user_id);
$stmt->bindParam(':body', $body);

if ($stmt->execute()) {
    echo json_encode([
        'ok'      => true,
        'message' => 'Comentário criado com sucesso.'
    ]);
} else {
    echo json_encode(['error' => 'Erro ao criar comentário.']);
}
