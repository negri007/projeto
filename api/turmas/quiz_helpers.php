<?php
/**
 * Helpers do quiz por conteudo (vertical academico).
 *
 * Nao e um endpoint. Ver docs/plans/grupos-verticais.md e a secao
 * "Grupos verticais" de docs/API_CONTRACT.md.
 *
 * O GATE DESTE MODULO: o gabarito (`correta`) e a fonte (`trecho_fonte`,
 * `pagina`) so saem para o professor, ou para o aluno DEPOIS que ele
 * respondeu. As visoes abaixo montam cada resposta por lista branca de
 * campos — nunca devolvem a linha do banco inteira.
 */

require_once __DIR__ . "/helpers.php";

/** Quantas questoes o quiz pede ao modelo, e o minimo aceito depois da
 *  validacao (questao com fonte inventada e descartada). */
const TURMA_QUIZ_QUESTOES = 5;
const TURMA_QUIZ_MIN_VALIDAS = 3;

/** Nome da ferramenta que o modelo e obrigado a chamar. */
const TURMA_QUIZ_TOOL = "registrar_quiz";

/**
 * O quiz ativo de um material, com as questoes (linhas do banco, com o
 * gabarito — quem chama decide o que expor). null se nao ha quiz ativo.
 */
function turma_quiz_ativo(PDO $pdo, int $materialId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, material_id, circle_id, modelo, created_at
           FROM turma_quizzes
          WHERE material_id = ? AND ativo = 1
          ORDER BY id DESC
          LIMIT 1"
    );
    $stmt->execute([$materialId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        return null;
    }

    $quiz["id"]          = (int)$quiz["id"];
    $quiz["material_id"] = (int)$quiz["material_id"];
    $quiz["circle_id"]   = (int)$quiz["circle_id"];
    $quiz["questoes"]    = turma_quiz_questoes($pdo, $quiz["id"]);

    return $quiz;
}

/** Questoes de um quiz, na ordem, com `alternativas` ja decodificado. */
function turma_quiz_questoes(PDO $pdo, int $quizId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, ordem, enunciado, alternativas, correta, assunto, trecho_fonte, pagina
           FROM turma_quiz_questoes
          WHERE quiz_id = ?
          ORDER BY ordem ASC"
    );
    $stmt->execute([$quizId]);

    $questoes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $alts = json_decode((string)$q["alternativas"], true);

        $questoes[] = [
            "id"           => (int)$q["id"],
            "ordem"        => (int)$q["ordem"],
            "enunciado"    => $q["enunciado"],
            "alternativas" => is_array($alts) ? array_values($alts) : [],
            "correta"      => (int)$q["correta"],
            "assunto"      => $q["assunto"],
            "trecho_fonte" => $q["trecho_fonte"],
            "pagina"       => $q["pagina"] !== null ? (int)$q["pagina"] : null,
        ];
    }

    return $questoes;
}

/** Respostas de um aluno a um quiz, indexadas por questao_id. Vazio se
 *  ele ainda nao respondeu. */
function turma_quiz_respostas_do_aluno(PDO $pdo, int $quizId, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT questao_id, escolhida, acertou
           FROM turma_quiz_respostas
          WHERE quiz_id = ? AND user_id = ?"
    );
    $stmt->execute([$quizId, $userId]);

    $resp = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $resp[(int)$r["questao_id"]] = [
            "escolhida" => (int)$r["escolhida"],
            "acertou"   => (bool)$r["acertou"],
        ];
    }

    return $resp;
}

/**
 * Visao do quiz para quem pede. Tres casos, cada um por lista branca:
 * - professor: tudo, gabarito e fonte inclusos;
 * - aluno que ja respondeu: questoes + o que ele marcou + gabarito + fonte;
 * - aluno que NAO respondeu: so id, ordem, enunciado e alternativas.
 */
function turma_quiz_visao(array $quiz, bool $ehProfessor, array $respostas): array
{
    $respondeu = !$ehProfessor && count($respostas) > 0;
    $questoes  = [];
    $acertos   = 0;

    foreach ($quiz["questoes"] as $q) {
        $item = [
            "id"           => $q["id"],
            "ordem"        => $q["ordem"],
            "enunciado"    => $q["enunciado"],
            "alternativas" => $q["alternativas"],
        ];

        if ($ehProfessor || $respondeu) {
            $item["correta"]      = $q["correta"];
            $item["assunto"]      = $q["assunto"];
            $item["trecho_fonte"] = $q["trecho_fonte"];
            $item["pagina"]       = $q["pagina"];
        }

        if ($respondeu) {
            $r = $respostas[$q["id"]] ?? null;
            $item["escolhida"] = $r !== null ? $r["escolhida"] : null;
            $item["acertou"]   = $r !== null && $r["acertou"];

            if ($item["acertou"]) {
                $acertos++;
            }
        }

        $questoes[] = $item;
    }

    $visao = [
        "id"        => $quiz["id"],
        "material_id" => $quiz["material_id"],
        "created_at" => $quiz["created_at"],
        "total"     => count($questoes),
        "questoes"  => $questoes,
    ];

    if (!$ehProfessor) {
        $visao["respondido"] = $respondeu;
    }

    if ($respondeu) {
        $visao["acertos"] = $acertos;
    }

    return $visao;
}

/** System prompt da geracao — ancorado no material, sem inventar. */
function turma_quiz_system(): string
{
    return "Você é um professor montando um quiz de revisão em português do Brasil. "
        . "Use APENAS o conteúdo do material fornecido: nunca invente fatos, dados, definições "
        . "ou datas que não estejam nele. Gere exatamente " . TURMA_QUIZ_QUESTOES . " questões de "
        . "múltipla escolha, cada uma com 4 alternativas: uma correta e três erradas plausíveis "
        . "(erros típicos de quem estudou mal, não absurdos). Varie a posição da correta. "
        . "Para cada questão, `trecho_fonte` deve ser uma frase COPIADA LITERALMENTE do material "
        . "(sem reescrever) que justifica a resposta correta; `assunto` é o tópico em 1 a 4 "
        . "palavras; `pagina` é o número da página no PDF, ou null se o material é texto. "
        . "Registre o quiz chamando a ferramenta " . TURMA_QUIZ_TOOL . ".";
}

/**
 * A ferramenta que o modelo e obrigado a chamar: o `input` dela E o quiz.
 * `strict: true` faz a API garantir que o input valida contra o schema.
 * Limites de schema estrito: todo objeto com additionalProperties:false,
 * todo campo em `required`, sem minItems/maximum — tamanho e faixa do
 * indice sao conferidos no PHP (turma_quiz_validar).
 */
function turma_quiz_tool(): array
{
    return [
        "name"        => TURMA_QUIZ_TOOL,
        "description" => "Registra o quiz de múltipla escolha gerado a partir do material de aula.",
        "strict"      => true,
        "input_schema" => [
            "type"                 => "object",
            "additionalProperties" => false,
            "required"             => ["questoes"],
            "properties"           => [
                "questoes" => [
                    "type"  => "array",
                    "items" => [
                        "type"                 => "object",
                        "additionalProperties" => false,
                        "required"             => ["enunciado", "alternativas", "correta", "assunto", "trecho_fonte", "pagina"],
                        "properties"           => [
                            "enunciado"    => ["type" => "string"],
                            "alternativas" => ["type" => "array", "items" => ["type" => "string"]],
                            "correta"      => ["type" => "integer", "description" => "Índice (0 a 3) da alternativa correta."],
                            "assunto"      => ["type" => "string"],
                            "trecho_fonte" => ["type" => "string"],
                            "pagina"       => ["anyOf" => [["type" => "integer"], ["type" => "null"]]],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Monta o corpo da chamada a Messages API (sem enviar). Separado da
 * chamada para poder ser inspecionado antes da primeira geracao paga.
 *
 * - modelo Sonnet (`model_sonnet` do ai_config): o gabarito precisa
 *   estar certo;
 * - tool_use com `tool_choice` forcado na ferramenta + `strict: true`: o
 *   JSON do quiz chega em `content[].input` de um bloco `tool_use`, ja
 *   validado contra o schema;
 * - `thinking` omitido: no Sonnet 5 isso roda o pensamento adaptativo
 *   (padrao), que a API aceita junto com tool_choice forcado.
 */
function turma_quiz_corpo(array $material, array $config): ?array
{
    $texto = trim((string)($material["conteudo_texto"] ?? ""));

    if ($texto !== "") {
        $conteudo = [[
            "type" => "text",
            "text" => "<material titulo=\"" . htmlspecialchars((string)$material["titulo"], ENT_QUOTES) . "\">\n"
                    . mb_substr($texto, 0, 40000)
                    . "\n</material>\n\nMonte o quiz a partir deste material.",
        ]];
    } elseif (turma_material_resumivel($material)) {
        // PDF (unico outro caso resumivel/quizavel).
        $caminho = __DIR__ . "/../../uploads/" . $material["arquivo"];
        $bytes   = is_file($caminho) ? @file_get_contents($caminho) : false;

        if ($bytes === false || $bytes === "") {
            return null;
        }

        $conteudo = [
            [
                "type"   => "document",
                "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => base64_encode($bytes)],
            ],
            ["type" => "text", "text" => "Monte o quiz a partir deste material."],
        ];
    } else {
        return null;
    }

    return [
        "model"       => $config["model_sonnet"],
        "max_tokens"  => 16000,
        "system"      => turma_quiz_system(),
        "tools"       => [turma_quiz_tool()],
        "tool_choice" => ["type" => "tool", "name" => TURMA_QUIZ_TOOL],
        "messages"    => [["role" => "user", "content" => $conteudo]],
    ];
}

/** Normaliza texto para conferir se o trecho citado existe no material. */
function turma_quiz_normalizar(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/[“”"«»]/u', '"', $s);

    return trim((string)$s, " .;:,\"'");
}

/**
 * Valida as questoes que vieram do modelo e devolve so as boas. Descarta a
 * questao que: nao tem 4 alternativas nao vazias e distintas; tem `correta`
 * fora de 0-3; tem enunciado/assunto/fonte vazios; ou, em material de
 * texto, cita um `trecho_fonte` que NAO esta no material (fonte inventada).
 */
function turma_quiz_validar(array $questoes, array $material): array
{
    $texto   = trim((string)($material["conteudo_texto"] ?? ""));
    $textoN  = $texto !== "" ? turma_quiz_normalizar($texto) : null;
    $validas = [];

    foreach ($questoes as $q) {
        if (!is_array($q)) {
            continue;
        }

        $enunciado = trim((string)($q["enunciado"] ?? ""));
        $assunto   = trim((string)($q["assunto"] ?? ""));
        $fonte     = trim((string)($q["trecho_fonte"] ?? ""));
        $alts      = array_map(fn($a) => trim((string)$a), (array)($q["alternativas"] ?? []));
        $correta   = $q["correta"] ?? null;

        if ($enunciado === "" || $assunto === "" || $fonte === "") {
            continue;
        }

        if (count($alts) !== 4 || in_array("", $alts, true) || count(array_unique(array_map("mb_strtolower", $alts))) !== 4) {
            continue;
        }

        if (!is_int($correta) || $correta < 0 || $correta > 3) {
            continue;
        }

        if ($textoN !== null && mb_strpos($textoN, turma_quiz_normalizar($fonte)) === false) {
            continue;
        }

        $pagina = $q["pagina"] ?? null;

        $validas[] = [
            "enunciado"    => mb_substr($enunciado, 0, 1000),
            "alternativas" => array_map(fn($a) => mb_substr($a, 0, 400), $alts),
            "correta"      => $correta,
            "assunto"      => mb_substr($assunto, 0, 80),
            "trecho_fonte" => mb_substr($fonte, 0, 1000),
            "pagina"       => $textoN === null && is_int($pagina) && $pagina > 0 ? $pagina : null,
        ];
    }

    return array_slice($validas, 0, TURMA_QUIZ_QUESTOES);
}

/**
 * Gera o quiz de um material pela API. Devolve
 * ["ok"=>true,"questoes"=>[...],"modelo"=>..,"tokens_in"=>..,"tokens_out"=>..]
 * ou ["ok"=>false,"erro"=>..]. Nao grava nada.
 */
function turma_quiz_gerar(array $material): array
{
    if (!function_exists("ai_config")) {
        require_once __DIR__ . "/../ai/helpers.php";
    }

    $config = ai_config();

    if ($config === null) {
        return ["ok" => false, "erro" => "A IA não está configurada nesta instalação."];
    }

    $corpo = turma_quiz_corpo($material, $config);

    if ($corpo === null) {
        return ["ok" => false, "erro" => "Esse material não serve para quiz. Cole o texto ou envie um PDF."];
    }

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_HTTPHEADER     => [
            "content-type: application/json",
            "x-api-key: " . $config["api_key"],
            "anthropic-version: 2023-06-01",
        ],
    ]);

    $resposta = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    // A chave nunca vai para o log; so o status e o comeco do erro.
    if ($resposta === false || $status !== 200 || $erroCurl !== "") {
        error_log("turma_quiz_gerar: HTTP $status " . ($erroCurl ?: substr((string)$resposta, 0, 300)));
        return ["ok" => false, "erro" => "Não consegui gerar o quiz agora. Tente de novo."];
    }

    $dados = json_decode($resposta, true);
    $stop  = $dados["stop_reason"] ?? null;

    if ($stop === "max_tokens" || $stop === "refusal") {
        error_log("turma_quiz_gerar: stop_reason $stop");
        return ["ok" => false, "erro" => "Não consegui gerar o quiz agora. Tente de novo."];
    }

    $input = null;

    foreach ($dados["content"] ?? [] as $bloco) {
        if (($bloco["type"] ?? "") === "tool_use" && ($bloco["name"] ?? "") === TURMA_QUIZ_TOOL) {
            $input = $bloco["input"] ?? null;
            break;
        }
    }

    if (!is_array($input) || !is_array($input["questoes"] ?? null)) {
        error_log("turma_quiz_gerar: resposta sem tool_use " . TURMA_QUIZ_TOOL);
        return ["ok" => false, "erro" => "Não consegui gerar o quiz agora. Tente de novo."];
    }

    $questoes = turma_quiz_validar($input["questoes"], $material);

    if (count($questoes) < TURMA_QUIZ_MIN_VALIDAS) {
        error_log("turma_quiz_gerar: so " . count($questoes) . " questoes validas de " . count($input["questoes"]));
        return ["ok" => false, "erro" => "O quiz gerado não passou na conferência com o material. Tente de novo."];
    }

    return [
        "ok"         => true,
        "questoes"   => $questoes,
        "modelo"     => (string)($dados["model"] ?? $corpo["model"]),
        "tokens_in"  => isset($dados["usage"]["input_tokens"]) ? (int)$dados["usage"]["input_tokens"] : null,
        "tokens_out" => isset($dados["usage"]["output_tokens"]) ? (int)$dados["usage"]["output_tokens"] : null,
    ];
}
