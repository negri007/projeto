<?php
/**
 * Retrato de api/ai/helpers.php para comparar antes e depois de um refactor.
 *
 * Carrega o helpers.php como os endpoints carregam e imprime, em JSON:
 * - funcoes: toda função de usuário definida, com a assinatura completa
 *   (parâmetros, tipos, padrões, referência, variádico, tipo de retorno);
 * - corpos: md5 do código-fonte de cada função (linhas da declaração ao
 *   fecha-chaves, sem o fim de linha) — prova que nada foi reescrito;
 * - constantes: toda constante de usuário, com o md5 do valor exportado;
 * - caminhos: para constante que é caminho existente, o realpath (mudar o
 *   arquivo de pasta muda o texto de __DIR__, não a pasta);
 * - includes: a ordem em que os arquivos de api/ foram carregados, para
 *   provar que corpus.php vem antes de api/ai/nucleo/*.
 *
 * Uso (da raiz do projeto): php tools/verificacao/base_helpers.php > saida.json
 * Só linha de comando; por HTTP, 404 (e a pasta é negada no .htaccess).
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$raiz = str_replace("\\", "/", dirname(__DIR__, 2));
require $raiz . "/api/ai/helpers.php";

$funcoes = [];
$corpos  = [];

foreach (get_defined_functions()["user"] as $nome) {
    $r  = new ReflectionFunction($nome);
    $ps = [];

    foreach ($r->getParameters() as $p) {
        $s = ($p->hasType() ? $p->getType() . " " : "") . ($p->isPassedByReference() ? "&" : "")
           . ($p->isVariadic() ? "..." : "") . "$" . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $s .= " = " . ($p->isDefaultValueConstant() ? $p->getDefaultValueConstantName() : var_export($p->getDefaultValue(), true));
        }
        $ps[] = $s;
    }

    $funcoes[strtolower($nome)] = "(" . implode(", ", $ps) . ")" . ($r->hasReturnType() ? ": " . $r->getReturnType() : "");

    $linhas = array_slice(file($r->getFileName()), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1);
    $corpos[strtolower($nome)] = md5(implode("\n", array_map(fn($l) => rtrim($l, "\r\n"), $linhas)));
}

ksort($funcoes);
ksort($corpos);

$constantes = [];
$caminhos   = [];
foreach (get_defined_constants(true)["user"] ?? [] as $nome => $valor) {
    $constantes[$nome] = md5(var_export($valor, true));
    if (is_string($valor) && str_contains($valor, "/") && file_exists($valor)) {
        $caminhos[$nome] = str_replace("\\", "/", realpath($valor));
    }
}
ksort($constantes);

$includes = array_values(array_filter(array_map(
    fn($f) => substr(str_replace("\\", "/", $f), strlen($raiz) + 1),
    get_included_files()
), fn($f) => str_starts_with($f, "api/")));

echo json_encode(
    ["funcoes" => $funcoes, "corpos" => $corpos, "constantes" => $constantes, "caminhos" => $caminhos, "includes" => $includes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), "\n";
