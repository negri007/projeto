<?php
/**
 * ROTA DESATIVADA — 2026-08-28
 *
 * Este endpoint trocava a senha de qualquer conta recebendo apenas
 * `email` + `new_pass`, sem token, sem sessão e sem qualquer prova de
 * posse do e-mail. Isso permitia sequestro de qualquer conta do sistema
 * por quem soubesse (ou adivinhasse) um endereço de e-mail cadastrado.
 *
 * A rota está desativada e responde HTTP 410 Gone. O fluxo correto de
 * recuperação de senha é, conforme docs/API_CONTRACT.md:
 *   POST /api/auth/forgot_password.php  -> envia link com token por e-mail
 *   POST /api/auth/reset_password.php   -> valida token e troca a senha
 *
 * Nenhum arquivo de front-end referencia esta rota. Este arquivo é
 * mantido apenas para que chamadas antigas recebam um erro explícito em
 * vez de um 404 ambíguo; pode ser removido depois que
 * reset_password.php estiver em produção.
 */

header("Content-Type: application/json; charset=utf-8");
http_response_code(410);
echo json_encode([
    "error" => "Rota desativada. Use /api/auth/forgot_password.php e /api/auth/reset_password.php."
]);
exit;

/* -----------------------------------------------------------------
 * Handler original (INSEGURO — não reativar):
 *
 * require "db.php";
 * $data = json_decode(file_get_contents("php://input"), true);
 * $email = trim($data["email"] ?? "");
 * $new_pass = trim($data["new_pass"] ?? "");
 * ... UPDATE users SET password_hash = ? WHERE email = ?
 * ----------------------------------------------------------------- */
