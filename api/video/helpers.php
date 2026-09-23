<?php
/**
 * Geração de vídeo de apresentação para a loja.
 *
 * Ver `docs/plans/plano-videos-ia.md`. O lojista descreve a loja num
 * prompt; `video_gerar()` tenta cada plataforma na ordem de qualidade
 * (Kling) e cai para busca por palavra-chave na
 * Pexels quando nenhuma gera — o Pexels não é "mais uma tentativa que
 * pode falhar", é o piso que garante vídeo sempre.
 *
 * Provider sem chave em `video_config.php` é pulado em silêncio: não é
 * erro, é plataforma que o dono do projeto ainda não contratou.
 */

require_once __DIR__ . "/../ai/helpers.php"; // ai_config() -> pexels_api_key

/** Segundos de poll antes de desistir de um provider e cair para o próximo. */
const VIDEO_POLL_TIMEOUT = 120;

/** Intervalo entre tentativas de poll. */
const VIDEO_POLL_INTERVAL = 5;

/** Tamanho máximo aceito do arquivo baixado. */
const VIDEO_MAX_BYTES = 50 * 1024 * 1024;

/** MIME real aceito, com a extensão de saída. */
const VIDEO_MIME_TYPES = [
    "video/mp4"  => "mp4",
    "video/webm" => "webm",
];

/**
 * Prompt sugerido por nicho — usado como placeholder no painel da loja e
 * como texto padrão quando o lojista não escreve nada.
 */
const VIDEO_PROMPTS_NICHO = [
    "Alimentação" => "{nome}, comida fresca sendo preparada, câmera lenta, luz quente de cozinha profissional, sem texto",
    "Moda"        => "{nome}, roupas em destaque, modelo em movimento suave, iluminação de estúdio, paleta de cores harmoniosa",
    "Petshop"     => "{nome}, animal feliz brincando, fundo limpo, luz natural, movimento alegre",
    "Tecnologia"  => "{nome}, dispositivo tecnológico em close, luz azul dramática, superfície espelhada, câmera lenta",
    "Beleza"      => "{nome}, produto de beleza em destaque, pétalas ou glitter caindo, fundo neutro, cinematográfico",
    "Saúde"       => "{nome}, ambiente limpo e moderno, luz clara, transmite confiança e bem-estar",
    "Serviços"    => "{nome}, profissional trabalhando com cuidado, ambiente organizado, luz natural",
    "Outro"       => "{nome}, produto ou serviço em destaque, qualidade cinematográfica, sem texto",
];

/**
 * Palavra-chave em inglês por nicho, só para a busca na Pexels: o acervo
 * de vídeo de banco de imagens é indexado em inglês, e a categoria da
 * loja é texto livre em português (ver `lojas.categoria`).
 */
const VIDEO_PEXELS_QUERY_NICHO = [
    "Alimentação" => "food cooking restaurant",
    "Moda"        => "fashion clothing store",
    "Petshop"     => "pet animal dog cat",
    "Tecnologia"  => "technology gadget device",
    "Beleza"      => "beauty cosmetics spa",
    "Saúde"       => "health wellness clinic",
    "Serviços"    => "business service professional",
    "Outro"       => "small business store",
];

/**
 * Configuração das plataformas de vídeo (fora do repositório).
 *
 * Mesmo formato de `ai_config()`: `require` de um arquivo que devolve
 * array, cacheado no processo. Sem o arquivo, devolve array vazio — cada
 * função de provider trata a própria chave ausente como "pular".
 */
function video_config(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $arquivo = __DIR__ . "/video_config.php";

    if (!is_file($arquivo)) {
        return $cache = [];
    }

    $config = require $arquivo;

    return $cache = is_array($config) ? $config : [];
}

/** O prompt sugerido para o nicho, com o nome da loja já substituído. */
function video_prompt_sugerido(string $nicho, string $nomeLoja): string
{
    $modelo = VIDEO_PROMPTS_NICHO[$nicho] ?? VIDEO_PROMPTS_NICHO["Outro"];

    return str_replace("{nome}", $nomeLoja, $modelo);
}

/* ======================================================================
   DISPARO EM BACKGROUND
   ====================================================================== */

/**
 * Dispara `processar.php` para o `$videoId` e devolve na hora, sem
 * esperar a geração terminar.
 *
 * `start /B` (Windows) devolve assim que o processo filho nasce — é isso
 * que torna a chamada fire-and-forget de verdade. Sem o `start`, o
 * `popen()` ficaria preso esperando o `processar.php` inteiro (30 a 90s)
 * antes de devolver, e `gerar.php` travaria a aba do lojista pelo tempo
 * exato que a arquitetura assíncrona deveria evitar.
 *
 * `PHP_BINARY` é o mesmo PHP que está atendendo esta requisição — não
 * depende de `php` estar no PATH (no XAMPP normalmente não está), mesma
 * lógica de `api/seed/seed_ia_posts.php`.
 */
function video_disparar_processamento(int $videoId): void
{
    $php    = PHP_BINARY;
    $script = __DIR__ . "/processar.php";

    $cmd = "cmd /c start \"\" /B "
        . escapeshellarg($php) . " " . escapeshellarg($script) . " " . escapeshellarg((string)$videoId)
        . " > NUL 2>&1";

    $handle = popen($cmd, "r");

    if ($handle !== false) {
        pclose($handle);
    }
}

/* ======================================================================
   ESTADO DOS PROVIDERS (tabela video_providers)
   ====================================================================== */

function video_provider_ativo(PDO $pdo, string $nome): bool
{
    try {
        $stmt = $pdo->prepare("SELECT ativo FROM video_providers WHERE nome = ?");
        $stmt->execute([$nome]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && (int)$row["ativo"] === 1;
    } catch (Exception $e) {
        error_log("video_provider_ativo($nome): " . $e->getMessage());
        // Falha ao consultar não deve travar o fallback inteiro — segue
        // como se estivesse ativo, e o próprio provider falha adiante se
        // não tiver chave.
        return true;
    }
}

function video_provider_registrar_erro(PDO $pdo, string $nome, string $erro): void
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE video_providers SET ultimo_erro = ?, ultimo_uso = NOW() WHERE nome = ?"
        );
        $stmt->execute([mb_substr($erro, 0, 2000), $nome]);
    } catch (Exception $e) {
        error_log("video_provider_registrar_erro($nome): " . $e->getMessage());
    }
}

function video_provider_registrar_uso(PDO $pdo, string $nome, ?int $creditosRestantes = null): void
{
    try {
        if ($creditosRestantes !== null) {
            $stmt = $pdo->prepare(
                "UPDATE video_providers SET ultimo_erro = NULL, ultimo_uso = NOW(), creditos_restantes = ? WHERE nome = ?"
            );
            $stmt->execute([$creditosRestantes, $nome]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE video_providers SET ultimo_erro = NULL, ultimo_uso = NOW() WHERE nome = ?"
            );
            $stmt->execute([$nome]);
        }
    } catch (Exception $e) {
        error_log("video_provider_registrar_uso($nome): " . $e->getMessage());
    }
}

/* ======================================================================
   FUNÇÃO PRINCIPAL — tenta cada provider na ordem, cai para Pexels
   ====================================================================== */

/**
 * Gera um vídeo tentando cada provider na ordem de prioridade e salva o
 * resultado localmente.
 *
 * @return array{ok:bool, provider:?string, arquivo:?string, url:?string, erro:?string}
 */
function video_gerar(PDO $pdo, string $prompt, int $lojaId, string $nicho = "Outro"): array
{
    $providers = [
        ["kling", "video_kling"],
    ];

    foreach ($providers as [$nome, $funcao]) {
        if (!video_provider_ativo($pdo, $nome)) {
            continue;
        }

        try {
            $url = $funcao($prompt);
        } catch (Throwable $e) {
            error_log("video_gerar($nome): " . $e->getMessage());
            video_provider_registrar_erro($pdo, $nome, $e->getMessage());
            continue;
        }

        // Chave ausente é o caso mais comum de retorno null: cada
        // video_X() devolve null cedo, sem registrar erro, quando não
        // tem chave — não é falha da plataforma, é plataforma não
        // contratada.
        if ($url === null) {
            continue;
        }

        $arquivo = video_baixar_e_salvar($url, $lojaId);

        if ($arquivo === null) {
            video_provider_registrar_erro($pdo, $nome, "download ou validação do vídeo falhou");
            continue;
        }

        video_provider_registrar_uso($pdo, $nome);

        return ["ok" => true, "provider" => $nome, "arquivo" => $arquivo, "url" => $url, "erro" => null];
    }

    // Pexels por último: não gera, busca — é o piso que garante vídeo
    // sempre, mesmo sem nenhuma chave de geração configurada.
    if (video_provider_ativo($pdo, "pexels")) {
        $query = VIDEO_PEXELS_QUERY_NICHO[$nicho] ?? VIDEO_PEXELS_QUERY_NICHO["Outro"];

        try {
            $url = video_pexels($query);
        } catch (Throwable $e) {
            error_log("video_gerar(pexels): " . $e->getMessage());
            $url = null;
        }

        if ($url !== null) {
            $arquivo = video_baixar_e_salvar($url, $lojaId);

            if ($arquivo !== null) {
                video_provider_registrar_uso($pdo, "pexels");

                return ["ok" => true, "provider" => "pexels", "arquivo" => $arquivo, "url" => $url, "erro" => null];
            }

            video_provider_registrar_erro($pdo, "pexels", "download ou validação do vídeo falhou");
        } else {
            video_provider_registrar_erro($pdo, "pexels", "sem chave configurada ou busca sem resultado");
        }
    }

    // Segundo banco grátis: se o Pexels não trouxe vídeo, tenta o Coverr.
    if (video_provider_ativo($pdo, "coverr")) {
        $query = VIDEO_PEXELS_QUERY_NICHO[$nicho] ?? VIDEO_PEXELS_QUERY_NICHO["Outro"];

        try {
            $url = video_coverr($query);
        } catch (Throwable $e) {
            error_log("video_gerar(coverr): " . $e->getMessage());
            $url = null;
        }

        if ($url !== null) {
            $arquivo = video_baixar_e_salvar($url, $lojaId);

            if ($arquivo !== null) {
                video_provider_registrar_uso($pdo, "coverr");

                return ["ok" => true, "provider" => "coverr", "arquivo" => $arquivo, "url" => $url, "erro" => null];
            }

            video_provider_registrar_erro($pdo, "coverr", "download ou validação do vídeo falhou");
        } else {
            video_provider_registrar_erro($pdo, "coverr", "sem chave configurada ou busca sem resultado");
        }
    }

    return [
        "ok" => false,
        "provider" => null,
        "arquivo" => null,
        "url" => null,
        "erro" => "Nenhuma plataforma de vídeo conseguiu gerar ou encontrar um vídeo agora.",
    ];
}

/* ======================================================================
   PROVIDERS — cada um devolve a URL do vídeo pronto, ou null
   ====================================================================== */

/**
 * Kling AI (api-singapore.klingai.com, endpoint para servidores fora da
 * China). Metodo novo: a API Key unica ("api-key-kling-...") vai direto
 * como Bearer, sem JWT. O metodo legado (access_key + secret_key assinando
 * um JWT HS256) foi descontinuado para contas novas -- ver a aba
 * "API Key (for all models)" da doc de Authentication.
 */
function video_kling(string $prompt): ?string
{
    $config = video_config();
    $apiKey = trim((string)($config["kling_api_key"] ?? ""));

    if ($apiKey === "") {
        return null;
    }

    /* API NOVA do Kling (2025+): o modelo vai no PATH (/text-to-video/<modelo>),
       nao no corpo, e o `kling-v1` do metodo antigo foi descontinuado (erro
       1203). O corpo usa `settings`, a resposta traz `data.id`, e o poll e
       em /tasks?task_ids=. `kling_model` guarda o segmento de path do modelo
       (ex.: kling-2.5-turbo). Ver a doc "Text to Video" do Kling. */
    $modelo = trim((string)($config["kling_model"] ?? "")) ?: "kling-2.5-turbo";
    $base   = "https://api-singapore.klingai.com";
    $headers = ["content-type: application/json", "authorization: Bearer " . $apiKey];

    $corpo = json_encode([
        "prompt"   => $prompt,
        "settings" => ["resolution" => "1080p", "aspect_ratio" => "16:9", "duration" => 5],
    ], JSON_UNESCAPED_UNICODE);

    $resposta = video_http_post("$base/text-to-video/" . rawurlencode($modelo), $corpo, $headers);

    if (($resposta["code"] ?? 0) !== 0) {
        error_log("video_kling: disparo recusado. Resposta: " . json_encode($resposta));
        return null;
    }

    $taskId = $resposta["data"]["id"] ?? null;

    if (!is_string($taskId) || $taskId === "") {
        error_log("video_kling: disparo sem id de tarefa. Resposta: " . json_encode($resposta));
        return null;
    }

    $inicio = time();

    while (time() - $inicio < VIDEO_POLL_TIMEOUT) {
        sleep(VIDEO_POLL_INTERVAL);

        $estado = video_http_get("$base/tasks?task_ids=" . urlencode($taskId), $headers);

        // `data` e um array de tarefas; a nossa e a primeira.
        $tarefa = $estado["data"][0] ?? null;
        $status = $tarefa["status"] ?? null;

        if ($status === "failed") {
            error_log("video_kling: tarefa falhou. Resposta: " . json_encode($estado));
            return null;
        }

        if ($status === "succeeded") {
            // Entre os outputs, pega o primeiro do tipo "video".
            foreach (($tarefa["outputs"] ?? []) as $out) {
                if (($out["type"] ?? "") === "video" && !empty($out["url"])) {
                    return $out["url"];
                }
            }
            error_log("video_kling: sucesso sem URL de video. Resposta: " . json_encode($estado));
            return null;
        }
    }

    error_log("video_kling: timeout esperando a tarefa $taskId terminar.");
    return null;
}

/**
 * Pexels Vídeo — não gera, busca por palavra-chave. Fallback sempre
 * disponível: reaproveita `pexels_api_key` de `api/ai/ai_config.php`
 * (mesma chave que `ai_buscar_foto_pexels()` já usa).
 */
function video_pexels(string $query): ?string
{
    $chave = trim((string)(ai_config()["pexels_api_key"] ?? ""));

    if ($chave === "") {
        return null;
    }

    $url = "https://api.pexels.com/videos/search?" . http_build_query([
        "query" => $query,
        "per_page" => 1,
        "orientation" => "landscape",
    ]);

    $resposta = video_http_get($url, ["authorization: " . $chave]);
    $video = $resposta["videos"][0] ?? null;

    if (!is_array($video)) {
        return null;
    }

    // Entre os arquivos de qualidades disponíveis, pega o de maior
    // largura até 1280px — HD o bastante pro banner, sem baixar 4K à toa.
    $melhor = null;

    foreach (($video["video_files"] ?? []) as $arquivo) {
        $largura = (int)($arquivo["width"] ?? 0);

        if ($largura > 0 && $largura <= 1280 && ($melhor === null || $largura > (int)$melhor["width"])) {
            $melhor = $arquivo;
        }
    }

    $melhor = $melhor ?? ($video["video_files"][0] ?? null);

    return $melhor["link"] ?? null;
}

/**
 * Coverr Vídeo — segundo banco grátis, ao lado do Pexels. Não gera, busca
 * por palavra-chave. Auth por `?api_key=` (snake_case; `apiKey` é recusado).
 * A URL do MP4 vem direto em `urls.mp4` (CDN, 1080p) — sem passo de
 * signed-url. Free tier: 50 chamadas/hora. Ver coverr.co/developers.
 */
function video_coverr(string $query): ?string
{
    $chave = trim((string)(video_config()["coverr_api_key"] ?? ""));

    if ($chave === "") {
        return null;
    }

    $url = "https://api.coverr.co/videos?urls=true&page_size=20"
         . "&query=" . urlencode($query)
         . "&api_key=" . urlencode($chave);

    $resposta = video_http_get($url, []);
    $hits = $resposta["hits"] ?? [];

    if (!is_array($hits) || $hits === []) {
        return null;
    }

    // Um hit ao acaso, pra duas lojas do mesmo nicho não saírem iguais.
    $hit = $hits[array_rand($hits)];

    return $hit["urls"]["mp4"] ?? ($hit["urls"]["mp4_download"] ?? null);
}

/* ======================================================================
   DOWNLOAD E ARMAZENAMENTO
   ====================================================================== */

/**
 * Baixa o vídeo de `$url`, valida o MIME real e salva em
 * `uploads/videos/lojas/{loja_id}/{hash}.{ext}`.
 *
 * @return string|null Caminho relativo a `uploads/` (ex.: "videos/lojas/12/ab3f...mp4"), ou null se falhar.
 */
function video_baixar_e_salvar(string $url, int $lojaId): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), "echo_video_");

    if ($tmp === false) {
        return null;
    }

    $destino = fopen($tmp, "wb");

    if ($destino === false) {
        @unlink($tmp);
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $destino,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_MAXFILESIZE => VIDEO_MAX_BYTES,
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    fclose($destino);

    if ($ok === false || $status !== 200 || $erroCurl !== "") {
        error_log("video_baixar_e_salvar: download falhou (HTTP $status) $erroCurl");
        @unlink($tmp);
        return null;
    }

    if (filesize($tmp) > VIDEO_MAX_BYTES) {
        error_log("video_baixar_e_salvar: arquivo maior que o limite de 50MB.");
        @unlink($tmp);
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    if (!isset(VIDEO_MIME_TYPES[$mime])) {
        error_log("video_baixar_e_salvar: MIME inválido ($mime).");
        @unlink($tmp);
        return null;
    }

    $pastaRelativa = "videos/lojas/" . $lojaId;
    $pastaAbsoluta = __DIR__ . "/../../uploads/" . $pastaRelativa;

    if (!is_dir($pastaAbsoluta) && !mkdir($pastaAbsoluta, 0775, true) && !is_dir($pastaAbsoluta)) {
        error_log("video_baixar_e_salvar: não criou a pasta $pastaAbsoluta.");
        @unlink($tmp);
        return null;
    }

    $nome = uniqid("vid_", true) . "." . VIDEO_MIME_TYPES[$mime];
    $caminhoAbsoluto = $pastaAbsoluta . "/" . $nome;

    if (!rename($tmp, $caminhoAbsoluto)) {
        error_log("video_baixar_e_salvar: não moveu para $caminhoAbsoluto.");
        @unlink($tmp);
        return null;
    }

    return $pastaRelativa . "/" . $nome;
}

/* ======================================================================
   HTTP — helpers finos por cima do curl, só para não repetir setopt em
   cada provider.
   ====================================================================== */

function video_http_post(string $url, string $corpo, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $erroCurl !== "") {
        error_log("video_http_post($url): $erroCurl");
        return [];
    }

    $dados = json_decode($resposta, true);

    return is_array($dados) ? $dados : [];
}

function video_http_get(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $erroCurl !== "") {
        error_log("video_http_get($url): $erroCurl");
        return [];
    }

    $dados = json_decode($resposta, true);

    return is_array($dados) ? $dados : [];
}
