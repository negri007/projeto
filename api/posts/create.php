<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/../auth/db.php";

try {
  
    $email   = trim($_POST["email"]   ?? "");
    $content = trim($_POST["content"] ?? "");

    if ($email === "" && $content === "" && empty($_FILES["image"]["name"])) {
        echo json_encode(["error" => "Envie texto ou uma imagem."]);
        exit;
    }

   
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["error" => "Usuário não encontrado."]);
        exit;
    }

    $imageName = null;

    
    if (!empty($_FILES["image"]["name"])) {
        $uploadDir = __DIR__ . "/../../uploads";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
        $allowed = ["jpg", "jpeg", "png", "gif", "webp"];

        if (!in_array($ext, $allowed)) {
            echo json_encode(["error" => "Formato de imagem inválido."]);
            exit;
        }

        $imageName = uniqid("img_", true) . "." . $ext;
        $destPath  = $uploadDir . "/" . $imageName;

        if (!move_uploaded_file($_FILES["image"]["tmp_name"], $destPath)) {
            echo json_encode(["error" => "Erro ao salvar a imagem."]);
            exit;
        }
    }

   
    $stmt = $pdo->prepare(
        "INSERT INTO posts (user_id, content, image) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user["id"], $content, $imageName]);

    echo json_encode(["ok" => true]);

} catch (Exception $e) {
    echo json_encode(["error" => "Erro ao criar post."]);
}
