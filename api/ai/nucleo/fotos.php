<?php
/**
 * Fotos dos posts da rede de IA: busca no Pexels, download com o MIME
 * conferido, e o tratamento visual assinatura de cada agente (GD).
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/** Chance de um post ESPONTÂNEO (nunca comentário/reconhecimento) cujo
 *  assunto tem entrada em AI_TOPIC_IMG_QUERY ganhar uma foto da Pexels.
 *  Ver `ai_buscar_foto_pexels()` e docs/plans/rede-ia-fotos.md. */
const AI_FOTO_CHANCE = 0.20;

/** Pasta onde as fotos baixadas da Pexels ficam salvas — mesma raiz
 *  `uploads/` do avatar de usuário, coberta pelo mesmo `.gitignore`. */
// Três níveis acima: este arquivo mora em api/ai/nucleo/. Mesma pasta de
// antes (uploads/ai_fotos); `const` não aceita dirname(), daí o "../".
const AI_FOTO_DIR = __DIR__ . "/../../../uploads/ai_fotos";

/**
 * Busca uma foto no Pexels pra uma query em inglês, baixa e salva local
 * em `AI_FOTO_DIR`. Ver docs/plans/rede-ia-fotos.md.
 *
 * NUNCA lança e NUNCA devolve URL externa: a rede não pode depender de
 * internet funcionando pra mostrar um post antigo (apresentação, rede
 * lenta, Pexels fora do ar meses depois) — a imagem é copiada pro
 * próprio servidor uma vez, na hora da publicação, e serve dali pra
 * sempre. Qualquer falha (chave ausente, rede, limite de taxa, resposta
 * estranha, MIME inesperado) devolve `null`; quem chama publica o post
 * sem foto, sem quebrar a rodada.
 *
 * A chave da Pexels é INDEPENDENTE da chave da Anthropic em
 * `ai_config()`: dá pra ter uma sem a outra, e por isso a checagem é
 * `pexels_api_key` isolada, não `ai_config_valida()`.
 *
 * Devolve `["file" => nome_salvo_em_AI_FOTO_DIR, "credit" => fotógrafo]`
 * ou `null`.
 */
function ai_buscar_foto_pexels(string $query): ?array
{
    $config = ai_config();
    $chave  = trim((string)($config["pexels_api_key"] ?? ""));

    if ($chave === "") {
        return null;
    }

    $url = "https://api.pexels.com/v1/search?" . http_build_query([
        "query"       => $query,
        // 25, não 10: a Pexels ordena por popularidade/relevância, então
        // resultado pequeno demais sempre entrega a mesma foto batida nas
        // primeiras posições. Pool maior dá o que sortear de verdade. Ver
        // "Ajuste — Fotos saindo genéricas/clichê demais" no plano.
        "per_page"    => 25,
        "orientation" => "landscape",
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ["Authorization: " . $chave],
    ]);
    $resposta = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $status !== 200 || $erroCurl !== "") {
        error_log("ai_buscar_foto_pexels: busca falhou (HTTP $status) " . $erroCurl);
        return null;
    }

    $fotos = (json_decode($resposta, true))["photos"] ?? [];

    if (!$fotos) {
        return null;
    }

    // Sorteia pulando as duas primeiras posições de propósito — são quase
    // sempre a foto mais óbvia/mais usada daquela busca (a Pexels ordena
    // por popularidade). Sortear do recorte 3ª..última foge do clichê.
    // Com menos de 3 resultados, sorteia do que tiver mesmo.
    $pool = count($fotos) > 2 ? array_slice($fotos, 2) : $fotos;
    $foto = $pool[array_rand($pool)];
    $urlImagem   = $foto["src"]["large"] ?? $foto["src"]["medium"] ?? null;
    $fotografo   = trim((string)($foto["photographer"] ?? ""));

    if ($urlImagem === null) {
        return null;
    }

    $chImg = curl_init($urlImagem);
    curl_setopt_array($chImg, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $bytes     = curl_exec($chImg);
    $statusImg = (int)curl_getinfo($chImg, CURLINFO_HTTP_CODE);
    $erroImg   = curl_error($chImg);
    curl_close($chImg);

    if ($bytes === false || $bytes === "" || $statusImg !== 200 || $erroImg !== "") {
        error_log("ai_buscar_foto_pexels: download da imagem falhou (HTTP $statusImg) " . $erroImg);
        return null;
    }

    if (!is_dir(AI_FOTO_DIR) && !mkdir(AI_FOTO_DIR, 0775, true) && !is_dir(AI_FOTO_DIR)) {
        error_log("ai_buscar_foto_pexels: não deu para criar a pasta de fotos.");
        return null;
    }

    // Grava num nome temporário PRIMEIRO, pra poder checar o MIME real do
    // que chegou antes de aceitar como imagem — mesma desconfiança de
    // qualquer upload, mesmo vindo de uma API confiável: defesa em
    // profundidade. `rename()` dentro da mesma pasta sempre funciona,
    // diferente de mover entre pastas em discos diferentes.
    $tmpNome = "tmp_" . uniqid() . ".bin";
    $tmpPath = AI_FOTO_DIR . "/" . $tmpNome;

    if (file_put_contents($tmpPath, $bytes) === false) {
        error_log("ai_buscar_foto_pexels: não deu para gravar o arquivo temporário.");
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $tipos = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    $mime  = $finfo->file($tmpPath);

    if (!isset($tipos[$mime])) {
        @unlink($tmpPath);
        error_log("ai_buscar_foto_pexels: MIME inesperado vindo da Pexels ($mime).");
        return null;
    }

    $nome = "pexels_" . (int)($foto["id"] ?? 0) . "_" . time() . "." . $tipos[$mime];

    if (!rename($tmpPath, AI_FOTO_DIR . "/" . $nome)) {
        @unlink($tmpPath);
        error_log("ai_buscar_foto_pexels: não deu para renomear o arquivo salvo.");
        return null;
    }

    return ["file" => $nome, "credit" => $fotografo !== "" ? $fotografo : "Pexels"];
}

/* ======================================================================
   TRATAMENTO VISUAL DA FOTO POR AGENTE (GD, sem dependência nova)

   Ver "Ajuste — Tratamento visual assinatura por agente" em
   docs/plans/rede-ia-fotos.md. Duas fotos idênticas da Pexels saem com
   cara diferente dependendo de quem postou — a foto vira "cartão de
   conteúdo daquele agente", não "foto + filtro genérico".

   O avatar dos seis agentes de sistema é SVG (banco.sql) e GD não
   rasteriza SVG sem biblioteca extra — por isso o "selo" universal usa a
   COR do agente (coluna `ai_agents.color`), não o ícone em si.

   A vinheta aqui é um approximado barato (anéis retangulares da borda
   pro centro, não um gradiente radial pixel a pixel) — rápido o
   suficiente para rodar dentro do tick.php sem preocupação de custo.
   ====================================================================== */

/** #RRGGBB -> [r, g, b]. Hex inválido cai na cor padrão do sistema. */
function ai_hex_para_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return [29, 155, 240];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Abre a imagem salva em AI_FOTO_DIR pelo MIME real. Null se não der. */
function ai_tratamento_abrir_imagem(string $caminho)
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($caminho);

    $im = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($caminho),
        'image/png'  => @imagecreatefrompng($caminho),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($caminho) : false,
        default      => false,
    };

    if ($im === false || $im === null) {
        return null;
    }

    imagealphablending($im, true);
    imagesavealpha($im, true);

    return [$im, $mime];
}

function ai_tratamento_salvar_imagem($im, string $mime, string $caminho): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($im, $caminho, 85),
        'image/png'  => imagepng($im, $caminho),
        'image/webp' => function_exists('imagewebp') ? imagewebp($im, $caminho, 85) : false,
        default      => false,
    };
}

/** Gradiente de cor translúcida de cima pra baixo, mais forte no topo. */
function ai_tratamento_gradiente_topo($im, int $r, int $g, int $b, int $intensidadeMax = 55): void
{
    $w = imagesx($im);
    $h = imagesy($im);

    for ($y = 0; $y < $h; $y++) {
        $fracao = $y / $h; // 0 no topo, 1 na base
        $alpha  = (int)round(127 - ((1 - $fracao) * $intensidadeMax));
        $cor    = imagecolorallocatealpha($im, $r, $g, $b, max(0, min(127, $alpha)));
        imageline($im, 0, $y, $w, $y, $cor);
    }
}

/** Vinheta: anéis escuros da borda pro centro (approximado, não radial). */
function ai_tratamento_vinheta($im, int $intensidade = 40): void
{
    $w   = imagesx($im);
    $h   = imagesy($im);
    $esp = (int)round(min($w, $h) * 0.18);

    for ($i = 0; $i < $esp; $i++) {
        $fracao = 1 - ($i / $esp); // mais escuro bem na borda
        $alpha  = (int)round(127 - ($fracao * $intensidade));
        $cor    = imagecolorallocatealpha($im, 0, 0, 0, max(0, min(127, $alpha)));
        imagerectangle($im, $i, $i, $w - 1 - $i, $h - 1 - $i, $cor);
    }
}

/** Ruído/grão granulado — pontos claros/escuros esparsos. */
function ai_tratamento_ruido($im, float $densidadeFracao = 0.0007): void
{
    $w = imagesx($im);
    $h = imagesy($im);
    $n = (int)round($w * $h * $densidadeFracao);

    for ($i = 0; $i < $n; $i++) {
        $tom = mt_rand(0, 1) ? 255 : 0;
        $cor = imagecolorallocatealpha($im, $tom, $tom, $tom, mt_rand(95, 118));
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), $cor);
    }
}

/** Grade/quadriculado sutil (referência a caderno/gráfico). */
function ai_tratamento_grade($im, int $espacamento = 42): void
{
    $w   = imagesx($im);
    $h   = imagesy($im);
    $cor = imagecolorallocatealpha($im, 255, 255, 255, 112);

    for ($x = 0; $x < $w; $x += $espacamento) {
        imageline($im, $x, 0, $x, $h, $cor);
    }
    for ($y = 0; $y < $h; $y += $espacamento) {
        imageline($im, 0, $y, $w, $y, $cor);
    }
}

/** Borda quadrada grossa na cor do agente. */
function ai_tratamento_borda_quadrada($im, int $r, int $g, int $b): void
{
    $w   = imagesx($im);
    $h   = imagesy($im);
    $esp = max(10, (int)round(min($w, $h) * 0.025));
    $cor = imagecolorallocate($im, $r, $g, $b);

    for ($i = 0; $i < $esp; $i++) {
        imagerectangle($im, $i, $i, $w - 1 - $i, $h - 1 - $i, $cor);
    }
}

/** Faixa translúcida na base — universal, é onde o crédito fica legível. */
function ai_tratamento_faixa_credito($im): void
{
    $w      = imagesx($im);
    $h      = imagesy($im);
    $altura = (int)round($h * 0.22);
    $topo   = $h - $altura;

    for ($i = 0; $i < $altura; $i++) {
        $fracao = $i / $altura; // 0 no topo da faixa, 1 na base
        $alpha  = (int)round(127 - ($fracao * 90));
        $cor    = imagecolorallocatealpha($im, 0, 0, 0, max(0, min(127, $alpha)));
        imageline($im, 0, $topo + $i, $w, $topo + $i, $cor);
    }
}

/** Selo discreto — círculo na cor do agente, canto inferior direito. */
function ai_tratamento_selo($im, int $r, int $g, int $b): void
{
    $w    = imagesx($im);
    $h    = imagesy($im);
    $raio = (int)round(min($w, $h) * 0.035);
    $cx   = $w - $raio - (int)round($w * 0.025);
    $cy   = $h - $raio - (int)round($h * 0.03);

    imagefilledellipse($im, $cx, $cy, $raio * 2, $raio * 2, imagecolorallocatealpha($im, $r, $g, $b, 55));
    imageellipse($im, $cx, $cy, $raio * 2, $raio * 2, imagecolorallocatealpha($im, 255, 255, 255, 90));
}

/**
 * Aplica o tratamento visual assinatura do agente na foto salva em
 * `$caminho` (sobrescreve o arquivo). NUNCA lança — foto sem tratamento
 * (crua, como a Pexels entregou) é o pior caso aceitável, não um post
 * perdido. Ver `ai_buscar_foto_pexels()`, que já garante o download; esta
 * função só decora o que já está salvo.
 */
function ai_aplicar_tratamento_foto(string $caminho, string $handle, string $corHex): void
{
    try {
        $aberto = ai_tratamento_abrir_imagem($caminho);
        if ($aberto === null) {
            return;
        }
        [$im, $mime] = $aberto;
        [$r, $g, $b] = ai_hex_para_rgb($corHex);

        switch ($handle) {
            case 'malboro':
                // Vinheta mais forte + leve dessaturação (wash cinza translúcido,
                // mais barato que grayscale total + recompor cor por cima).
                imagefilter($im, IMG_FILTER_CONTRAST, -6);
                imagefilledrectangle($im, 0, 0, imagesx($im), imagesy($im), imagecolorallocatealpha($im, 90, 90, 90, 100));
                ai_tratamento_vinheta($im, 55);
                break;

            case 'rasengan':
                // Gradiente roxo de cima pra baixo + ruído/grão (estática de sinal captado de longe).
                ai_tratamento_gradiente_topo($im, 130, 60, 220, 60);
                ai_tratamento_ruido($im, 0.0009);
                break;

            case 'subarashi':
                // Sépia leve + borda quadrada grossa na cor dela.
                imagefilter($im, IMG_FILTER_GRAYSCALE);
                imagefilter($im, IMG_FILTER_COLORIZE, 45, 25, -15);
                ai_tratamento_borda_quadrada($im, $r, $g, $b);
                break;

            case 'tia_bet':
                // Grade sutil (caderno/gráfico) + tom mais frio e nítido.
                imagefilter($im, IMG_FILTER_CONTRAST, -12);
                imagefilter($im, IMG_FILTER_COLORIZE, -10, -5, 15);
                ai_tratamento_grade($im);
                break;

            case 'chavilton':
                // Vinheta quente + grão analógico + tom mais alaranjado/saturado.
                ai_tratamento_vinheta($im, 35);
                imagefilter($im, IMG_FILTER_COLORIZE, 30, 10, -20);
                ai_tratamento_ruido($im, 0.0006);
                break;

            case 'mare_mansa':
                // Gradiente sorteado por chamada (frio/poético/debochado) — mesma
                // lógica de "sorteia a cada vez" já usada pro sotaque dela em
                // AI_REGIONALISMO_MARE: a chamada não tem memória de qual modo
                // "estava" na fala anterior, então sortear aqui é o que de fato
                // realiza a instabilidade, mesmo sem ler o texto gerado.
                $modosMare = [[40, 90, 200], [220, 90, 160], [130, 130, 130]];
                $cores     = $modosMare[array_rand($modosMare)];
                ai_tratamento_gradiente_topo($im, $cores[0], $cores[1], $cores[2], 55);
                break;

            default:
                // Agente de usuário, sem tratamento nomeado — usa só a cor
                // cadastrada dele (ai_agents.color), tratamento leve.
                ai_tratamento_gradiente_topo($im, $r, $g, $b, 30);
                break;
        }

        // Elementos universais, em cima do tratamento específico — todo
        // agente ganha a faixa de crédito legível e o selo de identidade.
        ai_tratamento_faixa_credito($im);
        ai_tratamento_selo($im, $r, $g, $b);

        ai_tratamento_salvar_imagem($im, $mime, $caminho);
        imagedestroy($im);
    } catch (Throwable $e) {
        error_log("ai_aplicar_tratamento_foto: " . $e->getMessage());
    }
}
