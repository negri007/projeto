<?php
/**
 * Seed do Echo — roda os seis módulos na ordem.
 * Ver docs/plans/seed-echo.md.
 *
 *     php api/seed/seed_completo.php
 *
 * Cada módulo também roda sozinho, na ordem que quiser:
 *
 *     php api/seed/seed_lojas.php
 *
 * Só CLI, em todos eles: são scripts que escrevem dezenas de linhas no
 * banco sem pedir sessão.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";

$inicio = time();
$pdo    = seed_pdo();

seed_diz("");
seed_diz("SEED DO ECHO — " . date("d/m/Y H:i"));
seed_diz(str_repeat("-", 56));

if (!ai_config_valida()) {
    seed_diz("api/ai/ai_config.php sem chave: nada de API neste seed.");
    seed_diz("O conteúdo sai todo do fallback fixo — a rede fica cheia e");
    seed_diz("navegável, com texto mais genérico. Ver o README desta pasta.");
} else {
    seed_diz("API ligada. O teto de " . SEED_TETO_HORA . " chamadas por hora é respeitado:");
    seed_diz("se encostar nele, o seed PAUSA até a janela virar (pode levar horas).");
}

if (trim((string)(ai_config()["pexels_api_key"] ?? "")) === "") {
    seed_diz("Sem chave da Pexels: nenhum avatar, foto de post ou logo de loja.");
}

seed_diz(str_repeat("-", 56));

$modulos = [
    "seed_usuarios",
    "seed_posts_humanos",
    "seed_lojas",
    "seed_posts_comercio",
    "seed_agentes_pessoais",
    "seed_ia_posts",
];

foreach ($modulos as $modulo) {
    /* `require` e não `require_once`: cada módulo é um script de topo de
       arquivo, e o `_once` só atrapalharia quem quisesse rodar o mesmo
       módulo duas vezes no mesmo processo. Falha de um não derruba os
       outros — o seed nunca para no meio deixando a base pela metade. */
    try {
        require __DIR__ . "/{$modulo}.php";
    } catch (Throwable $e) {
        seed_diz("  [erro] {$modulo}: " . $e->getMessage());
        error_log("[seed] {$modulo}: " . $e->getMessage());
    }
}

/* ======================================================================
   RELATÓRIO

   Os números saem do BANCO, não dos contadores da rodada: é o estado
   final que interessa, e numa segunda rodada quase tudo é pulado por
   idempotência — um relatório baseado só no que esta execução inseriu
   diria "0 usuários" numa base cheia.
   ====================================================================== */

$emails     = seed_emails();
$marcadores = implode(",", array_fill(0, count($emails), "?"));

$umNumero = function (string $sql) use ($pdo, $emails): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($emails);

    return (int)$stmt->fetchColumn();
};

$usuarios = $umNumero("SELECT COUNT(*) FROM users WHERE email IN ($marcadores)");
$amizades = $umNumero(
    "SELECT COUNT(*) FROM friends f JOIN users u ON u.id = f.user_id
      WHERE u.email IN ($marcadores) AND f.status = 'accepted'"
);
$posts = $umNumero(
    "SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id WHERE u.email IN ($marcadores)"
);
$curtidas = $umNumero(
    "SELECT COUNT(*) FROM post_likes pl JOIN users u ON u.id = pl.user_id WHERE u.email IN ($marcadores)"
);
$comentarios = $umNumero(
    "SELECT COUNT(*) FROM comments c JOIN users u ON u.id = c.user_id WHERE u.email IN ($marcadores)"
);
$lojas = $umNumero(
    "SELECT COUNT(*) FROM lojas l JOIN users u ON u.id = l.user_id WHERE u.email IN ($marcadores)"
);
$produtos = $umNumero(
    "SELECT COUNT(*) FROM loja_produtos p JOIN lojas l ON l.id = p.loja_id
       JOIN users u ON u.id = l.user_id WHERE u.email IN ($marcadores)"
);
$postsLoja = $umNumero(
    "SELECT COUNT(*) FROM loja_posts lp JOIN lojas l ON l.id = lp.loja_id
       JOIN users u ON u.id = l.user_id WHERE u.email IN ($marcadores)"
);
$agentes = $umNumero(
    "SELECT COUNT(*) FROM user_agents a JOIN users u ON u.id = a.user_id WHERE u.email IN ($marcadores)"
);
$memorias = $umNumero(
    "SELECT COUNT(*) FROM user_agent_memoria m JOIN users u ON u.id = m.user_id WHERE u.email IN ($marcadores)"
);

$postsIA  = (int)$pdo->query("SELECT COUNT(*) FROM ai_posts")->fetchColumn();
$placar   = seed_placar();
$chamadas = $placar["chamadas_api"] ?? 0;
$falhas   = $placar["chamadas_api_falhas"] ?? 0;
$minutos  = round((time() - $inicio) / 60, 1);

seed_diz("");
seed_diz(str_repeat("=", 56));
seed_diz("RESUMO");
seed_diz(str_repeat("=", 56));
seed_diz(sprintf("  usuários de teste........... %d", $usuarios));
seed_diz(sprintf("  amizades aceitas............ %d (contando os dois lados)", $amizades));
seed_diz(sprintf("  posts no feed humano........ %d", $posts));
seed_diz(sprintf("  curtidas / comentários...... %d / %d", $curtidas, $comentarios));
seed_diz(sprintf("  lojas....................... %d", $lojas));
seed_diz(sprintf("  produtos no catálogo........ %d", $produtos));
seed_diz(sprintf("  posts no feed de comércio... %d", $postsLoja));
seed_diz(sprintf("  agentes pessoais............ %d", $agentes));
seed_diz(sprintf("  memórias dos agentes........ %d", $memorias));
seed_diz(sprintf("  posts no feed da Rede IA.... %d (total da tabela)", $postsIA));
seed_diz(sprintf("  fotos baixadas da Pexels.... %d", $placar["fotos"] ?? 0));
seed_diz("");
seed_diz(sprintf("  chamadas de API............. %d (%d falharam e caíram no fallback)", $chamadas, $falhas));
seed_diz("  custo estimado.............. " . ($chamadas > 0 ? seed_custo_estimado($chamadas) : "US$ 0,00 — nenhuma chamada"));
seed_diz(sprintf("  tempo....................... %s min", $minutos));
seed_diz("");
seed_diz("Contas de teste: senha123 (e-mails @echo.local).");
seed_diz("");
