<?php
/**
 * Compara duas saídas de base_helpers.php.
 *
 * Uso: php compara_base.php base.json atual.json [funcao_esperada ...]
 *
 * - funcoes (assinatura) e constantes: têm de bater exatamente, com uma
 *   exceção: constante-caminho cujo texto mudou mas o realpath é o mesmo
 *   (mudar de pasta muda o __DIR__) — aceita e mostrada;
 * - corpos: têm de bater, menos as funções passadas como "esperadas"
 *   (ajuste de caminho feito de propósito na etapa) — aceitas e mostradas;
 * - includes: corpus.php carregado, e antes de todo api/ai/nucleo/*.
 *
 * Sai com 0 se aprovado, 1 se não.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$base  = json_decode(file_get_contents($argv[1]), true);
$atual = json_decode(file_get_contents($argv[2]), true);
$esperadas = array_map("strtolower", array_slice($argv, 3));
$erros = []; $avisos = [];

foreach (["funcoes", "corpos", "constantes"] as $k) {
    foreach (array_diff_key($base[$k], $atual[$k]) as $n => $_) $erros[] = "$k: SUMIU $n";
    foreach (array_diff_key($atual[$k], $base[$k]) as $n => $_) $erros[] = "$k: NOVA $n";
    foreach (array_intersect_key($base[$k], $atual[$k]) as $n => $v) {
        if ($atual[$k][$n] === $v) continue;

        if ($k === "corpos" && in_array($n, $esperadas, true)) {
            $avisos[] = "corpo de $n mudou (esperado: ajuste de caminho)";
            continue;
        }
        if ($k === "constantes" && isset($base["caminhos"][$n], $atual["caminhos"][$n])
            && $base["caminhos"][$n] === $atual["caminhos"][$n]) {
            $avisos[] = "texto de $n mudou, mesmo caminho real: {$atual["caminhos"][$n]}";
            continue;
        }
        $erros[] = "$k: MUDOU $n";
    }
}
foreach (array_diff($esperadas, array_keys(array_filter($atual["corpos"], fn($v, $n) => ($base["corpos"][$n] ?? null) !== $v, ARRAY_FILTER_USE_BOTH))) as $n) {
    $erros[] = "corpos: $n era esperado mudar e NÃO mudou";
}

$inc = $atual["includes"];
$posCorpus = array_search("api/ai/corpus.php", $inc, true);
$nucleo = array_filter($inc, fn($f) => str_starts_with($f, "api/ai/nucleo/"));
if ($posCorpus === false) $erros[] = "includes: corpus.php não foi carregado";
foreach ($nucleo as $pos => $f) {
    if ($posCorpus !== false && $pos < $posCorpus) $erros[] = "includes: $f carregou ANTES do corpus.php";
}

printf("base: funcoes %d/%d, corpos %d/%d, constantes %d/%d | nucleo carregados: %d, corpus.php em %s de %d includes\n",
    count($atual["funcoes"]), count($base["funcoes"]), count($atual["corpos"]), count($base["corpos"]),
    count($atual["constantes"]), count($base["constantes"]), count($nucleo),
    $posCorpus === false ? "-" : $posCorpus + 1, count($inc));
foreach ($avisos as $a) echo "  aceito: $a\n";

if ($erros) {
    echo "BASE DIFERENTE:\n  " . implode("\n  ", $erros) . "\n";
    exit(1);
}
echo "BASE IGUAL\n";
