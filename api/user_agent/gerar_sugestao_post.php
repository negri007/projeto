<?php
/**
 * O dono pede, agora, uma sugestão de post ao próprio agente.
 *
 * É a única entrada de geração disparada à mão. Gasta chamada de API, e
 * por isso passa pelo mesmo freio por pessoa que a provocação da IAlândia
 * usa — sem ele, apertar o botão em sequência consumiria a cota da hora
 * inteira e calaria a Rede IA para todo mundo.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../ai/limite_uso.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

try {
    $agente = user_agent_obter($pdo, $userId);

    if (!$agente) {
        echo json_encode(["error" => "Você ainda não tem um Echo."]);
        exit;
    }

    // O teto global protege a fatura; este protege os usuários uns dos
    // outros. Ver api/ai/limite_uso.php.
    $freio = ai_pode_provocar($pdo, $userId);

    if (!$freio["ok"]) {
        http_response_code(429);
        echo json_encode([
            "error" => "Você já pediu bastante coisa ao seu Echo. Tente de novo em "
                . login_tempo_legivel((int)$freio["espera"]) . ".",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r     = user_agent_gerar_sugestao_post($pdo, $userId);
    $texto = $r["texto"];

    /* Cada motivo tem a sua frase, porque as saídas pedem coisas
       diferentes de quem está lendo: sem memória, publique mais; sem
       cota, espere; modo acervo, ligue a API no seletor. Uma mensagem só
       para os três mandaria a pessoa fazer o que não resolve. */
    if ($texto === null) {
        $frases = [
            "sem_agente"  => "Você ainda não tem um Echo.",
            "modo_acervo" => "A geração por IA está desligada. Ligue em Rede IA, "
                . "no seletor \"Modo de geração\".",
            "sem_cota"    => "A rede está no limite de uso desta hora. Tente daqui a pouco.",
            "sem_memoria" => "Seu Echo ainda não tem o que dizer. Publique e curta um "
                . "pouco para ele aprender o seu jeito.",
            "falha_api"   => "Não consegui gerar agora. Tente de novo em instantes.",
        ];

        echo json_encode([
            "ok"     => true,
            "gerou"  => false,
            "motivo" => $frases[$r["motivo"]] ?? "Não consegui gerar agora.",
            "codigo" => $r["motivo"],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // A mesma moderação das falas da Rede IA: o texto sai de um modelo e
    // vai virar post assinado por uma pessoa.
    $motivo = ai_moderate($texto);

    if ($motivo !== null) {
        error_log("user_agent/gerar_sugestao_post moderação recusou ($motivo)");
        echo json_encode([
            "ok"     => true,
            "gerou"  => false,
            "motivo" => "A sugestão saiu do tom e foi descartada. Tente de novo.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = user_agent_guardar_sugestao($pdo, $userId, "post", $texto);

    echo json_encode([
        "ok"       => true,
        "gerou"    => true,
        "sugestao" => [
            "id"       => $id,
            "tipo"     => "post",
            "sugestao" => $texto,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("user_agent/gerar_sugestao_post: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao gerar a sugestão."]);
}
