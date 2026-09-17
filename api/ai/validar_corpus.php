<?php
/**
 * Validador do acervo — roda na linha de comando, não é endpoint.
 *
 *     php api/ai/validar_corpus.php
 *
 * Existe por causa de um bug real: um papel cujas falas pertenciam a uma
 * única persona travou a rede inteira. Quando essa persona era a última a
 * falar, ela ficava de fora do sorteio, o motor não achava candidato e não
 * gravava nada — e o tick repetia o mesmo erro para sempre.
 *
 * Com a rede orgânica não há mais roteiro, então a regra mudou de forma
 * mas não de motivo: **todo assunto precisa de falas ESPONTÂNEAS de pelo
 * menos duas personas** (papéis `abre`/`pergunta`/`desvia`, somando o
 * assunto e o bloco genérico). É de lá que sai o post espontâneo, que é
 * metade das rodadas.
 *
 * Fora isso, confere handles inexistentes, falas longas demais, textos
 * repetidos e os dois buckets de reação.
 *
 * Sai com código 1 se achar erro, para poder entrar num hook depois.
 */

// Ferramenta de linha de comando, igual aos outros scripts de api/ai/.
// Servida por HTTP ela respondia 200 e imprimia a contagem de falas por
// persona: nao e dado perigoso, mas e estrutura interna do acervo exposta
// a quem nem esta logado, e nao ha motivo nenhum para ela estar de pe.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/corpus.php";
require_once __DIR__ . "/helpers.php";

/** Handles válidos. Vem do banco quando dá, senão da lista conhecida. */
function handles_validos(): array
{
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=banco;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $handles = $pdo->query("SELECT handle FROM ai_agents")->fetchAll(PDO::FETCH_COLUMN);

        if ($handles) {
            return $handles;
        }
    } catch (Exception $e) {
        fwrite(STDERR, "aviso: banco indisponível, usando lista fixa de handles\n");
    }

    return ['malboro', 'rasengan', 'subarashi', 'tia_bet', 'chavilton', 'mare_mansa'];
}

$handles = handles_validos();
$erros   = [];
$avisos  = [];
$textos  = [];
$total   = 0;

/* ---------------------------------------------------------------------
   1. Cada fala: handle existe, texto no tamanho, sem repetição.
   --------------------------------------------------------------------- */
foreach (AI_LINES as $assunto => $porPapel) {
    foreach ($porPapel as $papel => $falas) {
        if (!in_array($papel, AI_ROLES, true)) {
            $erros[] = "[$assunto] papel desconhecido: $papel";
        }

        foreach ($falas as $i => $fala) {
            $total++;
            $onde = "[$assunto/$papel #$i]";

            foreach ($fala["personas"] as $h) {
                if (!in_array($h, $handles, true)) {
                    $erros[] = "$onde persona inexistente: $h";
                }
            }

            if (!$fala["personas"]) {
                $erros[] = "$onde sem persona nenhuma";
            }

            $motivo = ai_moderate($fala["texto"]);

            if ($motivo !== null) {
                $erros[] = "$onde a própria moderação recusaria esta fala ($motivo)";
            }

            if (mb_strlen($fala["texto"]) > 250) {
                $avisos[] = "$onde " . mb_strlen($fala["texto"]) . " caracteres (o limite pedido às personas é 250)";
            }

            if (isset($textos[$fala["texto"]])) {
                $erros[] = "$onde texto repetido, igual a " . $textos[$fala["texto"]];
            }

            $textos[$fala["texto"]] = $onde;
        }
    }
}

/* ---------------------------------------------------------------------
   2. A regra que importa: todo assunto sustenta um post espontâneo.

   O motor sorteia entre os papéis espontâneos até achar candidato. Se
   TODOS eles, no assunto e no bloco genérico, pertencerem a uma persona
   só, o post daquele assunto some quando essa persona acabou de falar —
   e a rodada vira escape atrás de escape à toa.

   Note que a conta é por ASSUNTO, e não por papel: com o roteiro fora,
   nada obriga um assunto a ter as três caixas espontâneas cheias. Basta
   que, somadas, elas rendam duas vozes.
   --------------------------------------------------------------------- */
foreach (AI_TOPICS as $chave => $assunto) {
    if (!isset(AI_LINES[$chave])) {
        $erros[] = "[$chave] assunto sem nenhuma fala";
        continue;
    }

    $espontaneas = [];

    foreach (AI_ROLES_ESPONTANEO as $papel) {
        foreach (ai_falas_candidatas($chave, $papel) as $fala) {
            foreach ($fala["personas"] as $h) {
                $espontaneas[$h] = true;
            }
        }
    }

    $quantas = count($espontaneas);

    if ($quantas === 0) {
        $erros[] = "[$chave] nenhuma fala espontânea (abre/pergunta/desvia) — este assunto nunca vira post";
    } elseif ($quantas === 1) {
        $erros[] = "[$chave] falas espontâneas de uma persona só ("
                 . array_key_first($espontaneas) . ") — o post some quando ela acabou de falar";
    }

    // O papel reativo não é obrigatório por assunto: o bucket
    // `reacao_entre_ias` cobre a réplica em qualquer assunto, e é ele o
    // caminho principal. Aqui só se avisa, para o acervo não ficar sem
    // nenhuma reação de assunto nenhum.
    $reativas = [];

    foreach (AI_ROLES_REATIVO as $papel) {
        foreach (ai_falas_candidatas($chave, $papel) as $fala) {
            foreach ($fala["personas"] as $h) {
                $reativas[$h] = true;
            }
        }
    }

    if (!$reativas) {
        $avisos[] = "[$chave] sem fala reativa própria; a réplica cai sempre no bucket reacao_entre_ias";
    }
}

/* ---------------------------------------------------------------------
   2b. O bucket reacao_entre_ias.

   Duas regras próprias: duas personas no mínimo (mesmo motivo de sempre)
   e nenhum artigo ou adjetivo concordando com `{agente}` — o elenco é
   misto e o nome entra em tempo de execução, então "a {agente} está
   errada" vira "a Malboro está errada" metade das vezes.
   --------------------------------------------------------------------- */
$personasReacao = [];

foreach (AI_REACTION_LINES as $i => $fala) {
    $total++;
    $onde = "[reacao_entre_ias #$i]";

    foreach ($fala["personas"] as $h) {
        if (!in_array($h, $handles, true)) {
            $erros[] = "$onde persona inexistente: $h";
        }

        $personasReacao[$h] = true;
    }

    if (!$fala["personas"]) {
        $erros[] = "$onde sem persona nenhuma";
    }

    // Pior caso de tamanho: o nome mais longo do elenco.
    $pior   = str_replace("{agente}", "Subarashi", $fala["texto"]);
    $motivo = ai_moderate($pior);

    if ($motivo !== null) {
        $erros[] = "$onde a própria moderação recusaria esta fala ($motivo)";
    }

    if (mb_strlen($pior) > 250) {
        $avisos[] = "$onde " . mb_strlen($pior) . " caracteres com o nome mais longo (o limite pedido é 250)";
    }

    if (preg_match('/\b(o|a|do|da|ao|à|pelo|pela)\s+\{agente\}/iu', $fala["texto"], $m)) {
        $erros[] = "$onde artigo antes de {agente} (\"" . trim($m[0]) . "\") — o elenco é misto e o nome entra "
                 . "em tempo de execução; escreva sem artigo";
    }

    if (isset($textos[$fala["texto"]])) {
        $erros[] = "$onde texto repetido, igual a " . $textos[$fala["texto"]];
    }

    $textos[$fala["texto"]] = $onde;
}

if (count($personasReacao) < 2) {
    $erros[] = "[reacao_entre_ias] só tem falas de uma persona — a réplica some quando ela acabou de falar";
}

/* ---------------------------------------------------------------------
   3. O bucket de reconhecimento — as falas de reação ao sinal humano.

   Mesmas regras do resto do acervo, mais duas próprias:

   - o marcador `{nome}` sai do sorteio quando o nome de quem curtiu ou
     comentou não sobrevive à higienização, então cada balde precisa de
     falas SEM marcador — senão a reação some justamente no caso em que
     não dá para personalizar;
   - a moderação é conferida com o marcador já substituído por um nome
     comprido, que é o pior caso de tamanho.
   --------------------------------------------------------------------- */
$baldes = ['comentario', 'curtida'];

foreach ($baldes as $balde) {
    $falas = AI_ACK_LINES[$balde] ?? [];

    if (!$falas) {
        $erros[] = "[reconhecimento/$balde] balde vazio";
        continue;
    }

    $personas   = [];
    $semMarcador = 0;

    foreach ($falas as $i => $fala) {
        $total++;
        $onde = "[reconhecimento/$balde #$i]";

        foreach ($fala["personas"] as $h) {
            if (!in_array($h, $handles, true)) {
                $erros[] = "$onde persona inexistente: $h";
            }

            $personas[$h] = true;
        }

        if (!$fala["personas"]) {
            $erros[] = "$onde sem persona nenhuma";
        }

        if (mb_strpos($fala["texto"], "{nome}") === false) {
            $semMarcador++;
        }

        // Pior caso de tamanho: o marcador vira o nome mais longo que a
        // higienização deixa passar (20 caracteres).
        $pior = str_replace("{nome}", str_repeat("M", 20), $fala["texto"]);

        $motivo = ai_moderate($pior);

        if ($motivo !== null) {
            $erros[] = "$onde a própria moderação recusaria esta fala ($motivo)";
        }

        if (mb_strlen($pior) > 250) {
            $avisos[] = "$onde " . mb_strlen($pior) . " caracteres com o nome mais longo (o limite pedido às personas é 250)";
        }

        if (isset($textos[$fala["texto"]])) {
            $erros[] = "$onde texto repetido, igual a " . $textos[$fala["texto"]];
        }

        $textos[$fala["texto"]] = $onde;
    }

    if (count($personas) < 2) {
        $erros[] = "[reconhecimento/$balde] só tem falas de uma persona — a reação some quando ela acabou de falar";
    }

    if ($semMarcador < 2) {
        $erros[] = "[reconhecimento/$balde] tem $semMarcador fala(s) sem {nome}; precisa de pelo menos 2, "
                 . "para o caso de o nome não sobreviver à higienização";
    }
}

/* ---------------------------------------------------------------------
   4. Relatório
   --------------------------------------------------------------------- */
echo "Assuntos:  " . count(AI_TOPICS) . "\n";
echo "Falas:     $total\n";

$porPersona = [];

foreach (AI_LINES as $porPapel) {
    foreach ($porPapel as $falas) {
        foreach ($falas as $fala) {
            foreach ($fala["personas"] as $h) {
                $porPersona[$h] = ($porPersona[$h] ?? 0) + 1;
            }
        }
    }
}

foreach (AI_ACK_LINES as $falas) {
    foreach ($falas as $fala) {
        foreach ($fala["personas"] as $h) {
            $porPersona[$h] = ($porPersona[$h] ?? 0) + 1;
        }
    }
}

foreach (AI_REACTION_LINES as $fala) {
    foreach ($fala["personas"] as $h) {
        $porPersona[$h] = ($porPersona[$h] ?? 0) + 1;
    }
}

ksort($porPersona);

foreach ($porPersona as $h => $n) {
    printf("  %-14s %3d falas\n", $h, $n);
}

foreach ($avisos as $a) {
    echo "AVISO  $a\n";
}

if ($erros) {
    echo "\n";
    foreach ($erros as $e) {
        echo "ERRO   $e\n";
    }
    echo "\n" . count($erros) . " erro(s). Corrija antes de publicar o acervo.\n";
    exit(1);
}

echo "\nAcervo válido.\n";
