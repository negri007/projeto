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
 * O PHP de linha de comando sai de video_php_cli() — não depende de `php`
 * estar no PATH (no XAMPP normalmente não está).
 */
function video_disparar_processamento(int $videoId): void
{
    $php    = video_php_cli();
    $script = __DIR__ . "/processar.php";

    // O último argumento é a marca do render: processar.php não o lê, mas
    // ela deixa video_encerrar_render() achar este processo também.
    $cmd = "cmd /c start \"\" /B "
        . escapeshellarg($php) . " " . escapeshellarg($script) . " " . escapeshellarg((string)$videoId)
        . " " . escapeshellarg(video_render_marca($videoId))
        . " > NUL 2>&1";

    $handle = popen($cmd, "r");

    if ($handle !== false) {
        pclose($handle);
    }
}

/**
 * Caminho do PHP de LINHA DE COMANDO para rodar processar.php.
 *
 * `PHP_BINARY` só serve quando esta requisição já roda num php CLI (o
 * `php -S`). Sob o Apache (mod_php) ele aponta para o httpd.exe — e
 * "httpd.exe processar.php 5" não renderiza nada. Ordem: `php_bin` de
 * video_config.php > PHP_BINARY se for um php > php.exe ao lado do
 * php.ini carregado (no XAMPP, C:\xampp\php\php.exe) > `php` do PATH.
 */
function video_php_cli(): string
{
    $config = trim((string)(video_config()["php_bin"] ?? ""));
    if ($config !== "") {
        return $config;
    }

    if (PHP_BINARY !== "" && preg_match('/^php(-cli)?(\.exe)?$/i', basename(PHP_BINARY))) {
        return PHP_BINARY;
    }

    $ini = php_ini_loaded_file();
    if ($ini) {
        $aoLado = dirname($ini) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === "Windows" ? "php.exe" : "php");
        if (is_file($aoLado)) {
            return $aoLado;
        }
    }

    return "php";
}

/* ======================================================================
   FILA GLOBAL DO MOTOR
   ====================================================================== */

/** Nome do GET_LOCK que serializa o despacho entre requisições e CLIs. */
const VIDEO_FILA_LOCK = "echo_video_fila";

/**
 * Quantos renders do motor podem rodar ao mesmo tempo no sistema todo.
 * Cada render sobe um Chrome headless (~2 GB de RAM), então o padrão é 1.
 */
function video_max_renders(): int
{
    return max(1, (int)(video_config()["max_renders"] ?? 1));
}

/**
 * Passa peças do motor de 'na_fila' para 'gerando' enquanto houver vaga e
 * dispara o processar.php de cada uma. Devolve os ids despachados.
 *
 * Chamado quando um pedido entra (marketing.php) e quando um render
 * termina (processar.php). Duas chamadas ao mesmo tempo — dois lojistas
 * pedindo juntos, ou um render terminando enquanto outro pedido chega —
 * contariam a mesma vaga duas vezes; o GET_LOCK faz uma esperar a outra.
 * O UPDATE ainda confere `status = 'na_fila'`, então nem um despacho fora
 * da trava tiraria a mesma peça da fila duas vezes.
 *
 * Só o motor (`modelo` preenchido): vídeo por IA não pesa na máquina e
 * não entra na fila.
 */
function video_fila_despachar(PDO $pdo): array
{
    $trava = $pdo->query("SELECT GET_LOCK('" . VIDEO_FILA_LOCK . "', 10)")->fetchColumn();
    if ((int)$trava !== 1) {
        // Outro despacho segurou a trava por 10s — anormal, mas não perde
        // nada: a peça segue 'na_fila' e o próximo despacho a pega.
        error_log("video_fila_despachar: não obteve a trava da fila");
        return [];
    }

    $despachados = [];
    try {
        $rodando = (int)$pdo->query(
            "SELECT COUNT(*) FROM videos_gerados WHERE status = 'gerando' AND modelo IS NOT NULL"
        )->fetchColumn();
        $vagas = video_max_renders() - $rodando;

        if ($vagas > 0) {
            // $vagas é int calculado aqui, não entrada — seguro no LIMIT.
            $ids = $pdo->query(
                "SELECT id FROM videos_gerados
                  WHERE status = 'na_fila' AND modelo IS NOT NULL
                  ORDER BY id LIMIT " . (int)$vagas
            )->fetchAll(PDO::FETCH_COLUMN);

            $tira = $pdo->prepare(
                "UPDATE videos_gerados SET status = 'gerando', iniciado_em = NOW()
                  WHERE id = ? AND status = 'na_fila'"
            );
            foreach ($ids as $id) {
                $tira->execute([(int)$id]);
                if ($tira->rowCount() === 1) {
                    $despachados[] = (int)$id;
                }
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('" . VIDEO_FILA_LOCK . "')");
    }

    // Fora da trava: o status já mudou, e o disparo não precisa segurar
    // os outros despachos.
    foreach ($despachados as $id) {
        video_disparar_processamento($id);
    }

    return $despachados;
}

/**
 * Trecho de SELECT que calcula a posição na fila de `vg` (1 = próximo),
 * NULL fora da fila. Usado por status.php e meus.php.
 */
const VIDEO_SQL_POSICAO_FILA =
    "CASE WHEN vg.status = 'na_fila' THEN
        (SELECT COUNT(*) FROM videos_gerados f
          WHERE f.status = 'na_fila' AND f.modelo IS NOT NULL AND f.id <= vg.id)
     END";

/* ======================================================================
   RENDER TRAVADO
   ====================================================================== */

/** Minutos em 'gerando' (contados de iniciado_em) até a peça contar como travada. */
const VIDEO_TRAVADO_MIN = 10;

/** O que o lojista lê quando a peça travou e foi cancelada. */
const VIDEO_MSG_TRAVADO = "A geração demorou mais que o normal e foi cancelada. Tente gerar de novo.";

/**
 * Tempo máximo de UM render (render_timeout_s do video_config.php, padrão
 * 480s), mandado ao render.js no job. Nunca passa de 540s: o render.js
 * tem de desistir sozinho, e responder o erro dele, antes dos
 * VIDEO_TRAVADO_MIN minutos em que a limpeza de travados mata tudo.
 */
function video_render_timeout_s(): int
{
    $s = (int)(video_config()["render_timeout_s"] ?? 480);
    $teto = VIDEO_TRAVADO_MIN * 60 - 60;
    return $s > 0 ? min($s, $teto) : 480;
}

/**
 * Marca do render na linha de comando dos processos dele (processar.php,
 * node e Chrome — ver video_motor_render). Termina em "_" para a marca do
 * vídeo 2 não casar com a do vídeo 20.
 */
function video_render_marca(int $videoId): string
{
    return "echo_render_v{$videoId}_";
}

/** O "arquivo nulo" do sistema, para descartar saída. */
function video_nulo(?string $os = null): string
{
    return ($os ?? PHP_OS_FAMILY) === "Windows" ? "NUL" : "/dev/null";
}

/**
 * Argumentos do comando que mata todo processo com a marca na linha de
 * comando (e os filhos dele), menos o processo $poupar (quem chama: o
 * processar.php leva a marca no próprio argumento). Separado de
 * video_encerrar_render() para o comando do Linux poder ser conferido
 * rodando no Windows.
 *
 * No Windows mata SÓ os processos com a marca, nunca a árvore (taskkill
 * /T): a árvore vem do ParentProcessId, e PID no Windows é reaproveitado —
 * um serviço antigo (o mysqld do XAMPP) cujo pai já morreu pode ter como
 * "pai" o PID reaproveitado por um node do render, e morreria junto. Isso
 * aconteceu no teste. Node, Chrome e processar.php levam a marca; os
 * filhos do Chrome (que não levam) só entram se nasceram depois do pai.
 *
 * O comando nunca leva a marca literal: senão ele mesmo casaria com a
 * busca e se mataria antes de terminar. No Windows vai como
 * -EncodedCommand (base64); no Linux, o "[e]" da regex não casa com o
 * próprio texto "[e]cho...".
 */
function video_cmd_encerrar(string $marca, int $poupar = 0, ?string $os = null): array
{
    if (($os ?? PHP_OS_FAMILY) === "Windows") {
        // Alvos: quem tem a marca, mais os descendentes que NASCERAM DEPOIS
        // do pai (filho de verdade; PID reaproveitado é sempre mais velho).
        $ps = "\$m = '" . str_replace("'", "''", $marca) . "'; \$poupar = " . $poupar . ";"
            . " \$todos = @(Get-CimInstance Win32_Process);"
            . " \$alvo = @(\$todos | Where-Object { \$_.CommandLine -and \$_.CommandLine.Contains(\$m)"
            . " -and \$_.ProcessId -ne \$PID -and \$_.ProcessId -ne \$poupar });"
            . " do { \$n = \$alvo.Count;"
            . " \$alvo += @(\$todos | Where-Object { \$f = \$_; (\$alvo.ProcessId -notcontains \$f.ProcessId)"
            . " -and \$f.ProcessId -ne \$PID -and \$f.ProcessId -ne \$poupar"
            . " -and (\$alvo | Where-Object { \$_.ProcessId -eq \$f.ParentProcessId -and \$f.CreationDate -gt \$_.CreationDate }) })"
            . " } while (\$alvo.Count -gt \$n);"
            . " \$alvo | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }";
        return [
            "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
            "-EncodedCommand", base64_encode(mb_convert_encoding($ps, "UTF-16LE", "UTF-8")),
        ];
    }

    $regex = "[" . $marca[0] . "]" . preg_quote(substr($marca, 1), "/");
    $sh = "for p in $(pgrep -f " . escapeshellarg($regex) . "); do"
        . " [ \"\$p\" = " . $poupar . " ] && continue;"
        . " pkill -KILL -P \"\$p\"; kill -KILL \"\$p\";"
        . " done 2>/dev/null; true";
    return ["/bin/sh", "-c", $sh];
}

/**
 * Mata node, Chrome e processar.php do render deste vídeo, se ainda
 * existirem — menos o processo que chama.
 */
function video_encerrar_render(int $videoId): void
{
    $proc = @proc_open(
        video_cmd_encerrar(video_render_marca($videoId), (int)getmypid()),
        [0 => ["file", video_nulo(), "r"], 1 => ["file", video_nulo(), "w"], 2 => ["file", video_nulo(), "w"]],
        $pipes
    );
    if (is_resource($proc)) {
        proc_close($proc);
    }
}

/** Apaga uma pasta temporária do render (e o que tiver dentro). */
function video_apagar_pasta(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itens as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Peça do motor em 'gerando' há mais de VIDEO_TRAVADO_MIN minutos (contados
 * de iniciado_em; sem ele, de created_at) vira erro, os processos dela são
 * encerrados e a vaga passa para o próximo da fila. Devolve os ids.
 *
 * Roda a cada consulta (status.php, meus.php, marketing.php) — a busca usa
 * o índice (status, id) e quase sempre não acha nada — e pelo CLI
 * api/video/limpar_travados.php.
 */
function video_limpar_travados(PDO $pdo): array
{
    $ids = $pdo->query(
        "SELECT id FROM videos_gerados
          WHERE status = 'gerando' AND modelo IS NOT NULL
            AND COALESCE(iniciado_em, created_at) < NOW() - INTERVAL " . VIDEO_TRAVADO_MIN . " MINUTE"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        return [];
    }

    $marca = $pdo->prepare("UPDATE videos_gerados SET status = 'erro', erro = ? WHERE id = ? AND status = 'gerando'");
    $cancelados = [];
    foreach ($ids as $id) {
        $marca->execute([VIDEO_MSG_TRAVADO, (int)$id]);
        if ($marca->rowCount() === 1) {
            $cancelados[] = (int)$id;
        }
    }

    foreach ($cancelados as $id) {
        error_log("video_limpar_travados: vídeo $id passou de " . VIDEO_TRAVADO_MIN . " min em 'gerando'; cancelado");
        video_encerrar_render($id);
    }

    if ($cancelados) {
        video_fila_despachar($pdo);
    }

    return $cancelados;
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
   MOTOR DE ANÚNCIOS (render local via Remotion, em /motor)

   Quando o registro tem `modelo` preenchido, o vídeo não vem de IA nem de
   banco de vídeo: é renderizado localmente pelo motor, sem custo de API.
   Ver docs/plans/motor-anuncios.md.
   ====================================================================== */

/** Limite do arquivo ENVIADO (antes de normalizar). Fotos de celular passam
 *  fácil de 8MB, então o teto é alto — a saída é sempre reduzida e re-salva. */
const MOTOR_IMG_MAX_BYTES = 25 * 1024 * 1024;
/** Maior lado da imagem final (px). Reduz gigantes pra render rápido e leve. */
const MOTOR_IMG_MAX_DIM = 1600;

/**
 * Prepara a foto do produto pro motor: aceita QUALQUER formato de imagem que
 * o servidor consiga decodificar (JPEG, PNG, WebP, GIF, AVIF, BMP via GD; e
 * TIFF/HEIC via ffmpeg quando disponível), corrige a rotação de fotos de
 * celular (EXIF), achata transparência em branco, reduz o tamanho e salva
 * como **JPEG** em motor/public/uploads. Assim o Chromium do Remotion sempre
 * consegue renderizar (ele não abre HEIC/TIFF direto).
 *
 * Devolve o caminho relativo a motor/public (o que o `staticFile()` espera,
 * ex.: "uploads/4_foto_ab12.jpg"), ou null se a imagem não pôde ser lida.
 */
function motor_copiar_imagem(string $src, int $lojaId, string $tag): ?string
{
    if (!is_file($src) || filesize($src) > MOTOR_IMG_MAX_BYTES) {
        return null;
    }

    $img = motor_img_carregar($src);
    if ($img === null) {
        return null;
    }

    $img = motor_img_corrige_exif($img, $src);

    // achata em fundo branco (PNG/WebP transparentes não viram preto) e reduz
    // se for maior que o teto.
    $w = imagesx($img);
    $h = imagesy($img);
    $maior = max($w, $h);
    $escala = $maior > MOTOR_IMG_MAX_DIM ? MOTOR_IMG_MAX_DIM / $maior : 1.0;
    $nw = max(1, (int)round($w * $escala));
    $nh = max(1, (int)round($h * $escala));

    $final = imagecreatetruecolor($nw, $nh);
    $branco = imagecolorallocate($final, 255, 255, 255);
    imagefilledrectangle($final, 0, 0, $nw, $nh, $branco);
    imagecopyresampled($final, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    $destDir = __DIR__ . "/../../motor/public/uploads";
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        imagedestroy($final);
        return null;
    }

    $nome = $lojaId . "_" . $tag . "_" . uniqid("", true) . ".jpg";
    $ok = imagejpeg($final, $destDir . "/" . $nome, 88);
    imagedestroy($final);

    return $ok ? "uploads/" . $nome : null;
}

/**
 * Decodifica a imagem pra um recurso GD. Tenta o GD direto (cobre JPEG, PNG,
 * WebP, GIF, AVIF, BMP); se falhar (ex.: HEIC do iPhone, TIFF), tenta o
 * ffmpeg convertendo pra PNG temporário. Devolve GdImage ou null.
 */
function motor_img_carregar(string $src)
{
    $data = @file_get_contents($src, false, null, 0, MOTOR_IMG_MAX_BYTES);
    if ($data !== false && $data !== "") {
        $img = @imagecreatefromstring($data);
        if ($img !== false) {
            return $img;
        }
    }

    // Fallback: ffmpeg -> PNG (para formatos que o GD não abre).
    // `-protocol_whitelist file`: a foto vem do lojista, e o ffmpeg não deve
    // abrir nada além dela (nem rede, nem outro arquivo que ela referencie)
    // — mesma trava de posts_converter_para_jpg().
    $ff = trim((string)(video_config()["ffmpeg_bin"] ?? "")) ?: "ffmpeg";
    $tmp = tempnam(sys_get_temp_dir(), "echo_img_");
    if ($tmp === false) {
        return null;
    }
    $tmpPng = $tmp . ".png";
    $cmd = escapeshellarg($ff) . " -v error -y -protocol_whitelist file -i " . escapeshellarg($src)
        . " -frames:v 1 " . escapeshellarg($tmpPng) . " 2>NUL";
    @shell_exec($cmd);
    @unlink($tmp);

    if (is_file($tmpPng) && filesize($tmpPng) > 0) {
        $img = @imagecreatefrompng($tmpPng);
        @unlink($tmpPng);
        if ($img !== false) {
            return $img;
        }
    }
    @unlink($tmpPng);

    return null;
}

/** Roda a imagem conforme a orientação EXIF (foto de celular deitada). */
function motor_img_corrige_exif($img, string $src)
{
    if (!function_exists("exif_read_data")) {
        return $img;
    }
    $exif = @exif_read_data($src);
    $orient = (int)($exif["Orientation"] ?? 0);
    $ang = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
    if ($ang !== 0) {
        $rot = @imagerotate($img, $ang, 0);
        if ($rot !== false) {
            imagedestroy($img);
            return $rot;
        }
    }
    return $img;
}

/**
 * Renderiza uma peça de marketing pelo motor Remotion.
 *
 * Monta o job.json a partir do registro, chama `node motor/render.js` e
 * salva o MP4 em uploads/videos/lojas/{loja}/. As fotos já foram copiadas
 * para motor/public/uploads em marketing.php e os `props` já apontam para
 * elas (staticFile).
 *
 * @param array $registro linha de videos_gerados (modelo, formato, params, loja_id)
 * @return array{ok:bool, arquivo:?string, erro:?string}
 */
function video_motor_render(array $registro): array
{
    $modelo  = (string)($registro["modelo"] ?? "");
    $formato = (string)($registro["formato"] ?? "") ?: "story";
    $lojaId  = (int)($registro["loja_id"] ?? 0);
    $params  = json_decode((string)($registro["params"] ?? "[]"), true);
    $params  = is_array($params) ? $params : [];

    $nicho  = (string)($params["nicho"] ?? "comida");
    $props  = is_array($params["props"] ?? null) ? $params["props"] : [];
    $musica = $params["musica"] ?? null; // ausente = padrão do nicho; "" = mudo

    // Pasta de saída — mesma convenção dos outros vídeos (relativa a uploads/).
    $pastaRelativa = "videos/lojas/" . $lojaId;
    $pastaAbsoluta = __DIR__ . "/../../uploads/" . $pastaRelativa;

    if (!is_dir($pastaAbsoluta) && !mkdir($pastaAbsoluta, 0775, true) && !is_dir($pastaAbsoluta)) {
        return ["ok" => false, "arquivo" => null, "erro" => "não criou a pasta de saída"];
    }

    $nome    = uniqid("mkt_", true) . ".mp4";
    $outAbs  = $pastaAbsoluta . "/" . $nome;

    $job = [
        "modelo"  => $modelo,
        "formato" => $formato,
        "nicho"   => $nicho,
        "props"   => $props,
        "out"     => str_replace("\\", "/", $outAbs),
    ];
    if ($musica !== null) {
        $job["musica"] = $musica;
    }
    $job["timeout_s"] = video_render_timeout_s();

    $videoId = (int)($registro["id"] ?? 0);
    $marca   = video_render_marca($videoId);

    /* Pasta temporária própria do render, com a MARCA no nome. O job.json
       mora nela (a marca entra na linha de comando do node) e ela vira o
       TEMP do node — o Remotion cria o perfil do Chrome em os.tmpdir(),
       então a marca entra também no --user-data-dir do Chrome. É por essa
       marca que video_encerrar_render() acha o node e o Chrome, inclusive
       o Chrome que sobra quando alguém mata o node. */
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $marca . "tmp";
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        return ["ok" => false, "arquivo" => null, "erro" => "Não consegui gerar o vídeo. Tente de novo."];
    }
    $jobFile = $tmpDir . DIRECTORY_SEPARATOR . "echo_job_v{$videoId}.json";
    file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE));

    $node     = trim((string)(video_config()["node_bin"] ?? "")) ?: "node";
    $renderJs = realpath(__DIR__ . "/../../motor/render.js") ?: (__DIR__ . "/../../motor/render.js");

    // stderr (ruído do Remotion + tempos) vai para um log por vídeo, que só
    // fica se o render falhar. logs/ está no .gitignore.
    $logDir = __DIR__ . "/../../logs/motor";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . "/v{$videoId}.log";

    $env = getenv();
    $env["TEMP"] = $env["TMP"] = $env["TMPDIR"] = $tmpDir;

    /* proc_open com lista de argumentos: não passa por shell nenhum (nada de
       aspas nem de 2>NUL, e funciona igual no Windows e no Linux). A
       resposta vem em stdout como UMA linha JSON; render.js valida `modelo`
       contra whitelist própria. */
    $saida = "";
    $proc = @proc_open(
        [$node, $renderJs, $jobFile],
        [0 => ["file", video_nulo(), "r"], 1 => ["pipe", "w"], 2 => ["file", $logFile, "w"]],
        $pipes,
        dirname($renderJs),
        $env
    );
    if (is_resource($proc)) {
        $saida = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
    }

    // O que sobrar com a marca (Chrome de um node morto no meio) morre aqui.
    video_encerrar_render($videoId);
    video_apagar_pasta($tmpDir);

    // Pega a última linha não-vazia (a linha JSON do render.js).
    $linhas = array_values(array_filter(array_map("trim", explode("\n", $saida))));
    $ultima = $linhas ? end($linhas) : "";
    $res = json_decode($ultima, true);

    if (is_array($res) && !empty($res["ok"]) && is_file($outAbs)) {
        @unlink($logFile);
        return ["ok" => true, "arquivo" => $pastaRelativa . "/" . $nome, "erro" => null];
    }

    $erro = is_array($res) ? ($res["erro"] ?? "render falhou") : "motor sem resposta (node no PATH?)";
    error_log("video_motor_render($videoId): $erro | stderr em logs/motor/v{$videoId}.log");

    // O detalhe (caminho do servidor, mensagem do Remotion) fica só no log:
    // `erro` vai para a tela do lojista.
    return ["ok" => false, "arquivo" => null, "erro" => "Não consegui gerar o vídeo. Tente de novo."];
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
