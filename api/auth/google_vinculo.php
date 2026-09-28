<?php
/**
 * Acha, vincula ou cria o usuário local de um login com Google.
 *
 * Não é um endpoint: define a função usada por google_callback.php. Mora
 * à parte do callback para poder ser chamada sem o fluxo OAuth — o
 * callback só chega aqui depois de trocar `code` por token na Google, e
 * isso não dá para reproduzir num teste.
 */

/**
 * Devolve `["id" => int, "name" => string, "session_version" => int]`
 * pronto para start_user_session(), ou null quando o e-mail já está
 * vinculado a OUTRA conta Google (quem chama decide como falhar).
 *
 * Três caminhos:
 * - `google_id` já conhecido → a conta dele, sem mexer em nada;
 * - e-mail de conta local → vincula, apaga a senha e derruba as sessões;
 * - e-mail novo → cria conta sem senha (`password_hash` NULL).
 *
 * Erro de banco sobe como exceção.
 */
function google_vincular_usuario(PDO $pdo, string $googleId, string $email, string $name): ?array
{
    // Já logou com o Google antes?
    $stmt = $pdo->prepare("SELECT id, name, session_version FROM users WHERE google_id = ?");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return [
            "id"              => (int)$user["id"],
            "name"            => $user["name"],
            "session_version" => (int)$user["session_version"],
        ];
    }

    // Conta local com o mesmo e-mail já existe — vincula em vez de criar
    // uma segunda conta para a mesma pessoa.
    $stmt = $pdo->prepare("SELECT id, name, google_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existente) {
        $pdo->prepare(
            "INSERT INTO users (name, email, google_id, password_hash) VALUES (?, ?, ?, NULL)"
        )->execute([$name, $email, $googleId]);

        return [
            "id"              => (int)$pdo->lastInsertId(),
            "name"            => $name,
            "session_version" => 1,
        ];
    }

    // google_id já é UNIQUE no banco; isto só evita o erro de constraint
    // virar um 500 genérico.
    if ($existente["google_id"] !== null && $existente["google_id"] !== $googleId) {
        return null;
    }

    // O vínculo APAGA a senha e derruba as sessões abertas.
    //
    // O cadastro não confirma e-mail: qualquer um cria a conta
    // "vitima@gmail.com" com uma senha sua. Se o vínculo só gravasse o
    // google_id, a dona do e-mail entraria pelo Google numa conta cuja
    // senha outra pessoa conhece — e essa pessoa continuaria entrando por
    // login.php e lendo tudo. O Google acabou de provar a posse do e-mail;
    // a senha antiga não prova nada. Quem é dona de verdade e quer senha de
    // novo usa a recuperação por e-mail, que também prova posse.
    //
    // `session_version + 1` expulsa quem já estava logado com a senha
    // velha (session_validate_version, em db.php).
    $pdo->prepare(
        "UPDATE users
            SET google_id = ?, password_hash = NULL, session_version = session_version + 1
          WHERE id = ?"
    )->execute([$googleId, $existente["id"]]);

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$existente["id"]]);

    return [
        "id"              => (int)$existente["id"],
        "name"            => $existente["name"],
        "session_version" => (int)$stmt->fetchColumn(),
    ];
}
