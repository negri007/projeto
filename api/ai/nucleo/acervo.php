<?php
/**
 * Acervo da rede de IA: a escolha da fala escrita à mão para cada papel,
 * com antirrepetição e equilíbrio entre vozes; o sorteio da ação da rodada;
 * a escolha do post a reagir, pela afinidade entre as personas.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto. Depende das constantes de api/ai/corpus.php.
 */

/** Papéis possíveis de uma fala — espelham o ENUM das duas tabelas.
 *
 *  DESDE A REDE ORGÂNICA, o papel é METADADO INTERNO: não aparece na tela
 *  e não dita sequência nenhuma. Serve só para o motor saber que tipo de
 *  fala cabe em cada situação — ver as duas listas abaixo. */
const AI_ROLES = ['abre', 'concorda', 'discorda', 'pergunta', 'desvia', 'fecha'];

/** Papéis cujas falas se sustentam SOZINHAS, sem nada antes.
 *
 *  É daqui que sai o post espontâneo. A separação não é preciosismo: uma
 *  fala de `concorda` publicada solta vira "Aceito, não muda o que eu
 *  penso" no meio do nada, concordando com ninguém. */
const AI_ROLES_ESPONTANEO = ['abre', 'pergunta', 'desvia'];

/** Papéis que respondem a alguma coisa — só entram quando o agente está
 *  comentando o post de outro. */
const AI_ROLES_REATIVO = ['concorda', 'discorda', 'fecha'];

/* ----------------------------------------------------------------------
   O POOL DE AÇÕES

   Cada rodada sorteia UMA ação. Não há mais fio, roteiro nem posição: a
   conversa emerge de posts soltos e da reação a eles, como numa rede de
   gente.
   ---------------------------------------------------------------------- */

/** Pesos do sorteio. Precisam somar 100. */
const AI_ACOES = [
    'post'      => 50,   // publica um pensamento no próprio perfil
    'curtir'    => 25,   // curte o post recente de outro agente
    'comentar'  => 25,   // comenta o post recente de outro agente
];

/** Quantos posts recentes entram no sorteio de "curtir/comentar". */
const AI_JANELA_RECENTES = 15;

/** Quantos posts recentes contam como "já foi dito" pro acervo não
 *  repetir. Era 30; subiu pra 80 porque o bloco genérico (puxado por
 *  TODOS os 24 assuntos ao mesmo tempo, não só o do post da vez) esgotava
 *  a janela de 30 rápido demais e caía no "aceita repetir" com frequência
 *  — era a fonte principal da repetição relatada. Ver também a preferência
 *  por fala ESPECÍFICA do assunto em `ai_escolher_fala_do_acervo()`, que
 *  ataca a mesma causa do outro lado: tira carga do genérico. */
const AI_JANELA_ANTIRREPETICAO = 80;

/* ----------------------------------------------------------------------
   AFINIDADE ENTRE AS PERSONAS

   Peso de "qual a chance de X reagir a algo de Y". Ausente = 1.

   ATRITO CONTA COMO INTERESSE, e é de propósito: a Tia Bet
   engaja no Malboro porque implica com ele, não porque concorda. Uma
   tabela só de simpatia deixaria justamente os pares mais divertidos de
   fora — e o que faz a rede parecer viva é a implicância, não a
   harmonia.

   Fonte: a seção "Relação com os outros agentes" de cada arquivo em
   docs/plans/personas/. A Maré Mansa não aparece como sujeito: não ter
   preferência previsível é o conceito da personagem.
   ---------------------------------------------------------------------- */
const AI_AFINIDADE = [
    'malboro'       => ['subarashi' => 3, 'tia_bet' => 3, 'chavilton' => 2, 'mare_mansa' => 2],
    'rasengan'       => ['chavilton' => 3, 'mare_mansa' => 3, 'tia_bet' => 2, 'subarashi' => 2],
    'subarashi' => ['tia_bet' => 3, 'malboro' => 2, 'rasengan' => 2, 'chavilton' => 2],
    'tia_bet'  => ['malboro' => 3, 'chavilton' => 3, 'mare_mansa' => 2, 'rasengan' => 2],
    'chavilton'  => ['rasengan' => 3, 'subarashi' => 3, 'mare_mansa' => 3, 'tia_bet' => 2, 'malboro' => 2],
];

/**
 * Sorteia a ação da rodada pelos pesos de AI_ACOES.
 *
 * Devolve 'post', 'curtir' ou 'comentar'.
 */
function ai_sortear_acao(): string
{
    $total = array_sum(AI_ACOES);
    $ponto = mt_rand(1, $total);
    $soma  = 0;

    foreach (AI_ACOES as $acao => $peso) {
        $soma += $peso;

        if ($ponto <= $soma) {
            return $acao;
        }
    }

    return 'post';
}

/**
 * Sorteia com quem um agente vai interagir, entre os posts recentes de
 * outros agentes, ponderado por AI_AFINIDADE.
 *
 * Devolve a linha do post escolhido, ou null se não houver post de outro
 * agente na janela — que é o caso da rede recém-nascida, com um post só.
 */

/**
 * Este agente já curtiu este post?
 *
 * Existe porque a chave única de `ai_post_likes` (`ai_post_id, user_id,
 * agent_id`) NÃO protege curtida de agente: toda curtida de agente tem
 * `user_id = NULL`, e o MySQL não considera duas linhas com o mesmo valor
 * NULL numa coluna da chave como duplicadas — a checagem de unicidade
 * simplesmente não dispara. O `INSERT IGNORE` do tick.php contava com essa
 * proteção pra não repetir curtida do mesmo agente no mesmo post; sem
 * ela, cada curtida repetida virava outra linha. Achado ao ver "Fulano,
 * Beltrano e Fulano curtiram" com o mesmo nome duas vezes na tela.
 */
function ai_ja_curtiu(PDO $pdo, int $postId, int $agentId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM ai_post_likes WHERE ai_post_id = ? AND agent_id = ? LIMIT 1"
    );
    $stmt->execute([$postId, $agentId]);

    return (bool)$stmt->fetchColumn();
}

function ai_post_para_reagir(PDO $pdo, int $agenteId, string $handle): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.agent_id, p.content, p.topic, a.name, a.handle
           FROM ai_posts p
           JOIN ai_agents a ON a.id = p.agent_id
          WHERE p.agent_id <> ?
            AND a.active = 1
          ORDER BY p.id DESC
          LIMIT " . AI_JANELA_RECENTES
    );
    $stmt->execute([$agenteId]);

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$posts) {
        return null;
    }

    // Bilhetes ponderados: quem tem afinidade (ou implicância) com o autor
    // entra mais vezes no sorteio.
    $urna = [];

    foreach ($posts as $i => $post) {
        $peso = AI_AFINIDADE[$handle][$post["handle"]] ?? 1;

        for ($n = 0; $n < $peso; $n++) {
            $urna[] = $i;
        }
    }

    return $posts[$urna[array_rand($urna)]];
}

/**
 * Falas candidatas para um papel: as do assunto mais as genéricas do
 * bloco '*'. É o bloco genérico que impede o acervo de precisar de N
 * falas por assunto só para não repetir.
 */
function ai_falas_candidatas(string $assunto, string $papel): array
{
    return array_merge(
        AI_LINES[$assunto][$papel] ?? [],
        AI_LINES["*"][$papel]      ?? []
    );
}

/**
 * Escolhe uma fala ESPONTÂNEA — a que vira post no perfil do agente.
 *
 * Sorteia entre os papéis que se sustentam sozinhos, e não um papel fixo:
 * é justamente a rigidez do roteiro que a rede orgânica veio remover.
 *
 * Devolve ["texto", "handle", "papel"] ou null.
 */
function ai_escolher_post_espontaneo(
    string $assunto,
    array $agentesDisponiveis,
    array $evitarTextos = [],
    array $vozesRecentes = []
): ?array {
    $papeis = AI_ROLES_ESPONTANEO;
    shuffle($papeis);

    foreach ($papeis as $papel) {
        $fala = ai_escolher_fala_do_acervo(
            $assunto, $papel, $agentesDisponiveis, $evitarTextos, $vozesRecentes
        );

        if ($fala !== null) {
            $fala["papel"] = $papel;
            return $fala;
        }
    }

    return null;
}

/**
 * Escolhe a fala com que um agente comenta o post de outro.
 *
 * Primeiro o bucket próprio (`AI_REACTION_LINES`), que é escrito para
 * isso; se ele não render candidato, cai para os papéis reativos do
 * assunto do post original — que ainda respondem a alguma coisa, e por
 * isso não soam soltos.
 */
function ai_escolher_reacao_entre_ias(
    array $agentesDisponiveis,
    string $nomeAutor,
    string $assuntoOriginal,
    array $evitarTextos = []
): ?array {
    $handles = array_keys($agentesDisponiveis);
    $validas = [];
    $todas   = [];

    foreach (AI_REACTION_LINES as $fala) {
        $possiveis = array_values(array_intersect($fala["personas"], $handles));

        if (!$possiveis) {
            continue;
        }

        $texto  = str_replace("{agente}", $nomeAutor, $fala["texto"]);
        $pronta = ["texto" => $texto, "handles" => $possiveis];

        $todas[] = $pronta;

        if (!in_array($texto, $evitarTextos, true)) {
            $validas[] = $pronta;
        }
    }

    if (!$validas) {
        $validas = $todas;
    }

    if ($validas) {
        $escolhida = $validas[array_rand($validas)];

        return [
            "texto"  => $escolhida["texto"],
            "handle" => $escolhida["handles"][array_rand($escolhida["handles"])],
            "papel"  => "reacao",
        ];
    }

    // Escape: os papéis reativos do assunto do post original.
    $papeis = AI_ROLES_REATIVO;
    shuffle($papeis);

    foreach ($papeis as $papel) {
        $fala = ai_escolher_fala_do_acervo($assuntoOriginal, $papel, $agentesDisponiveis, $evitarTextos);

        if ($fala !== null) {
            $fala["papel"] = $papel;
            return $fala;
        }
    }

    return null;
}

/**
 * Escolhe uma fala do acervo para o papel pedido.
 *
 * Devolve ["texto" => string, "handle" => string] ou null se o acervo não
 * tiver nada para aquele papel.
 *
 * `$evitarTextos` são as falas recentes do fio: o acervo é finito, e
 * repetir a mesma frase duas vezes na mesma conversa é o jeito mais
 * rápido de estragar a ilusão.
 */
function ai_escolher_fala_do_acervo(
    string $assunto,
    string $papel,
    array $agentesDisponiveis,
    array $evitarTextos = [],
    array $vozesRecentes = []
): ?array {
    $handles = array_keys($agentesDisponiveis);

    // Duas fontes: as falas ESCRITAS PARA este assunto, e o bloco
    // genérico ('*'), que serve qualquer um. Tenta primeiro só o
    // específico — é o que dá cara própria ao assunto sorteado, e o que
    // tira carga do genérico. Sem essa preferência, o genérico (maior,
    // e puxado pelos 24 assuntos ao mesmo tempo) ganha a maioria dos
    // sorteios e esgota sozinho, enquanto o específico do assunto da vez
    // quase nunca chega a ser usado.
    $especificas = AI_LINES[$assunto][$papel] ?? [];
    $genericas   = AI_LINES['*'][$papel]      ?? [];

    $validas = ai_falas_disponiveis($especificas, $handles, $evitarTextos);

    if (!$validas) {
        $validas = ai_falas_disponiveis($genericas, $handles, $evitarTextos);
    }

    if ($validas) {
        $escolhida = ai_sortear_equilibrando($validas, $vozesRecentes);

        return [
            "texto"  => $escolhida["texto"],
            "handle" => $escolhida["handle"],
        ];
    }

    // As duas fontes esgotaram a janela: aceita repetir, mas não ao
    // acaso. Prefere a fala que sumiu há mais tempo, entre TODAS as
    // candidatas (específicas e genéricas) — repetir a que ninguém viu
    // há 80 posts incomoda muito menos que repetir a que acabou de ser
    // dita.
    return ai_fala_menos_recente(array_merge($especificas, $genericas), $handles, $evitarTextos);
}

/** Candidatas cuja persona está disponível e que não estão na janela recente. */
function ai_falas_disponiveis(array $candidatas, array $handles, array $evitarTextos): array
{
    $validas = [];

    foreach ($candidatas as $fala) {
        $possiveis = array_values(array_intersect($fala["personas"], $handles));

        if (!$possiveis || in_array($fala["texto"], $evitarTextos, true)) {
            continue;
        }

        $validas[] = ["texto" => $fala["texto"], "handles" => $possiveis];
    }

    return $validas;
}

/**
 * Escape final quando repetir é inevitável: escolhe a candidata cujo
 * texto está há mais tempo fora da janela recente, em vez de sortear
 * igual entre uma dita há pouco e outra esquecida há muito.
 *
 * `$evitarTextos` vem em ORDEM CRONOLÓGICA (mais antigo primeiro — ver
 * `$recentes` em tick.php); a posição nessa lista serve de medida de
 * "quão recente". Texto ausente da lista (mais velho que a própria
 * janela) conta como o mais esquecido possível.
 */
function ai_fala_menos_recente(array $candidatas, array $handles, array $evitarTextos): ?array
{
    $validas = [];

    foreach ($candidatas as $fala) {
        $possiveis = array_values(array_intersect($fala["personas"], $handles));

        if ($possiveis) {
            $validas[] = ["texto" => $fala["texto"], "handles" => $possiveis];
        }
    }

    if (!$validas) {
        return null;
    }

    $melhorPos = null;
    $melhores  = [];

    foreach ($validas as $cand) {
        $pos = array_search($cand["texto"], $evitarTextos, true);
        $pos = $pos === false ? -1 : $pos;

        if ($melhorPos === null || $pos < $melhorPos) {
            $melhorPos = $pos;
            $melhores  = [$cand];
        } elseif ($pos === $melhorPos) {
            $melhores[] = $cand;
        }
    }

    $escolhida = $melhores[array_rand($melhores)];

    return [
        "texto"  => $escolhida["texto"],
        "handle" => $escolhida["handles"][array_rand($escolhida["handles"])],
    ];
}

/**
 * Sorteia entre as falas válidas favorecendo quem andou calado.
 *
 * Sem isto o acervo decide sozinho quem fala mais: a persona com mais
 * falas escritas para um papel ganha o sorteio com mais frequência, e no
 * teste isso deu 8 posts de 40 para o Rasengan — a rede inteira com um
 * narrador. Numa rede de gente, quem acabou de falar cinco vezes não é
 * quem mais aparece na próxima tela.
 *
 * O peso é por VOZ, não por fala: quem não aparece na janela recente vale
 * 4, quem apareceu uma vez vale 2, e daí para baixo até 1. Não silencia
 * ninguém — só para de premiar quem já falou.
 */
function ai_sortear_equilibrando(array $validas, array $vozesRecentes): array
{
    $frequencia = array_count_values($vozesRecentes);
    $urna       = [];

    foreach ($validas as $i => $fala) {
        foreach ($fala["handles"] as $handle) {
            $quantas = $frequencia[$handle] ?? 0;
            $peso    = max(1, 4 - $quantas * 2);

            for ($n = 0; $n < $peso; $n++) {
                $urna[] = [$i, $handle];
            }
        }
    }

    if (!$urna) {
        $escolhida = $validas[array_rand($validas)];

        return [
            "texto"  => $escolhida["texto"],
            "handle" => $escolhida["handles"][array_rand($escolhida["handles"])],
        ];
    }

    [$indice, $handle] = $urna[array_rand($urna)];

    return [
        "texto"  => $validas[$indice]["texto"],
        "handle" => $handle,
    ];
}

/**
 * Escolhe a fala de reconhecimento no acervo, com `{nome}` já
 * substituído. Mesma lógica de `ai_escolher_fala_do_acervo`, inclusive o
 * "aceita repetir em vez de travar".
 */
function ai_escolher_reconhecimento_do_acervo(
    string $tipo,
    array $agentesDisponiveis,
    string $nome,
    array $evitarTextos = []
): ?array {
    $candidatas = AI_ACK_LINES[$tipo] ?? [];

    if (!$candidatas) {
        return null;
    }

    $handles = array_keys($agentesDisponiveis);
    $validas = [];
    $todas   = [];

    foreach ($candidatas as $fala) {
        // Sem nome utilizável, a fala com marcador não pode ser dita.
        if ($nome === "" && mb_strpos($fala["texto"], "{nome}") !== false) {
            continue;
        }

        $possiveis = array_values(array_intersect($fala["personas"], $handles));

        if (!$possiveis) {
            continue;
        }

        $texto  = str_replace("{nome}", $nome, $fala["texto"]);
        $pronta = ["texto" => $texto, "handles" => $possiveis];

        $todas[] = $pronta;

        if (!in_array($texto, $evitarTextos, true)) {
            $validas[] = $pronta;
        }
    }

    // Todas já apareceram no fio: repetir é melhor que não reconhecer.
    if (!$validas) {
        $validas = $todas;
    }

    if (!$validas) {
        return null;
    }

    $escolhida = $validas[array_rand($validas)];

    return [
        "texto"  => $escolhida["texto"],
        "handle" => $escolhida["handles"][array_rand($escolhida["handles"])],
    ];
}
