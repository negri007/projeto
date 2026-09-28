<?php
/**
 * Moderação e higiene de texto da rede de IA: lista de bloqueio, padrões
 * de ataque pessoal, e a limpeza do texto humano antes de entrar num prompt.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/** Tamanho máximo do comentário humano. Bem menor que os 2000 do
 *  comentário do feed humano: este texto pode entrar num prompt. */
const AI_COMMENT_MAX = 500;

/* ======================================================================
   MODERAÇÃO LEVE

   Não é filtro de conteúdo de usuário — é guarda-corpo do tom da rede.
   Vale igual para a fala do acervo e para a gerada pela API: uma fala
   real que saia do tom é barrada do mesmo jeito.
   ====================================================================== */

/** Termos que reprovam a fala na hora. */
const AI_BLOCKLIST = [
    'idiota', 'imbecil', 'burro', 'burra', 'estúpido', 'estupido',
    'otário', 'otario', 'merda', 'porra', 'caralho', 'foda-se', 'fodase',
    'lixo humano', 'cala a boca',
];

/** Padrões de ataque pessoal (discordar sim, ofender não). */
const AI_ATTACK_PATTERNS = [
    '/\bvocê\s+é\s+(um|uma)\s+\w+/iu',
    '/\bninguém\s+aguenta\s+você/iu',
    '/\bcale?\s*-?\s*se\b/iu',
    // 'vai se ...' estava na lista de termos como substring, e
    // casava dentro de 'nao vai ser hoje'. A regra agora exige o
    // que ela sempre quis pegar, com fronteira de palavra.
    '/\bvai\s+se\s+(f\w+|lascar|catar|danar|ferrar)\b/iu',
];

/**
 * O miolo da moderação, sem checagem de tamanho: vocabulário, ataque
 * pessoal e link. Existe separado de `ai_moderate()` porque o formulário
 * de criação de agente precisa da mesma checagem de conteúdo com limites
 * de tamanho DIFERENTES por campo (nome não é bio não é personalidade) —
 * duplicar as listas seria o jeito de uma virar desatualizada da outra.
 */
function ai_moderate_conteudo(string $limpo): ?string
{
    $minusculo = mb_strtolower($limpo);

    foreach (AI_BLOCKLIST as $termo) {
        if (mb_strpos($minusculo, $termo) !== false) {
            return "vocabulario:" . $termo;
        }
    }

    foreach (AI_ATTACK_PATTERNS as $padrao) {
        if (preg_match($padrao, $limpo)) {
            return "ataque_pessoal";
        }
    }

    // Fala que é só link, ou que traz link: a rede não tem para onde
    // apontar, e link gerado por modelo costuma ser inventado.
    if (preg_match('~https?://~i', $limpo)) {
        return "link";
    }

    return null;
}

/**
 * Devolve null quando a fala pode ser publicada, ou o motivo da recusa.
 */
function ai_moderate(string $texto): ?string
{
    $limpo = trim($texto);

    if (mb_strlen($limpo) < 3) {
        return "curta_demais";
    }

    if (mb_strlen($limpo) > AI_TEXT_MAX) {
        return "longa_demais";
    }

    return ai_moderate_conteudo($limpo);
}

/**
 * Deixa o comentário humano seguro para entrar num prompt: sem caracteres
 * de controle, sem os marcadores que delimitam o bloco (senão o próprio
 * texto fecha o delimitador e o resto passa a valer como instrução) e no
 * tamanho.
 */
function ai_higienizar_comentario(string $texto): string
{
    $limpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto);
    $limpo = str_replace(["<<<", ">>>"], "", (string)$limpo);

    return mb_substr(trim($limpo), 0, AI_COMMENT_MAX);
}

/**
 * Mesma higienização do comentário humano (sem controles, sem os
 * marcadores de delimitador), com um teto de tamanho próprio: os campos
 * do formulário de criação são maiores que um comentário.
 */
function ai_higienizar_campo_criacao(string $texto): string
{
    $limpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto);
    $limpo = str_replace(["<<<", ">>>"], "", (string)$limpo);

    return mb_substr(trim($limpo), 0, AI_CRIACAO_PERSONALIDADE_MAX);
}
