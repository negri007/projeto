<?php
/**
 * Módulo 1 do seed — 20 usuários de teste, foto, bio e amizades.
 * Ver docs/plans/seed-echo.md, "Módulo 1".
 *
 * Idempotente: a chave é o e-mail (`users.email` é UNIQUE). Quem já
 * existe é reaproveitado, nunca duplicado nem sobrescrito — rodar de
 * novo depois de mexer no perfil à mão não desfaz o que foi mexido.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";

/** Senha de todas as contas de teste, igual à das que já existem (ver
 *  "Ambiente de teste" no ajustes.md). A lista das 20 pessoas está em
 *  helpers_seed.php: três módulos precisam dela. */
const SEED_SENHA = "senha123";

seed_titulo("usuários");

$pdo = seed_pdo();

$busca = $pdo->prepare("SELECT id, avatar FROM users WHERE email = ?");
$cria  = $pdo->prepare(
    "INSERT INTO users (name, email, password_hash, bio, avatar, created_at) VALUES (?, ?, ?, ?, ?, ?)"
);
$poeFoto = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");

$ids   = [];   // email => id
$grupos = [];  // grupo => [ids]

foreach (SEED_PESSOAS as [$nome, $email, $bio, $queryFoto, $grupo]) {
    $busca->execute([$email]);
    $existente = $busca->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $id = (int)$existente["id"];
        seed_diz("  já existe: {$nome}");

        // Conta sem foto (rodada anterior sem chave da Pexels) ganha a
        // foto agora, sem mexer em mais nada do perfil.
        if (empty($existente["avatar"])) {
            $foto = seed_pexels_imagem($queryFoto . " portrait person", "portrait");
            if ($foto !== null) {
                $poeFoto->execute([$foto, $id]);
            }
        }
    } else {
        seed_diz("  criando {$nome}...");

        $foto = seed_pexels_imagem($queryFoto . " portrait person", "portrait");

        $cria->execute([
            $nome,
            $email,
            password_hash(SEED_SENHA, PASSWORD_DEFAULT),
            $bio,
            $foto,
            seed_data_passada(120),
        ]);

        $id = (int)$pdo->lastInsertId();
        seed_conta("usuarios");
    }

    $ids[$email]      = $id;
    $grupos[$grupo][] = $id;
}

/* ----------------------------------------------------------------------
   AMIZADES

   Duas camadas, para a rede não sair nem em ilhas nem em bolo único:

   1. Dentro do grupo de interesse, todo mundo com todo mundo. É o que faz
      "Lucas, Gustavo e Henrique se conhecem" do plano.
   2. Mais 2 a 6 pontes sorteadas fora do grupo por pessoa — sem elas, o
      feed "Amigos" de quem é de tech nunca mostraria nada de saúde, e a
      rede viraria cinco redes separadas.

   As duas direções são gravadas. `friends` tem UNIQUE(user_id, friend_id)
   mas não é simétrica por si: as consultas do projeto procuram a linha
   nos dois sentidos, e meia amizade apareceria só de um lado.
   ---------------------------------------------------------------------- */

$liga = $pdo->prepare(
    "INSERT IGNORE INTO friends (user_id, friend_id, status, created_at) VALUES (?, ?, 'accepted', ?)"
);

$amizade = function (int $a, int $b) use ($liga): void {
    if ($a === $b) {
        return;
    }

    $quando = seed_data_passada(100);

    $liga->execute([$a, $b, $quando]);
    $novas = $liga->rowCount();

    $liga->execute([$b, $a, $quando]);
    $novas += $liga->rowCount();

    if ($novas > 0) {
        seed_conta("amizades");
    }
};

seed_diz("  ligando amizades...");

foreach ($grupos as $membros) {
    foreach ($membros as $a) {
        foreach ($membros as $b) {
            if ($a < $b) {
                $amizade($a, $b);
            }
        }
    }
}

$todos = array_values($ids);

/* Teto de amigos por pessoa. Sem ele, cada nova rodada do seed sortearia
   mais pontes e, em três ou quatro execuções, todo mundo seria amigo de
   todo mundo — o feed "Amigos" deixaria de ser diferente do "Todos", que
   é justamente a diferença que essas duas abas existem para mostrar. */
const SEED_MAX_AMIGOS = 12;

$contaAmigos = $pdo->prepare(
    "SELECT COUNT(*) FROM friends WHERE user_id = ? AND status = 'accepted'"
);

foreach ($todos as $eu) {
    $contaAmigos->execute([$eu]);
    $quantos = (int)$contaAmigos->fetchColumn();

    if ($quantos >= SEED_MAX_AMIGOS) {
        continue;
    }

    $fora = array_values(array_filter($todos, fn($x) => $x !== $eu));

    foreach (seed_amostra($fora, min(mt_rand(2, 6), SEED_MAX_AMIGOS - $quantos)) as $outro) {
        $amizade($eu, $outro);
    }
}

$placar = seed_placar();

seed_diz(sprintf(
    "  %d usuários novos, %d amizades novas (total de contas do seed: %d)",
    $placar["usuarios"] ?? 0,
    $placar["amizades"] ?? 0,
    count($ids)
));
