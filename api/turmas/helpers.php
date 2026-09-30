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

/**
 * URL por onde o arquivo do material sai, ou null se ele não tem arquivo.
 *
 * O arquivo NÃO é servido direto de uploads/ — `uploads/turmas/` é negada
 * no .htaccess da raiz. Sai por material_arquivo.php, que confere a turma
 * pela sessão a cada pedido: saber a URL não dá acesso a nada.
 */
function turma_material_url(array $material): ?string
{
    if (($material["arquivo"] ?? null) === null || $material["arquivo"] === "") {
        return null;
    }

    return "api/turmas/material_arquivo.php?material_id=" . (int)$material["id"];
}

/**
 * Caminho absoluto do arquivo do material em disco, ou null se não existe
 * ou escapa de uploads/turmas/. O caminho vem do banco, gravado por
 * material_criar.php — a conferência com realpath() é a defesa para o dia
 * em que alguém gravar ali algo que não veio de lá.
 */
function turma_material_caminho(array $material): ?string
{
    $rel = (string)($material["arquivo"] ?? "");

    if ($rel === "") {
        return null;
    }

    $base    = realpath(__DIR__ . "/../../uploads/turmas");
    $caminho = realpath(__DIR__ . "/../../uploads/" . $rel);

    if ($base === false || $caminho === false || !is_file($caminho)
        || strpos($caminho, $base . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    return $caminho;
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

/** Resumo: teto de tokens da resposta, e de caracteres do texto guardado. */
const TURMA_RESUMO_MAX_TOKENS = 1200;
const TURMA_RESUMO_MAX_CHARS  = 6000;

/**
 * Gera o resumo de um material. Devolve ["ok"=>true,"resumo"=>..] ou
 * ["ok"=>false,"erro"=>..] — com a cota da pessoa estourada, também
 * "espera" (segundos), para o endpoint responder 429. Nao grava nada —
 * quem chama decide cachear.
 *
 * Dois conteúdos, uma chamada só, pelo cliente único ai_api_mensagens():
 * - texto colado -> bloco de texto (cortado em 40000 caracteres);
 * - PDF          -> bloco `document` (base64), que a Messages API lê
 *                   nativamente.
 * A cota é da PESSOA que pediu (aluno abrindo o primeiro resumo ou
 * professor regerando): o cliente confere e registra — o endpoint não
 * cobra mais à parte. Sem chave configurada: erro amigavel, nada gasto.
 */
function turma_resumir_material(array $material, PDO $pdo, int $userId): array
{
    if (!function_exists("ai_config")) {
        require_once __DIR__ . "/../ai/helpers.php";
    }
    require_once __DIR__ . "/../ai/limite_uso.php";

    $config = ai_config();

    if ($config === null) {
        return ["ok" => false, "erro" => "A IA não está configurada nesta instalação."];
    }

    if (!turma_material_resumivel($material)) {
        return ["ok" => false, "erro" => "Esse material ainda não pode ser resumido. Cole o texto ou envie um PDF."];
    }

    $texto = trim((string)($material["conteudo_texto"] ?? ""));
    $doPdf = $texto === "";

    if ($doPdf) {
        $caminho = __DIR__ . "/../../uploads/" . $material["arquivo"];
        $bytes   = is_file($caminho) ? @file_get_contents($caminho) : false;

        if ($bytes === false || $bytes === "") {
            return ["ok" => false, "erro" => "Arquivo do material não encontrado."];
        }

        $conteudo = [
            ["type" => "document", "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => base64_encode($bytes)]],
            ["type" => "text", "text" => "Resuma este material de aula seguindo o formato pedido."],
        ];
    } else {
        // Corta um texto absurdo antes de mandar (teto de custo/token).
        $conteudo = mb_substr($texto, 0, 40000);
    }

    // Modelo Haiku (o `model` padrao do config) da conta de um resumo e e o
    // mais barato — mesma escolha das acoes de rotina da rede.
    $r = ai_api_mensagens([
        "model"      => $config["model"],
        "max_tokens" => TURMA_RESUMO_MAX_TOKENS,
        "system"     => turma_resumo_system(),
        "messages"   => [["role" => "user", "content" => $conteudo]],
    ], [
        "cota"    => ["tipo" => "pessoa", "pdo" => $pdo, "user_id" => $userId, "teto" => AI_ACOES_TURMA_POR_HORA],
        "timeout" => max(60, (int)($config["timeout"] ?? 15)), // resumo demora mais que uma fala
        "rotulo"  => $doPdf ? "turma_resumir_material(pdf)" : "turma_resumir_material(texto)",
    ]);

    if ($r["erro"] === "cota") {
        return ["ok" => false, "erro" => ai_cota_mensagem($r["espera"]), "espera" => $r["espera"]];
    }

    // O texto colado tirava aspas em volta (como toda fala); o PDF, não —
    // mantido como era em cada caminho.
    $resumo = $r["ok"] ? trim($r["texto"], $doPdf ? " \n\r\t" : "\"\u{201C}\u{201D} \n\r\t") : "";

    if ($resumo === "") {
        return ["ok" => false, "erro" => $doPdf
            ? "Não consegui ler esse PDF. Tente colar o texto do material."
            : "Não consegui gerar o resumo agora. Tente de novo."];
    }

    return ["ok" => true, "resumo" => mb_substr($resumo, 0, TURMA_RESUMO_MAX_CHARS)];
}

/* ----------------------------------------------------------------------
   Entrada de aluno na turma (Fase 2). Três formas coexistem: o professor
   adiciona (A, em api/circles/add_member.php), convite por código (B) e
   pedido com aprovação (C). As funções abaixo são a parte comum.
   -------------------------------------------------------------------- */

/** Alfabeto do código de convite, sem caracteres ambíguos (0/O, 1/I). */
const TURMA_CODIGO_ALFABETO = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
const TURMA_CODIGO_TAM      = 8;

/** Gera um código de convite único (não colide com nenhum já em uso). */
function turma_gerar_codigo(PDO $pdo): string
{
    $alfa = TURMA_CODIGO_ALFABETO;
    $max  = strlen($alfa) - 1;

    for ($tentativa = 0; $tentativa < 12; $tentativa++) {
        $cod = "";
        for ($i = 0; $i < TURMA_CODIGO_TAM; $i++) {
            $cod .= $alfa[random_int(0, $max)];
        }

        $stmt = $pdo->prepare("SELECT 1 FROM circles WHERE codigo_convite = ? LIMIT 1");
        $stmt->execute([$cod]);

        if (!$stmt->fetch()) {
            return $cod;
        }
    }

    // Praticamente impossível chegar aqui; some o timestamp pra garantir.
    return substr($cod, 0, 4) . substr((string)time(), -4);
}

/**
 * Turma (círculo academia) de um código de convite, ou null. O código é
 * normalizado (maiúsculas, sem espaço) antes da busca.
 */
function turma_por_codigo(PDO $pdo, string $codigo): ?array
{
    $codigo = strtoupper(trim($codigo));

    if ($codigo === "") {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, owner_id, name, aceita_pedidos FROM circles
          WHERE codigo_convite = ? AND tipo = 'academia' LIMIT 1"
    );
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Já é membro da turma (não conta o dono)? */
function turma_ja_membro(PDO $pdo, int $circleId, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$circleId, $userId]);
    return (bool)$stmt->fetch();
}

/**
 * Registra um pedido de entrada (forma C). Devolve "pendente" (criado ou já
 * existia esperando), "ja_membro", "dono" ou "recusado_antes" (foi recusado
 * e pode pedir de novo -> reabre como pendente).
 */
function turma_criar_pedido(PDO $pdo, int $circleId, int $ownerId, int $userId): string
{
    if ($userId === $ownerId) {
        return "dono";
    }
    if (turma_ja_membro($pdo, $circleId, $userId)) {
        return "ja_membro";
    }

    // Um pedido por (turma, pessoa): cria pendente, ou reabre um já decidido.
    $stmt = $pdo->prepare(
        "INSERT INTO circle_join_requests (circle_id, user_id, status)
         VALUES (?, ?, 'pendente')
         ON DUPLICATE KEY UPDATE
           status = IF(status = 'recusado', 'pendente', status),
           decided_at = IF(status = 'recusado', NULL, decided_at)"
    );
    $stmt->execute([$circleId, $userId]);

    return "pendente";
}

/**
 * Adiciona um aluno à turma (INSERT IGNORE). Devolve o estado:
 * "novo" (entrou agora), "ja_membro" (já estava) ou "dono" (é o professor).
 */
function turma_add_membro(PDO $pdo, int $circleId, int $ownerId, int $userId): string
{
    if ($userId === $ownerId) {
        return "dono";
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO circle_members (circle_id, user_id) VALUES (?, ?)");
    $stmt->execute([$circleId, $userId]);

    return $stmt->rowCount() > 0 ? "novo" : "ja_membro";
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
        // A URL protegida, nunca o caminho em disco (ver material_arquivo.php).
        "arquivo_url"  => turma_material_url($m),
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
