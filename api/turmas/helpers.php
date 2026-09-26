<?php
/**
 * Helpers do vertical academico (turma = circulo com tipo='academia').
 *
 * Nao e um endpoint: define funcoes usadas pelos arquivos de api/turmas/.
 * Ver docs/plans/grupos-verticais.md.
 *
 * Regras de seguranca (CLAUDE.md), validas em todo este modulo:
 * - Identidade vem SEMPRE da sessao (require_login / current_user_id).
 *   Nenhum endpoint aceita user_id/owner_id do cliente como identidade.
 * - Upload validado pelo MIME REAL (finfo), nunca pela extensao do cliente,
 *   e com limite de tamanho.
 * - Resposta de erro sempre {"error": "..."}; a mensagem do Throwable so
 *   vai para o log.
 */

require_once __DIR__ . "/../circles/helpers.php";

/** Teto de tamanho do material enviado. */
const TURMA_MATERIAL_MAX_BYTES = 20 * 1024 * 1024; // 20 MB

/** MIME real aceito -> extensao usada ao salvar. So o que da pra guardar
 *  com seguranca; resumir e outra conversa (ver turma_material_resumivel). */
const TURMA_MIME_EXT = [
    "application/pdf" => "pdf",
    "text/plain"      => "txt",
    "text/markdown"   => "md",
    "image/jpeg"      => "jpg",
    "image/png"       => "png",
    "image/webp"      => "webp",
];

/**
 * Pasta de uploads de uma turma (uploads/turmas/<circle_id>/), criada sob
 * demanda. Fica sob uploads/, que o .gitignore ignora e o .htaccess de
 * uploads impede de executar PHP — material enviado nao vira codigo.
 */
function turma_uploads_dir(int $circleId): string
{
    $dir = __DIR__ . "/../../uploads/turmas/" . $circleId;

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * Carrega a turma se o usuario tiver acesso (dono ou membro) E ela for do
 * tipo 'academia'. Devolve o array de circles_load_for_user() ou null.
 *
 * Circulo social, inexistente ou de terceiros devolvem o mesmo null: quem
 * nao participa nao descobre que a turma existe.
 */
function turma_load_for_user(PDO $pdo, int $circleId, int $userId): ?array
{
    $circle = circles_load_for_user($pdo, $circleId, $userId);

    if ($circle === null || ($circle["tipo"] ?? "social") !== "academia") {
        return null;
    }

    return $circle;
}

/**
 * Carrega um material se o usuario tiver acesso a turma dele. Devolve a
 * linha do material + `is_owner` (quem pode editar/subir e o dono da turma),
 * ou null.
 */
function turma_material_load(PDO $pdo, int $materialId, int $userId): ?array
{
    if ($materialId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT m.id, m.circle_id, m.owner_id, m.titulo, m.tipo_arquivo,
                m.arquivo, m.conteudo_texto, m.resumo, m.resumo_em, m.created_at
           FROM turma_materiais m
          WHERE m.id = ?"
    );
    $stmt->execute([$materialId]);
    $mat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mat) {
        return null;
    }

    // Acesso pela turma, sempre pela sessao — nunca por dado do cliente.
    $circle = turma_load_for_user($pdo, (int)$mat["circle_id"], $userId);

    if ($circle === null) {
        return null;
    }

    $mat["id"]        = (int)$mat["id"];
    $mat["circle_id"] = (int)$mat["circle_id"];
    $mat["owner_id"]  = (int)$mat["owner_id"];
    $mat["is_owner"]  = $circle["is_owner"];

    return $mat;
}

/**
 * Registra que o aluno abriu o material (sinal "nao abriu" do alerta de
 * risco). So aluno: a abertura do professor nao conta. INSERT IGNORE — a
 * primeira abertura fica, as seguintes nao mudam nada.
 */
function turma_registrar_view(PDO $pdo, array $material, int $userId): void
{
    if ($material["is_owner"]) {
        return;
    }

    $pdo->prepare("INSERT IGNORE INTO turma_material_views (material_id, user_id) VALUES (?, ?)")
        ->execute([$material["id"], $userId]);
}

/**
 * Valida um arquivo enviado ($_FILES[...]). Devolve
 * ["ok"=>true,"mime"=>..,"ext"=>..] ou ["ok"=>false,"erro"=>..].
 * O MIME vem do finfo (conteudo real), nunca de $file["type"].
 */
function turma_validar_arquivo(array $file): array
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"] ?? "")) {
        return ["ok" => false, "erro" => "Falha no envio do arquivo."];
    }

    if (($file["size"] ?? 0) > TURMA_MATERIAL_MAX_BYTES) {
        return ["ok" => false, "erro" => "Arquivo grande demais (máx. 20 MB)."];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);

    if (!isset(TURMA_MIME_EXT[$mime])) {
        return ["ok" => false, "erro" => "Tipo de arquivo não aceito. Use PDF, TXT, MD, JPG, PNG ou WEBP."];
    }

    return ["ok" => true, "mime" => $mime, "ext" => TURMA_MIME_EXT[$mime]];
}

/** Da pra gerar resumo deste material? So texto colado ou PDF, por ora.
 *  Imagem fica guardada, mas nao entra na sumarizacao v1. */
function turma_material_resumivel(array $material): bool
{
    if (trim((string)($material["conteudo_texto"] ?? "")) !== "") {
        return true;
    }

    return ($material["tipo_arquivo"] ?? "") === "pdf" && !empty($material["arquivo"]);
}

/** O system prompt do resumo — mesmo pra texto e PDF. */
function turma_resumo_system(): string
{
    return "Você é um assistente de estudos. Resuma o material de aula em português do Brasil, "
        . "para um aluno revisar. Use APENAS o que está no material; nunca invente fatos, dados ou "
        . "datas que não estejam nele. Estruture assim:\n"
        . "- Uma linha de TL;DR.\n"
        . "- 3 a 6 tópicos principais, em bullets curtos.\n"
        . "- Uma seção \"O que pode cair na prova\" com 2 a 4 pontos.\n"
        . "Seja claro e direto. Responda só o resumo, sem preâmbulo.";
}

/**
 * Gera o resumo de um material. Devolve ["ok"=>true,"resumo"=>..] ou
 * ["ok"=>false,"erro"=>..]. Nao grava nada — quem chama decide cachear.
 *
 * Dois caminhos, os dois pela chave do ai_config.php (mesma da rede de IA):
 * - texto colado -> ai_chamar_api (fluxo de texto ja provado).
 * - PDF          -> bloco `document` (base64) na Messages API, que le PDF
 *                   nativamente.
 * Sem chave configurada: erro amigavel, nada quebra.
 */
function turma_resumir_material(array $material): array
{
    if (!function_exists("ai_config")) {
        require_once __DIR__ . "/../ai/helpers.php";
    }

    $config = ai_config();

    if ($config === null) {
        return ["ok" => false, "erro" => "A IA não está configurada nesta instalação."];
    }

    if (!turma_material_resumivel($material)) {
        return ["ok" => false, "erro" => "Esse material ainda não pode ser resumido. Cole o texto ou envie um PDF."];
    }

    // Modelo Haiku (o `model` padrao do config) da conta de um resumo e e o
    // mais barato — mesma escolha das acoes de rotina da rede.
    $model   = $config["model"];
    $system  = turma_resumo_system();
    $timeout = max(60, (int)($config["timeout"] ?? 15)); // resumo demora mais que uma fala

    $texto = trim((string)($material["conteudo_texto"] ?? ""));

    if ($texto !== "") {
        // Corta um texto absurdo antes de mandar (teto de custo/token).
        $texto  = mb_substr($texto, 0, 40000);
        // false: o resumo e Markdown com bullets ("- "), que o filtro de
        // travessao da rede transformaria em virgulas.
        $resumo = ai_chamar_api($system, $texto, 1200, $timeout, 6000, $model, false);

        return $resumo !== null
            ? ["ok" => true, "resumo" => $resumo]
            : ["ok" => false, "erro" => "Não consegui gerar o resumo agora. Tente de novo."];
    }

    // PDF -> bloco document base64.
    $caminho = __DIR__ . "/../../uploads/" . $material["arquivo"];

    if (!is_file($caminho)) {
        return ["ok" => false, "erro" => "Arquivo do material não encontrado."];
    }

    $resumo = turma_resumir_pdf($caminho, $config, $system, $model, $timeout);

    return $resumo !== null
        ? ["ok" => true, "resumo" => $resumo]
        : ["ok" => false, "erro" => "Não consegui ler esse PDF. Tente colar o texto do material."];
}

/**
 * Chama a Messages API com o PDF como bloco `document` (base64). Devolve o
 * texto do resumo ou null. Espelha o cURL de ai_chamar_api(): mesma URL,
 * mesmos headers, a chave nunca vai para o log.
 */
function turma_resumir_pdf(string $caminho, array $config, string $system, string $model, int $timeout): ?string
{
    $bytes = @file_get_contents($caminho);

    if ($bytes === false || $bytes === "") {
        return null;
    }

    $corpo = json_encode([
        "model"      => $model,
        "max_tokens" => 1200,
        "system"     => $system,
        "messages"   => [[
            "role"    => "user",
            "content" => [
                [
                    "type"   => "document",
                    "source" => [
                        "type"       => "base64",
                        "media_type" => "application/pdf",
                        "data"       => base64_encode($bytes),
                    ],
                ],
                ["type" => "text", "text" => "Resuma este material de aula seguindo o formato pedido."],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            "content-type: application/json",
            "x-api-key: " . $config["api_key"],
            "anthropic-version: 2023-06-01",
        ],
    ]);

    $resposta = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $status !== 200 || $erroCurl !== "") {
        error_log("turma_resumir_pdf: HTTP $status " . ($erroCurl ?: substr((string)$resposta, 0, 200)));
        return null;
    }

    $dados = json_decode($resposta, true);
    $texto = "";

    foreach ($dados["content"] ?? [] as $bloco) {
        if (($bloco["type"] ?? "") === "text") {
            $texto .= $bloco["text"];
        }
    }

    $texto = trim($texto);

    return $texto !== "" ? mb_substr($texto, 0, 6000) : null;
}

/** Formata um material para a resposta JSON (sem despejar o texto inteiro
 *  nas listagens: `tem_texto` basta pra tela). */
function turma_material_row(array $m, bool $completo = false): array
{
    $row = [
        "id"           => (int)$m["id"],
        "circle_id"    => (int)$m["circle_id"],
        "titulo"       => $m["titulo"],
        "tipo_arquivo" => $m["tipo_arquivo"],
        "arquivo"      => $m["arquivo"] !== null && $m["arquivo"] !== "" ? $m["arquivo"] : null,
        "tem_texto"    => trim((string)($m["conteudo_texto"] ?? "")) !== "",
        "tem_resumo"   => trim((string)($m["resumo"] ?? "")) !== "",
        "resumivel"    => turma_material_resumivel($m),
        "created_at"   => $m["created_at"] ?? null,
    ];

    if ($completo) {
        $row["resumo"] = $m["resumo"] !== null && $m["resumo"] !== "" ? $m["resumo"] : null;
    }

    return $row;
}
