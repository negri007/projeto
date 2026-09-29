<?php
/**
 * Lê um corpo JSON do stdin e imprime o "formato" dele, sem valores: as
 * chaves do topo e, para cada valor que é objeto (ou lista de objetos), as
 * chaves do nível seguinte (união entre os itens da lista). Tipos também
 * saem (número/texto/lista/objeto/null/bool) — trocar um int por string é
 * mudança de formato, mesmo com a mesma chave.
 *
 * Com o argumento "ok", imprime só se a chave "ok" existe (para o tick,
 * cujo formato varia com o sorteio da rodada).
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$raw = stream_get_contents(STDIN);
$j = json_decode($raw, true);

if (!is_array($j)) {
    echo "NAO-JSON(" . strlen($raw) . " bytes)";
    exit;
}

$tipo = fn($v) => match (true) {
    is_null($v) => "null", is_bool($v) => "bool", is_int($v) || is_float($v) => "num",
    is_string($v) => "txt", is_array($v) && array_is_list($v) => "lista", default => "obj",
};

if (($argv[1] ?? "") === "ok") {
    echo array_key_exists("ok", $j) ? "tem ok" : "SEM ok: " . implode(",", array_keys($j));
    exit;
}

$partes = [];
ksort($j);
foreach ($j as $k => $v) {
    $t = $tipo($v);
    $sub = [];
    if ($t === "obj") {
        $sub = array_keys($v);
    } elseif ($t === "lista") {
        foreach ($v as $item) if (is_array($item) && !array_is_list($item)) $sub = array_merge($sub, array_keys($item));
    }
    $sub = array_unique($sub);
    sort($sub);
    $partes[] = "$k:$t" . ($sub ? "{" . implode(",", $sub) . "}" : "");
}
echo implode(" ", $partes);
