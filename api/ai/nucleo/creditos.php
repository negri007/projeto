<?php
/**
 * Créditos de IA do usuário: saldo, débito na criação/edição de agente e o
 * crédito ganho por post no feed humano.
 *
 * Não é um endpoint. Carregado por api/ai/helpers.php — não inclua este
 * arquivo direto.
 */

/* ----------------------------------------------------------------------
   CRIAÇÃO DE AGENTE PELO USUÁRIO

   Além dos 6 agentes de sistema, quem usa o Echo pode criar o próprio
   agente. Custa crédito, e o crédito se ganha postando no feed humano.
   ---------------------------------------------------------------------- */

/** Créditos que ganhar a conta no cadastro. Espelha o DEFAULT da coluna
 *  `users.ai_credits` — a constante existe para o texto da tela e para
 *  qualquer lugar do código que precise citar o número sem duplicá-lo. */
const AI_CREDITS_CADASTRO = 10;

/** Quanto custa criar e editar um agente. */
const AI_CREDITS_CRIAR = 10;
const AI_CREDITS_EDITAR = 5;

/** Quanto um post humano rende, e o teto diário. */
const AI_CREDITS_POR_POST = 1;
const AI_CREDITS_POR_POST_MAX_DIA = 5;

/* ----------------------------------------------------------------------
   CRÉDITOS

   Update condicional em vez de "ler saldo, decidir, gravar": é o que
   torna o débito seguro sem trava explícita. Duas abas confirmando ao
   mesmo tempo não conseguem as duas passar — a segunda UPDATE simplesmente
   não acha linha com saldo suficiente e `rowCount()` vem 0.
   ---------------------------------------------------------------------- */

/**
 * Debita créditos de um usuário, só se o saldo alcançar.
 *
 * Devolve true se debitou (o chamador pode prosseguir e gravar o que
 * custou o crédito), false se o saldo não alcançava (nada foi alterado).
 */
function ai_debitar_creditos(PDO $pdo, int $userId, int $quanto): bool
{
    $stmt = $pdo->prepare(
        "UPDATE users SET ai_credits = ai_credits - ? WHERE id = ? AND ai_credits >= ?"
    );
    $stmt->execute([$quanto, $userId, $quanto]);

    return $stmt->rowCount() === 1;
}

/** O saldo atual, para a prévia informar antes de a pessoa confirmar. */
function ai_saldo_creditos(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT ai_credits FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Credita 1 ponto por post do feed humano, até `AI_CREDITS_POR_POST_MAX_DIA`
 * por dia. Chamada de `posts/create.php`, depois que o post já foi
 * gravado — falhar em creditar não pode desfazer uma publicação.
 *
 * O reset do contador diário acontece aqui, na hora do primeiro post do
 * dia: sem tarefa agendada no projeto, é o jeito de "todo dia começa
 * zerado" sem precisar de cron.
 */
function ai_creditar_post(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        "SELECT ai_credits_earned_today, ai_credits_earned_date FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha) {
        return;
    }

    $hoje    = date("Y-m-d");
    $ganhos  = $linha["ai_credits_earned_date"] === $hoje ? (int)$linha["ai_credits_earned_today"] : 0;

    if ($ganhos >= AI_CREDITS_POR_POST_MAX_DIA) {
        return;   // teto do dia batido, sem crédito e sem tocar no contador
    }

    $pdo->prepare(
        "UPDATE users
            SET ai_credits = ai_credits + ?,
                ai_credits_earned_today = ?,
                ai_credits_earned_date = ?
          WHERE id = ?"
    )->execute([AI_CREDITS_POR_POST, $ganhos + 1, $hoje, $userId]);
}
