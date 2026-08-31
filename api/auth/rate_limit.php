<?php
/**
 * Freio de tentativas de login.
 *
 * Sem isso, `login.php` aceita quantas tentativas por segundo o atacante
 * conseguir mandar — a senha vira só uma questão de tempo. A contagem
 * fica em `login_attempts` e é feita por e-mail **e** por IP:
 *
 * - por e-mail, para não deixar alguém martelar uma conta específica;
 * - por IP, para não deixar varrer muitas contas do mesmo lugar.
 *
 * Tentativa que deu certo limpa o histórico daquele e-mail, então quem
 * só errou a senha e acertou em seguida não fica preso.
 */

/** Tentativas erradas por e-mail antes de bloquear. */
const LOGIN_MAX_POR_EMAIL = 5;

/** Tentativas erradas por IP antes de bloquear. */
const LOGIN_MAX_POR_IP = 20;

/** Janela de contagem e duração do bloqueio, em minutos. */
const LOGIN_JANELA_MINUTOS = 15;

/** IP de quem está chamando, já normalizado para caber na coluna. */
function login_client_ip(): string
{
    // Sem proxy reverso no projeto, então REMOTE_ADDR é a única fonte
    // confiável — X-Forwarded-For é cabeçalho que o cliente escolhe, e
    // confiar nele aqui daria ao atacante o poder de zerar o próprio
    // contador só trocando o valor.
    $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

    return substr($ip, 0, 45);
}

/**
 * Quantos segundos faltam para liberar, ou 0 se não está bloqueado.
 *
 * Falha de banco devolve 0 de propósito: se a checagem quebrar, o certo
 * é deixar entrar quem sabe a senha, não trancar todo mundo para fora.
 */
function login_bloqueado_por(PDO $pdo, string $email): int
{
    try {
        // Os segundos que faltam são calculados **dentro do SQL**, contra
        // o NOW() do banco. Fazer essa conta em PHP com strtotime() dava
        // resultado errado sempre que o relógio do PHP e o do MySQL
        // estivessem em fusos diferentes — que é o caso desta
        // instalação (PHP em UTC, MySQL em horário local): a diferença
        // de 5h zerava o bloqueio na hora.
        $sql = "SELECT
                    (SELECT COUNT(*) FROM login_attempts
                      WHERE email = :email AND succeeded = 0
                        AND created_at > DATE_SUB(NOW(), INTERVAL :jan1 MINUTE)) AS por_email,
                    (SELECT COUNT(*) FROM login_attempts
                      WHERE ip = :ip AND succeeded = 0
                        AND created_at > DATE_SUB(NOW(), INTERVAL :jan2 MINUTE)) AS por_ip,
                    (SELECT TIMESTAMPDIFF(
                                SECOND,
                                NOW(),
                                DATE_ADD(MAX(created_at), INTERVAL :jan3 MINUTE))
                       FROM login_attempts
                      WHERE (email = :email2 OR ip = :ip2) AND succeeded = 0) AS faltam";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            "email"  => $email,
            "email2" => $email,
            "ip"     => login_client_ip(),
            "ip2"    => login_client_ip(),
            "jan1"   => LOGIN_JANELA_MINUTOS,
            "jan2"   => LOGIN_JANELA_MINUTOS,
            "jan3"   => LOGIN_JANELA_MINUTOS,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 0;
        }

        $estourou = (int)$row["por_email"] >= LOGIN_MAX_POR_EMAIL
                 || (int)$row["por_ip"] >= LOGIN_MAX_POR_IP;

        if (!$estourou) {
            return 0;
        }

        $faltam = (int)($row["faltam"] ?? 0);

        return $faltam > 0 ? $faltam : 0;

    } catch (Exception $e) {
        error_log("rate_limit: " . $e->getMessage());
        return 0;
    }
}

/** Registra a tentativa. Nunca lança: registrar não pode quebrar o login. */
function login_registrar_tentativa(PDO $pdo, string $email, bool $sucesso): void
{
    try {
        $pdo->prepare(
            "INSERT INTO login_attempts (email, ip, succeeded) VALUES (?, ?, ?)"
        )->execute([substr($email, 0, 150), login_client_ip(), $sucesso ? 1 : 0]);

        if ($sucesso) {
            // Acertou: o histórico daquele e-mail deixa de contar.
            $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND succeeded = 0")
                ->execute([$email]);
        }

        // Limpeza oportunista, para a tabela não crescer sem fim. Roda
        // em ~2% das tentativas: barato e suficiente.
        if (random_int(1, 50) === 1) {
            $pdo->exec(
                "DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
        }

    } catch (Exception $e) {
        error_log("rate_limit: " . $e->getMessage());
    }
}

/** "3 minutos" / "45 segundos", para a mensagem de erro. */
function login_tempo_legivel(int $segundos): string
{
    if ($segundos >= 60) {
        $minutos = (int)ceil($segundos / 60);
        return $minutos . ($minutos === 1 ? " minuto" : " minutos");
    }

    return $segundos . ($segundos === 1 ? " segundo" : " segundos");
}
