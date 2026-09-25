<?php
/**
 * Sobe um material para uma turma. Só o dono da turma (professor).
 *
 * POST multipart/form-data:
 *   circle_id        (obrigatório)  id da turma (circulo tipo='academia')
 *   titulo           (obrigatório)  nome do material
 *   conteudo_texto   (opcional)     texto colado do material
 *   arquivo          (opcional)     PDF/TXT/MD/imagem (MIME real validado)
 * Pelo menos um entre conteudo_texto e arquivo.
 *
 * Resposta: { ok:true, material:{...} }.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $circleId = (int)($_POST["circle_id"] ?? 0);
    $titulo   = trim((string)($_POST["titulo"] ?? ""));
    $texto    = trim((string)($_POST["conteudo_texto"] ?? ""));

    $circle = turma_load_for_user($pdo, $circleId, $userId);

    if ($circle === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }

    if (!$circle["is_owner"]) {
        echo json_encode(["error" => "Só o professor da turma pode subir material."]);
        exit;
    }

    if ($titulo === "") {
        echo json_encode(["error" => "Dê um nome ao material."]);
        exit;
    }
    if (mb_strlen($titulo) > 160) {
        echo json_encode(["error" => "Nome do material é longo demais (máx. 160 caracteres)."]);
        exit;
    }

    $temArquivo = isset($_FILES["arquivo"]) && ($_FILES["arquivo"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($texto === "" && !$temArquivo) {
        echo json_encode(["error" => "Cole o texto do material ou envie um arquivo."]);
        exit;
    }

    $tipoArquivo = null;
    $relPath     = null;

    if ($temArquivo) {
        $val = turma_validar_arquivo($_FILES["arquivo"]);

        if (!$val["ok"]) {
            echo json_encode(["error" => $val["erro"]]);
            exit;
        }

        $dir  = turma_uploads_dir($circleId);
        $nome = uniqid("mat_", true) . "." . $val["ext"];
        $dest = $dir . "/" . $nome;

        if (!move_uploaded_file($_FILES["arquivo"]["tmp_name"], $dest)) {
            echo json_encode(["error" => "Não consegui salvar o arquivo."]);
            exit;
        }

        $tipoArquivo = $val["ext"];
        $relPath     = "turmas/" . $circleId . "/" . $nome; // relativo a uploads/
    }

    $stmt = $pdo->prepare(
        "INSERT INTO turma_materiais (circle_id, owner_id, titulo, tipo_arquivo, arquivo, conteudo_texto)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $circleId,
        $userId,
        $titulo,
        $tipoArquivo,
        $relPath,
        $texto !== "" ? $texto : null,
    ]);

    $materialId = (int)$pdo->lastInsertId();
    $material   = turma_material_load($pdo, $materialId, $userId);

    echo json_encode(["ok" => true, "material" => turma_material_row($material, true)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/material_criar: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao salvar o material."]);
}
