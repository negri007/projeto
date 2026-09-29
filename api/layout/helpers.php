<?php
/**
 * Layout por blocos (hoje só da tela de perfil). Não é endpoint: define a
 * validação e a leitura/gravação usadas por api/layout/*.php.
 *
 * Tudo que o layout pode conter vem das listas fixas abaixo. O servidor
 * nunca grava o JSON como chegou: monta de novo, campo a campo, a partir
 * dessas listas — texto livre não tem por onde entrar. Contrato em
 * docs/API_CONTRACT.md, "Layout por blocos do perfil".
 */

/** Versão do formato do layout. */
const LAYOUT_VERSAO = 1;

/** Tamanhos: pequeno = 1/3 da largura, medio = 1/2, grande = toda. */
const LAYOUT_TAMANHOS = ["pequeno", "medio", "grande"];

/** Todas as formas que existem. Fora desta lista = "desconhecida". */
const LAYOUT_FORMAS = ["quadrado", "arredondado", "pilula", "circulo"];

/** Formas dos blocos de texto: círculo só vale para imagem. */
const LAYOUT_FORMAS_TEXTO = ["quadrado", "arredondado", "pilula"];

/**
 * Blocos de cada tela: tipo => formas aceitas e forma padrão. A lista de
 * tipos é também o máximo de blocos — cada um entra exatamente uma vez.
 */
const LAYOUT_BLOCOS = [
    "perfil" => [
        "foto"         => ["formas" => LAYOUT_FORMAS,       "padrao" => "circulo"],
        "identidade"   => ["formas" => LAYOUT_FORMAS_TEXTO, "padrao" => "arredondado"],
        "estatisticas" => ["formas" => LAYOUT_FORMAS_TEXTO, "padrao" => "arredondado"],
        "sobre"        => ["formas" => LAYOUT_FORMAS_TEXTO, "padrao" => "arredondado"],
        // Lista alta: a pílula vira uma oval e o fundo dos posts escapa
        // pela curva (visto no teste ao vivo). Só formas de canto reto.
        "publicacoes"  => ["formas" => ["quadrado", "arredondado"], "padrao" => "arredondado"],
    ],
];

/** Salvamentos de layout por pessoa por hora (freio genérico de rate_limit.php). */
const LAYOUT_MAX_SALVAR_HORA = 30;

/** Maior corpo aceito no salvar (o layout de 5 blocos tem ~400 bytes). */
const LAYOUT_MAX_BYTES = 4096;

/** A tela pedida existe? */
function layout_tela_valida(mixed $tela): bool
{
    return is_string($tela) && isset(LAYOUT_BLOCOS[$tela]);
}

/**
 * Valida e normaliza um layout. Devolve ["ok" => true, "layout" => [...]]
 * com o layout remontado só a partir das listas fixas, ou
 * ["ok" => false, "erro" => "..."] com a mensagem para o cliente.
 */
function layout_validar(string $tela, mixed $layout): array
{
    $tipos = LAYOUT_BLOCOS[$tela];
    $falha = fn(string $erro) => ["ok" => false, "erro" => $erro];

    if (!is_array($layout) || array_is_list($layout)) {
        return $falha("O layout precisa ser um objeto com versao e blocos.");
    }

    $sobra = array_diff(array_keys($layout), ["versao", "blocos"]);
    if ($sobra) {
        return $falha("Campo não permitido no layout: " . layout_nome_seguro(reset($sobra)) . ".");
    }

    if (($layout["versao"] ?? null) !== LAYOUT_VERSAO) {
        return $falha("Versão de layout não suportada.");
    }

    $blocos = $layout["blocos"] ?? null;

    if (!is_array($blocos) || !array_is_list($blocos)) {
        return $falha("`blocos` precisa ser uma lista.");
    }

    if (count($blocos) !== count($tipos)) {
        return $falha("O layout precisa ter cada bloco exatamente uma vez (" . count($tipos) . " blocos).");
    }

    $vistos = [];
    $ordens = [];
    $saida  = [];

    foreach ($blocos as $b) {
        if (!is_array($b) || array_is_list($b)) {
            return $falha("Cada bloco precisa ser um objeto.");
        }

        $sobra = array_diff(array_keys($b), ["tipo", "ordem", "tamanho", "forma"]);
        if ($sobra) {
            return $falha("Campo não permitido num bloco: " . layout_nome_seguro(reset($sobra)) . ".");
        }

        $tipo = $b["tipo"] ?? null;

        if (!is_string($tipo) || !isset($tipos[$tipo])) {
            return $falha("Tipo de bloco desconhecido.");
        }
        if (isset($vistos[$tipo])) {
            return $falha("O bloco " . $tipo . " aparece mais de uma vez.");
        }
        $vistos[$tipo] = true;

        $ordem = $b["ordem"] ?? null;

        if (!is_int($ordem) || $ordem < 1 || $ordem > count($tipos) || isset($ordens[$ordem])) {
            return $falha("A ordem dos blocos precisa ir de 1 a " . count($tipos) . ", sem repetir.");
        }
        $ordens[$ordem] = true;

        $tamanho = $b["tamanho"] ?? null;

        if (!is_string($tamanho) || !in_array($tamanho, LAYOUT_TAMANHOS, true)) {
            return $falha("Tamanho inválido no bloco " . $tipo . ".");
        }

        // Forma ausente ou desconhecida: a padrão do tipo, sem erro.
        // Conhecida, mas não permitida para este tipo: erro.
        $forma = $b["forma"] ?? null;

        if (!is_string($forma) || !in_array($forma, LAYOUT_FORMAS, true)) {
            $forma = $tipos[$tipo]["padrao"];
        } elseif (!in_array($forma, $tipos[$tipo]["formas"], true)) {
            return $falha("A forma " . $forma . " não é permitida no bloco " . $tipo . ".");
        }

        // Remontado das listas fixas: nenhum valor do cliente é copiado
        // sem antes bater exatamente com uma delas.
        $saida[] = ["tipo" => $tipo, "ordem" => $ordem, "tamanho" => $tamanho, "forma" => $forma];
    }

    usort($saida, fn($a, $b) => $a["ordem"] <=> $b["ordem"]);

    return ["ok" => true, "layout" => ["versao" => LAYOUT_VERSAO, "blocos" => $saida]];
}

/**
 * Nome de campo para a mensagem de erro: só letras, números e _, cortado.
 * O cliente escolhe o nome da chave — ele não volta cru na resposta.
 */
function layout_nome_seguro(mixed $nome): string
{
    $limpo = preg_replace('/[^A-Za-z0-9_]/', '', (string)$nome);

    return $limpo === "" ? "(sem nome)" : mb_substr($limpo, 0, 30);
}

/**
 * Layout salvo de um dono numa tela, já revalidado — ou null (sem layout,
 * ou guardado num formato que não passa mais: aí vale o automático).
 */
function layout_ler(PDO $pdo, int $userId, string $tela): ?array
{
    $stmt = $pdo->prepare("SELECT layout FROM perfil_layouts WHERE user_id = ? AND tela = ?");
    $stmt->execute([$userId, $tela]);
    $bruto = $stmt->fetchColumn();

    if ($bruto === false) {
        return null;
    }

    $r = layout_validar($tela, json_decode((string)$bruto, true));

    if (!$r["ok"]) {
        error_log("layout_ler: layout guardado de $userId/$tela não passa mais na validação: " . $r["erro"]);
        return null;
    }

    return $r["layout"];
}

/** Grava (ou substitui) o layout já validado do dono para a tela. */
function layout_gravar(PDO $pdo, int $userId, string $tela, array $layout): void
{
    $pdo->prepare(
        "INSERT INTO perfil_layouts (user_id, tela, versao, layout) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE versao = VALUES(versao), layout = VALUES(layout)"
    )->execute([$userId, $tela, LAYOUT_VERSAO, json_encode($layout, JSON_UNESCAPED_UNICODE)]);
}

/** Apaga o layout do dono para a tela: volta o automático. */
function layout_apagar(PDO $pdo, int $userId, string $tela): void
{
    $pdo->prepare("DELETE FROM perfil_layouts WHERE user_id = ? AND tela = ?")->execute([$userId, $tela]);
}

/** Resposta de erro de entrada: 400 + {"error"} e encerra. */
function layout_erro_400(string $erro): void
{
    http_response_code(400);
    echo json_encode(["error" => $erro], JSON_UNESCAPED_UNICODE);
    exit;
}
