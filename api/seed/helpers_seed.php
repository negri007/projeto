<?php
/**
 * Helpers do seed — ver docs/plans/seed-echo.md.
 *
 * Só CLI. Nenhum arquivo de `api/seed/` responde a requisição HTTP: eles
 * escrevem dezenas de linhas no banco sem pedir sessão, e uma rota web
 * que faz isso é uma rota que qualquer um chama. O guarda está no topo
 * de cada módulo, e não só aqui, porque cada um também roda sozinho.
 *
 * TRÊS COISAS QUE MUDARAM EM RELAÇÃO AO PLANO, e por quê:
 *
 * 1. `ai_chamar_api()` tem outra assinatura. O plano supõe
 *    `ai_chamar_api($pdo, $prompt, $system, 'haiku', 500)`; a função real
 *    (api/ai/helpers.php:2185) é
 *    `ai_chamar_api($system, $contexto, $maxTokens, $timeout, $maxChars, $modelo)`.
 *    `seed_ai_chamar()` mantém o papel que o plano deu a ela e chama a
 *    função existente do jeito que ela existe.
 *
 * 2. `max_tokens: 500` do plano não cabe no que o plano pede. Um lote de
 *    10 posts em JSON passa fácil de 500 tokens, e pior: `ai_chamar_api()`
 *    corta o texto devolvido em `$maxChars` (500 por padrão, AI_TEXT_MAX),
 *    o que entregaria um JSON truncado no meio — `json_decode` devolveria
 *    null e o lote inteiro cairia no fallback. Cada chamada aqui passa o
 *    teto que ela precisa.
 *
 * 3. Foto da Pexels não fica como URL externa. O plano manda gravar a URL
 *    da Pexels em `users.avatar` e `posts.image`, mas o front monta
 *    `uploads/${encodeURIComponent(valor)}` (js/echo-ui.js:1299,
 *    js/echo-feed.js:182) — uma URL ali vira `uploads/https%3A%2F%2F...`,
 *    que é imagem quebrada em toda foto do seed. O projeto já resolveu
 *    isso uma vez em `ai_buscar_foto_pexels()`: baixa, confere o MIME real
 *    e guarda o ARQUIVO. `seed_pexels_imagem()` faz o mesmo, gravando em
 *    `uploads/` (raiz), que é de onde post e avatar são servidos.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../ai/helpers.php";
require_once __DIR__ . "/../posts/helpers.php";

/** Pasta de onde o front serve avatar e imagem de post. */
const SEED_UPLOAD_DIR = __DIR__ . "/../../uploads";

/** MIME aceito na foto baixada, com a extensão que vai para o disco.
 *  Mesma desconfiança de qualquer upload: o tipo vem do finfo, nunca do
 *  que a API disse. */
const SEED_IMAGE_TYPES = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

/** Teto de chamadas por hora que o seed respeita. Um a menos que
 *  AI_TETO_CHAMADAS_HORA (20) para nunca ser o seed a fechar a cota na
 *  cara de uma rodada da Rede IA que esteja acontecendo junto. */
const SEED_TETO_HORA = 18;

/** Preço do Haiku 4.5 por milhão de tokens, para a estimativa do fim.
 *  (O plano cita 0,80/4,00, que é a tabela do Haiku 3.5.) */
const SEED_USD_INPUT_MTOK  = 1.00;
const SEED_USD_OUTPUT_MTOK = 5.00;

/** Média por chamada usada na estimativa, igual à do plano. */
const SEED_TOKENS_IN_MEDIA  = 600;
const SEED_TOKENS_OUT_MEDIA = 300;

/* ======================================================================
   CONEXÃO

   Cada módulo roda sozinho (`php api/seed/seed_lojas.php`) ou dentro do
   seed_completo. Por isso a conexão vem daqui e não de um `$pdo` global
   que só existiria no primeiro caso.

   `db.php` é código de topo de arquivo: requerido dentro de uma função,
   o `$pdo` que ele cria é local dela — é o que permite devolvê-lo sem
   vazar variável para o escopo do módulo.
   ====================================================================== */

function seed_pdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        require __DIR__ . "/../auth/db.php";

        /* `api/bootstrap.php` (incluído pelo db.php) abre um `ob_start()`
           para poder trocar o corpo da resposta por JSON quando um fatal
           acontece no meio. Num script de 10 minutos isso guardaria TODO o
           progresso para imprimir junto no fim. Solta aqui: no CLI o
           buffer não serve para nada, e quem acompanha o seed precisa ver
           a linha no momento em que ela acontece. */
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    return $pdo;
}

/* ======================================================================
   SAÍDA
   ====================================================================== */

/** Uma linha de progresso, já descarregada no terminal. */
function seed_diz(string $texto): void
{
    echo $texto . "\n";
    flush();
}

/** Título de módulo. */
function seed_titulo(string $texto): void
{
    seed_diz("");
    seed_diz("=== " . $texto . " ===");
}

/* ======================================================================
   CONTADORES DO RELATÓRIO FINAL

   O seed_completo imprime isto no fim. Ficam em `static` de uma função e
   não em global porque os módulos são incluídos em escopos diferentes
   dependendo de como foram chamados.
   ====================================================================== */

function seed_conta(string $chave, int $quanto = 1): void
{
    seed_placar($chave, $quanto);
}

function seed_placar(?string $chave = null, int $quanto = 0): array
{
    static $placar = [];

    if ($chave !== null) {
        $placar[$chave] = ($placar[$chave] ?? 0) + $quanto;
    }

    return $placar;
}

/* ======================================================================
   API DA ANTHROPIC

   Uma porta só para todo o seed: conta as chamadas, respeita o teto por
   hora, registra em `ai_api_uso` e devolve null em qualquer falha — quem
   chama cai no fallback e segue. O seed nunca para por causa da API.
   ====================================================================== */

/**
 * @param string $system   prompt de sistema
 * @param string $contexto mensagem do usuário
 * @param int    $maxTokens teto de tokens de saída
 * @param int    $maxChars  teto de caracteres do texto devolvido
 */
function seed_ai_chamar(PDO $pdo, string $system, string $contexto, int $maxTokens = 1500, int $maxChars = 8000): ?string
{
    static $chamadasHora = 0;
    static $inicioHora   = null;

    if (!seed_api_ligada()) {
        return null;
    }

    /* O SELETOR DE MODO VALE AQUI TAMBEM.

       `ai_generation_state.mode` em "acervo" e o botao de desligar a
       geracao por IA do app inteiro. O seed gasta ate ~115 chamadas
       espalhadas por horas, e furava esse botao: quem populava o banco
       com o modo em acervo pagava a conta assim mesmo.

       Devolver null aqui faz cada chamador cair no proprio fallback fixo,
       que ja existe para o caso de nao haver chave -- o mesmo caminho,
       so que agora tambem quando a geracao esta desligada de proposito.
       Custo: US$ 0,00, com a rede cheia e navegavel pelo acervo. */
    if ((ai_estado($pdo)["mode"] ?? "hibrido") === "acervo") {
        return null;
    }

    if ($inicioHora === null) {
        $inicioHora = time();
    }

    // Passou a hora: a janela recomeça.
    if (time() - $inicioHora > 3600) {
        $chamadasHora = 0;
        $inicioHora   = time();
    }

    /* O teto real não é o contador daqui: é a tabela `ai_api_uso`, que
       conta TAMBÉM as rodadas da Rede IA disparadas pelo navegador
       enquanto o seed roda. Olhar só o contador local deixaria o seed
       furar um teto que ele acha que não encostou. */
    $noBanco = ai_chamadas_api_na_ultima_hora($pdo);

    if ($chamadasHora >= SEED_TETO_HORA || $noBanco >= SEED_TETO_HORA) {
        $espera = max(60, 3600 - (time() - $inicioHora) + 5);
        seed_diz("  [cota] teto por hora encostado ({$noBanco} no banco). Aguardando {$espera}s...");
        sleep($espera);
        $chamadasHora = 0;
        $inicioHora   = time();
    }

    $config = ai_config();
    $modelo = $config["model_haiku"] ?? null;

    // Uma linha por chamada, do mesmo jeito que o tick faz: é o que
    // mantém honesto o teto por hora do resto do sistema.
    ai_registrar_chamada_api($pdo);
    $chamadasHora++;
    seed_conta("chamadas_api");

    $texto = ai_chamar_api($system, $contexto, $maxTokens, null, $maxChars, $modelo);

    if ($texto === null) {
        seed_conta("chamadas_api_falhas");
    }

    return $texto;
}

/** Existe chave de API utilizável? Avisa uma vez só, no primeiro uso. */
function seed_api_ligada(): bool
{
    static $avisou = false;

    $ok = ai_config_valida();

    if (!$ok && !$avisou) {
        $avisou = true;
        seed_diz("  [api] sem api/ai/ai_config.php com chave — todo conteúdo vem do fallback fixo.");
    }

    return $ok;
}

/**
 * JSON de resposta do modelo. Ele às vezes embrulha em ```json, às vezes
 * escreve uma linha antes do objeto — por isso a varredura do primeiro
 * `{` até o último `}` em vez de um json_decode direto.
 */
function seed_json(?string $texto): ?array
{
    if ($texto === null || trim($texto) === "") {
        return null;
    }

    $texto = preg_replace('/^```(?:json)?|```$/mi', "", trim($texto));

    $ini = strpos($texto, "{");
    $fim = strrpos($texto, "}");

    if ($ini === false || $fim === false || $fim <= $ini) {
        return null;
    }

    $dados = json_decode(substr($texto, $ini, $fim - $ini + 1), true);

    return is_array($dados) ? $dados : null;
}

/* ======================================================================
   PEXELS

   Baixa e guarda em `uploads/`, com o nome do arquivo indo para o banco
   — é o que o front sabe servir. Ver a nota 3 no topo.

   Nunca lança e nunca derruba o seed: sem chave, sem rede ou com MIME
   estranho devolve null e o post nasce sem foto.
   ====================================================================== */

function seed_pexels_imagem(string $query, string $orientacao = "landscape"): ?string
{
    $config = ai_config();
    $chave  = trim((string)($config["pexels_api_key"] ?? ""));

    if ($chave === "") {
        seed_pexels_avisar();
        return null;
    }

    $url = "https://api.pexels.com/v1/search?query=" . urlencode($query)
         . "&per_page=15&orientation=" . urlencode($orientacao);

    $json = seed_http_get($url, ["Authorization: " . $chave]);

    if ($json === null) {
        return null;
    }

    $dados = json_decode($json, true);
    $fotos = $dados["photos"] ?? [];

    if (!$fotos) {
        return null;
    }

    // Sorteia entre as encontradas: pegar sempre a primeira daria a mesma
    // foto para as cinco lojas de alimentação.
    $foto = $fotos[array_rand($fotos)];
    $src  = $foto["src"]["large"] ?? ($foto["src"]["medium"] ?? null);

    if (!$src) {
        return null;
    }

    return seed_baixar_imagem($src, "seed_" . (int)($foto["id"] ?? 0));
}

/** Avisa uma vez que não há chave da Pexels, em vez de uma vez por foto. */
function seed_pexels_avisar(): void
{
    static $avisou = false;

    if (!$avisou) {
        $avisou = true;
        seed_diz("  [pexels] sem `pexels_api_key` em api/ai/ai_config.php — nada de foto neste seed.");
    }
}

/**
 * Baixa a imagem e grava em `uploads/`. O tipo sai do finfo sobre os
 * bytes que chegaram, nunca da extensão da URL (convenção 7 do
 * ajustes.md). Devolve o nome do arquivo ou null.
 */
function seed_baixar_imagem(string $url, string $prefixo): ?string
{
    $bytes = seed_http_get($url, [], 20);

    if ($bytes === null || $bytes === "") {
        return null;
    }

    if (!is_dir(SEED_UPLOAD_DIR) && !mkdir(SEED_UPLOAD_DIR, 0775, true) && !is_dir(SEED_UPLOAD_DIR)) {
        error_log("[seed] não deu para criar a pasta uploads/");
        return null;
    }

    // Grava temporário primeiro para checar o MIME real antes de aceitar.
    $tmp = SEED_UPLOAD_DIR . "/tmp_seed_" . uniqid() . ".bin";

    if (file_put_contents($tmp, $bytes) === false) {
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    if (!isset(SEED_IMAGE_TYPES[$mime])) {
        @unlink($tmp);
        error_log("[seed] MIME inesperado vindo da Pexels ($mime)");
        return null;
    }

    $nome = $prefixo . "_" . uniqid() . "." . SEED_IMAGE_TYPES[$mime];

    if (!rename($tmp, SEED_UPLOAD_DIR . "/" . $nome)) {
        @unlink($tmp);
        return null;
    }

    seed_conta("fotos");

    return $nome;
}

/** GET simples com curl. Devolve o corpo ou null — nunca lança. */
function seed_http_get(string $url, array $headers = [], int $timeout = 15): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $corpo  = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $status !== 200 || $erro !== "") {
        error_log("[seed] GET $status " . ($erro ?: substr((string)$corpo, 0, 120)) . " — " . $url);
        return null;
    }

    return $corpo;
}

/* ======================================================================
   SORTEIO E DATAS
   ====================================================================== */

/** N itens sorteados de uma lista, sem repetir. */
function seed_amostra(array $lista, int $quantos): array
{
    if ($quantos >= count($lista)) {
        shuffle($lista);
        return $lista;
    }

    $chaves = (array)array_rand($lista, max(1, $quantos));
    $saida  = [];

    foreach ($chaves as $k) {
        $saida[] = $lista[$k];
    }

    return $saida;
}

/**
 * Data no passado, em texto pronto para o MySQL.
 *
 * O feed ordena por `created_at`/id; tudo criado no mesmo segundo vira um
 * bloco sem história. Espalhar pelos últimos N dias é o que faz a rede
 * parecer que existe há um tempo.
 */
function seed_data_passada(int $diasMax = 45): string
{
    $segundos = mt_rand(3600, $diasMax * 86400);
    return date("Y-m-d H:i:s", time() - $segundos);
}

/* ======================================================================
   AS 20 PESSOAS DO SEED

   Mora aqui, e não no módulo 1, porque três módulos precisam da mesma
   lista: o 1 cria, o 3 dá uma loja para cada uma e o 5 usa a bio para
   descrever o jeito de escrever do agente pessoal.

   `foto` é a query da Pexels. `grupo` é o que decide quem conhece quem
   nas amizades. A ordem importa: é ela que casa pessoa e loja no módulo
   3, e por isso a consulta abaixo reordena pelo e-mail em vez de confiar
   no id.

   E-mail `@echo.local` como o plano pede. Vale saber de dois handles
   ambíguos que isto cria (`@lucas` e `@bruno` já existem em contas
   `@gmail.com` desta base): pelo ajustes.md, menção ambígua é ignorada
   — o handle sai do prefixo do e-mail, não de uma coluna própria.
   ====================================================================== */

const SEED_PESSOAS = [
    ["Lucas Oliveira",  "lucas@echo.local",    "Dev apaixonado por IA e café ☕",              "technology computer",   "tech"],
    ["Ana Souza",       "ana@echo.local",      "Designer UX · amante de gatos",               "design creative",       "criativo"],
    ["Pedro Costa",     "pedro@echo.local",    "Empreendedor serial · foco em startup",       "startup office",        "tech"],
    ["Julia Ferreira",  "julia@echo.local",    "Nutricionista · vida saudável sempre",        "healthy food salad",    "saude"],
    ["Rafael Lima",     "rafael@echo.local",   "Fotógrafo urbano · capturando momentos",      "photography urban",     "criativo"],
    ["Camila Santos",   "camila@echo.local",   "Professora · leitora compulsiva 📚",          "books reading",         "letras"],
    ["Bruno Alves",     "bruno@echo.local",    "Músico · guitarrista nas horas vagas",        "guitar music",          "criativo"],
    ["Fernanda Castro", "fernanda@echo.local", "Advogada · café e séries no fim do dia",      "coffee cup desk",       "letras"],
    ["Thiago Ribeiro",  "thiago@echo.local",   "Personal trainer · vida é movimento",         "fitness gym",           "saude"],
    ["Mariana Gomes",   "mariana@echo.local",  "Veterinária · mãe de 3 cachorros 🐕",         "dog veterinary",        "saude"],
    ["Diego Martins",   "diego@echo.local",    "Arquiteto · minimalismo e bom design",        "architecture minimal",  "criativo"],
    ["Isabela Rocha",   "isabela@echo.local",  "Estudante de medicina · pré-residente",       "medical student",       "saude"],
    ["Carlos Mendes",   "carlos@echo.local",   "Chef de cozinha · criatividade no prato",     "food cooking chef",     "estilo"],
    ["Larissa Pereira", "larissa@echo.local",  "Influenciadora de moda · estilo próprio",     "fashion style",         "estilo"],
    ["Gustavo Nunes",   "gustavo@echo.local",  "Engenheiro de software · open source",        "programming code",      "tech"],
    ["Amanda Vieira",   "amanda@echo.local",   "Psicóloga · saúde mental em primeiro lugar",  "calm nature mindful",   "saude"],
    ["Ricardo Barbosa", "ricardo@echo.local",  "Contador · finanças e investimentos",         "finance charts",        "negocios"],
    ["Patricia Lima",   "patricia@echo.local", "Decoradora · transformando espaços",          "interior decoration",   "estilo"],
    ["Henrique Souza",  "henrique@echo.local", "Gamer · streamer nas horas vagas 🎮",         "gaming setup",          "tech"],
    ["Beatriz Campos",  "beatriz@echo.local",  "Jornalista · palavras têm poder",             "journalist writing",    "letras"],
];

/** Só os e-mails, para consulta e para a limpeza. */
function seed_emails(): array
{
    return array_column(SEED_PESSOAS, 1);
}

/**
 * Os usuários do seed que já existem no banco, na ordem da lista acima.
 *
 * Filtra pelos 20 e-mails e não por `LIKE '%@echo.local'`: a conta
 * `alice@echo.local` do ambiente de teste antigo é @echo.local e NÃO é
 * do seed — contá-la aqui estragaria o relatório e a limpeza.
 */
function seed_usuarios_do_seed(PDO $pdo): array
{
    $emails      = seed_emails();
    $marcadores  = implode(",", array_fill(0, count($emails), "?"));

    $stmt = $pdo->prepare(
        "SELECT id, name, email, bio FROM users
          WHERE email IN ($marcadores)
          ORDER BY FIELD(email, $marcadores)"
    );
    $stmt->execute(array_merge($emails, $emails));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ======================================================================
   Conecta já, no fim deste arquivo, antes de qualquer módulo imprimir a
   primeira linha.

   `api/auth/db.php` manda um `Content-Type` — no CLI isso não serve para
   nada, mas se QUALQUER coisa já tiver sido impressa o PHP avisa
   "Cannot modify header information" no meio do progresso. Conectar aqui
   resolve para todos os módulos de uma vez, em vez de cada um ter de
   lembrar de pedir o `$pdo` antes do primeiro echo.
   ====================================================================== */
seed_pdo();

/** Estimativa de custo das chamadas feitas, no formato do relatório. */
function seed_custo_estimado(int $chamadas): string
{
    $tokIn  = $chamadas * SEED_TOKENS_IN_MEDIA;
    $tokOut = $chamadas * SEED_TOKENS_OUT_MEDIA;

    $usd = ($tokIn / 1000000) * SEED_USD_INPUT_MTOK + ($tokOut / 1000000) * SEED_USD_OUTPUT_MTOK;

    return sprintf(
        "~%d tokens de entrada + ~%d de saída = ~US$ %.3f (Haiku 4.5: US$ %.2f/MTok in, US$ %.2f/MTok out)",
        $tokIn,
        $tokOut,
        $usd,
        SEED_USD_INPUT_MTOK,
        SEED_USD_OUTPUT_MTOK
    );
}
