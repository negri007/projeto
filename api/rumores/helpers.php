<?php
/**
 * Helpers do módulo de rumor (telefone sem fio).
 *
 * Não é um endpoint: só define funções usadas pelos arquivos de
 * `api/rumores/` e chamadas por `api/posts/create.php` (marcar origem)
 * e `api/comments/create.php` (cada comentário vira um repasse).
 */

/** A cada quantos repasses a reescrita usa a API em vez da regra simples
 *  de sinônimo/corte — economiza crédito de API na maioria das vezes,
 *  igual ao motor híbrido da Rede de IA (`AI_REAL_CHANCE`). */
const RUMOR_API_A_CADA = 5;

/** Teto de tamanho do texto distorcido — mesmo espírito do post normal,
 *  só que bem mais curto: rumor é fala de corredor, não redação. */
const RUMOR_TEXTO_MAX = 300;

/** Sinônimos comuns em pt-BR usados na distorção simples. Lista curta e
 *  genérica de propósito — não precisa cobrir tudo, só render plausível
 *  que "alguém trocou uma palavra ao repassar". */
const RUMOR_SINONIMOS = [
    'disse'      => ['falou', 'contou', 'comentou'],
    'falou'      => ['disse', 'contou'],
    'viu'        => ['flagrou', 'presenciou', 'notou'],
    'ouviu'      => ['escutou', 'soube'],
    'soube'      => ['ouviu', 'ficou sabendo'],
    'achou'      => ['acha', 'considerou'],
    'gente'      => ['pessoal', 'galera'],
    'muito'      => ['super', 'bastante'],
    'grande'     => ['enorme', 'gigante'],
    'pequeno'    => ['mínimo', 'minúsculo'],
    'rápido'     => ['ligeiro', 'veloz'],
    'ontem'      => ['outro dia', 'há pouco'],
    'hoje'       => ['agora', 'nesse instante'],
    'sempre'     => ['direto', 'toda hora'],
    'nunca'      => ['jamais', 'nem uma vez'],
    'todo mundo' => ['geral', 'a galera toda'],
    'ninguém'    => ['quase ninguém', 'nem uma alma'],
    'verdade'    => ['fato', 'certeza'],
    'mentira'    => ['invenção', 'história'],
    'amigo'      => ['parceiro', 'chegado'],
    'amiga'      => ['parceira', 'chegada'],
    'trabalho'   => ['serviço', 'correria'],
    'dinheiro'   => ['grana', 'trocado'],
    'problema'   => ['perrengue', 'complicação'],
];

/**
 * Distorção simples: troca 1-2 palavras por sinônimo e, na metade das
 * vezes, corta a última oração — o "pedaço que se perdeu no repasse".
 * Nunca devolve string vazia: se o corte zerar o texto, mantém o texto
 * como estava antes do corte.
 */
function rumor_distorcer_simples(string $texto): string
{
    $palavras = preg_split('/(\s+)/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
    $trocas   = 0;

    foreach ($palavras as $i => $token) {
        if ($trocas >= 2) {
            break;
        }

        $limpo = mb_strtolower(trim($token, ".,!?;:\"'"));

        if (isset(RUMOR_SINONIMOS[$limpo]) && mt_rand(1, 100) <= 60) {
            $sinonimo       = RUMOR_SINONIMOS[$limpo][array_rand(RUMOR_SINONIMOS[$limpo])];
            $palavras[$i]   = preg_replace('/' . preg_quote($limpo, '/') . '/ui', $sinonimo, $token);
            $trocas++;
        }
    }

    $resultado = implode('', $palavras);

    // Corte: acha o último separador de oração (. ! ?) antes do fim e,
    // na metade das vezes, joga tudo depois dele fora.
    if (mt_rand(1, 100) <= 50) {
        $pontos = [];
        preg_match_all('/[.!?]/u', $resultado, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $ocorrencia) {
            $pontos[] = $ocorrencia[1];
        }

        // Só corta num ponto que não seja o último caractere — cortar
        // ali não muda nada.
        array_pop($pontos);

        if ($pontos) {
            $corte     = $pontos[array_rand($pontos)];
            $cortado   = trim(mb_substr($resultado, 0, $corte + 1));
            if ($cortado !== '') {
                $resultado = $cortado;
            }
        }
    }

    return mb_substr(trim($resultado), 0, RUMOR_TEXTO_MAX);
}

/**
 * Reescrita via API: pede pro modelo reescrever como quem ouviu de
 * outra pessoa e lembrou errado. Devolve `null` em qualquer falha —
 * quem chama cai pra `rumor_distorcer_simples()`, o rumor nunca trava
 * esperando a API.
 */
function rumor_distorcer_api(string $texto): ?string
{
    if (!ai_config_valida()) {
        return null;
    }

    $system = "Você reescreve um boato como se fosse a próxima pessoa numa "
        . "brincadeira de telefone sem fio: ouviu a frase a seguir de "
        . "outra pessoa e está repassando de memória, com os detalhes já "
        . "meio errados — muda um nome, um número, uma consequência, sem "
        . "perder o assunto por completo. Tom de fofoca de corredor, "
        . "nunca formal. Responda só com a frase reescrita, sem aspas e "
        . "sem explicação nenhuma, em no máximo 300 caracteres.";

    $reescrita = ai_chamar_api($system, $texto, 120, null, RUMOR_TEXTO_MAX);

    if ($reescrita === null || trim($reescrita) === '') {
        return null;
    }

    return mb_substr(trim($reescrita), 0, RUMOR_TEXTO_MAX);
}

/**
 * Calcula o próximo texto da cadeia a partir do anterior — a cada
 * `RUMOR_API_A_CADA` repasses tenta a API, senão (ou se ela falhar) usa
 * a distorção simples.
 */
function rumor_proximo_texto(string $textoAnterior, int $proximaOrdem): string
{
    if ($proximaOrdem % RUMOR_API_A_CADA === 0) {
        $viaApi = rumor_distorcer_api($textoAnterior);

        if ($viaApi !== null) {
            return $viaApi;
        }
    }

    return rumor_distorcer_simples($textoAnterior);
}

/**
 * Registra um repasse quando um comentário novo entra num post que é
 * origem de rumor. Não faz nada se o post não for origem de rumor —
 * quem chama não precisa checar antes.
 *
 * Falha aqui nunca derruba o comentário: é efeito do comentário, não
 * parte da gravação dele.
 */
function rumor_registrar_repasse(PDO $pdo, int $postId, int $userId, ?int $commentId): void
{
    try {
        $stmt = $pdo->prepare("SELECT id, post_origem_id FROM rumores WHERE post_origem_id = ?");
        $stmt->execute([$postId]);
        $rumor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rumor) {
            return;
        }

        $rumorId = (int)$rumor["id"];

        $stmt = $pdo->prepare(
            "SELECT texto_distorcido FROM rumor_repasses
             WHERE rumor_id = ? ORDER BY ordem DESC LIMIT 1"
        );
        $stmt->execute([$rumorId]);
        $anterior = $stmt->fetchColumn();

        if ($anterior === false) {
            // Primeiro repasse: parte do texto original do post, não de
            // repasse nenhum (ainda não existe um).
            $stmt = $pdo->prepare("SELECT content FROM posts WHERE id = ?");
            $stmt->execute([$postId]);
            $anterior = (string)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(ordem), 0) + 1 FROM rumor_repasses WHERE rumor_id = ?"
        );
        $stmt->execute([$rumorId]);
        $proximaOrdem = (int)$stmt->fetchColumn();

        $novoTexto = rumor_proximo_texto((string)$anterior, $proximaOrdem);

        $pdo->prepare(
            "INSERT INTO rumor_repasses
                (rumor_id, ordem, comment_id, autor_id, autor_tipo, texto_distorcido)
             VALUES (?, ?, ?, ?, 'usuario', ?)"
        )->execute([$rumorId, $proximaOrdem, $commentId, $userId, $novoTexto]);

    } catch (Exception $e) {
        error_log("rumor_registrar_repasse(): " . $e->getMessage());
    }
}

/**
 * Monta a linha do tempo completa de um rumor: o post original e cada
 * repasse em ordem, com o nome de quem repassou (quando for um
 * comentário humano ainda existente).
 */
function rumor_timeline(PDO $pdo, int $postId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT r.id AS rumor_id, r.criado_em, p.content AS texto_original,
                p.user_id AS autor_original_id, u.name AS autor_original_nome
         FROM rumores r
         JOIN posts p ON p.id = r.post_origem_id
         JOIN users u ON u.id = p.user_id
         WHERE r.post_origem_id = ?"
    );
    $stmt->execute([$postId]);
    $rumor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rumor) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT rr.ordem, rr.texto_distorcido, rr.autor_tipo, rr.criado_em,
                u.name AS autor_nome
         FROM rumor_repasses rr
         LEFT JOIN users u ON u.id = rr.autor_id AND rr.autor_tipo = 'usuario'
         WHERE rr.rumor_id = ?
         ORDER BY rr.ordem ASC"
    );
    $stmt->execute([(int)$rumor["rumor_id"]]);

    $repasses = array_map(static function (array $row): array {
        return [
            "ordem"            => (int)$row["ordem"],
            "texto_distorcido" => $row["texto_distorcido"],
            "autor_tipo"       => $row["autor_tipo"],
            "autor_nome"       => $row["autor_nome"] ?? "alguém",
            "criado_em"        => $row["criado_em"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        "post_id"          => $postId,
        "criado_em"        => $rumor["criado_em"],
        "texto_original"   => $rumor["texto_original"],
        "autor_original"   => $rumor["autor_original_nome"],
        "repasses"         => $repasses,
    ];
}
