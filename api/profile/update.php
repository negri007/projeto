<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método inválido.']);
    exit;
}

$email = $_POST['email'] ?? '';
$name  = trim($_POST['name'] ?? '');
$bio   = trim($_POST['bio'] ?? '');

if (!$email || !$name) {
    echo json_encode(['error' => 'Email e nome são obrigatórios.']);
    exit;
}

// busca usuário
$stmt = $pdo->prepare("SELECT id, avatar FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

$userId         = (int)$user['id'];
$currentAvatar  = $user['avatar'] ?? null;
$newAvatarName  = $currentAvatar;

// upload de avatar (se enviado)
if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext     = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        echo json_encode(['error' => 'Formato de imagem inválido. Use jpg, png, gif ou webp.']);
        exit;
    }

    // garante pasta uploads/
    $uploadDir = __DIR__ . '/../../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newAvatarName = "avatar_" . $userId . "_" . time() . "." . $ext;
    $destPath      = $uploadDir . "/" . $newAvatarName;

    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destPath)) {
        echo json_encode(['error' => 'Falha ao enviar a imagem.']);
        exit;
    }
}

// atualiza usuário
$upd = $pdo->prepare("UPDATE users SET name = ?, bio = ?, avatar = ? WHERE id = ?");
$upd->execute([$name, $bio, $newAvatarName, $userId]);

echo json_encode(['ok' => true, 'message' => 'Perfil atualizado com sucesso.']);
