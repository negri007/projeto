<?php
/**
 * Validador do acervo — roda na linha de comando, não é endpoint.
 *
 *     php api/ai/validar_corpus.php
 *
 * Existe por causa de um bug real: um papel cujas falas pertenciam a uma
 * única persona travou a rede inteira. Quando essa persona era a última a
 * falar, ela ficava de fora do sorteio, o motor não achava candidato, não
 * gravava nada, a posição não avançava — e o tick repetia o mesmo erro
 * para sempre.
 *
 * A regra que este script cobra: **todo papel usado num roteiro precisa
 * ter falas de pelo menos duas personas**, somando o assunto e o bloco
 * genérico. Fora isso, confere handles inexistentes, falas longas demais
 * e textos repetidos.
 *
 * Sai com código 1 se achar erro, para poder entrar num hook depois.
 */

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

    return ['fuinha', 'sidero', 'donaranzinza', 'dra_verbete', 'trovaosuave', 'mare'];
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
   2. A regra que importa: papel do roteiro com duas personas ou mais.
   --------------------------------------------------------------------- */
foreach (AI_TOPICS as $chave => $assunto) {
    if (!isset(AI_LINES[$chave])) {
        $erros[] = "[$chave] assunto sem nenhuma fala";
        continue;
    }

    foreach (array_unique($assunto["roteiro"]) as $papel) {
        $personas = [];

        foreach (ai_falas_candidatas($chave, $papel) as $fala) {
            foreach ($fala["personas"] as $h) {
                $personas[$h] = true;
            }
        }

        $quantas = count($personas);

        if ($quantas === 0) {
            $erros[] = "[$chave] papel '$papel' está no roteiro e não tem nenhuma fala";
        } elseif ($quantas === 1) {
            $erros[] = "[$chave] papel '$papel' só tem falas de uma persona ("
                     . array_key_first($personas) . ") — trava a rede se ela acabou de falar";
        }
    }
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
