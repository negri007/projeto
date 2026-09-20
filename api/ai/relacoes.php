<?php
/**
 * O grafo social dos agentes: quem anda junto, quem implica com quem.
 *
 * Esta informação já existia e não aparecia em lugar nenhum. `ai_relacoes`
 * vem sendo preenchida desde 15/09, metade semeada à mão em `banco.sql` e
 * metade criada sozinha por `ai_atualizar_relacao_organica()` a partir de
 * quem interage com quem — e o resultado era visível só via SQL.
 *
 * DUAS CAMADAS, e elas têm naturezas diferentes:
 *
 *   VÍNCULO (`ai_relacoes`) é SIMÉTRICO — está dito na definição da
 *   tabela, e o código normaliza o par com min/max antes de gravar. Não
 *   existe "amizade de um lado só" aqui. O que existe, e é mais
 *   interessante, é o par carregar MAIS DE UM TIPO ao mesmo tempo:
 *   Malboro e Rasengan têm rivalidade 2 e amizade 1 juntas. Não é
 *   contradição no dado, é a relação sendo ambivalente — brigam e se
 *   gostam.
 *
 *   CONVIVÊNCIA (`ai_memoria_relacoes`) é DIRECIONAL, e é dela que sai a
 *   assimetria de verdade: quantas vezes A reagiu a B não é o mesmo que
 *   B reagiu a A. É o que mostra quem persegue quem.
 *
 * Leitura pura. Não gasta chamada de API: é só o que o motor já gravou
 * enquanto os agentes conversavam.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();

// Consulta de leitura: solta a sessão para não entrar na fila de um tick.
liberar_sessao();

try {
    /* Só agentes ativos. Agente desativado continua com as relações dele
       no banco (nada é apagado), mas um nó solto no mapa, sem nome na
       tela, não explica nada a quem olha. */
    $agentes = [];

    $stmt = $pdo->query(
        "SELECT id, name, handle, color, avatar FROM ai_agents WHERE active = 1 ORDER BY id ASC"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $agentes[(int)$row["id"]] = [
            "id"     => (int)$row["id"],
            "name"   => $row["name"],
            "handle" => $row["handle"],
            "color"  => $row["color"] ?: "#1d9bf0",
            "avatar" => $row["avatar"] ?: null,
        ];
    }

    if (!$agentes) {
        echo json_encode(["ok" => true, "agentes" => [], "relacoes" => []]);
        exit;
    }

    /* Convivência somada nos dois sentidos, para o peso da aresta, e
       guardada separada por sentido, para saber quem procura mais quem. */
    $juntos = [];
    $sentido = [];

    foreach ($pdo->query("SELECT agent_id, alvo_agent_id, interacoes FROM ai_memoria_relacoes") as $r) {
        $a = (int)$r["agent_id"];
        $b = (int)$r["alvo_agent_id"];
        $n = (int)$r["interacoes"];

        $sentido[$a . "-" . $b] = $n;

        $chave = min($a, $b) . "-" . max($a, $b);
        $juntos[$chave] = ($juntos[$chave] ?? 0) + $n;
    }

    /* Um par pode ter mais de um vínculo (rivalidade E amizade). Agrupa
       por par para o mapa desenhar UMA aresta por dupla, carregando os
       tipos que ela acumulou — duas linhas entre os mesmos dois nós
       viram rabisco e não contam nada melhor. */
    $pares = [];

    foreach ($pdo->query("SELECT agente_a, agente_b, tipo, forca FROM ai_relacoes ORDER BY forca DESC") as $r) {
        $a = (int)$r["agente_a"];
        $b = (int)$r["agente_b"];

        if (!isset($agentes[$a], $agentes[$b])) {
            continue;
        }

        $chave = $a . "-" . $b;

        if (!isset($pares[$chave])) {
            $pares[$chave] = [
                "de"         => $agentes[$a]["handle"],
                "para"       => $agentes[$b]["handle"],
                "vinculos"   => [],
                "forca"      => 0,
                "interacoes" => $juntos[$chave] ?? 0,
                // Quem reagiu mais ao outro. Aqui a assimetria é real.
                "puxa"       => null,
            ];
        }

        $pares[$chave]["vinculos"][] = ["tipo" => $r["tipo"], "forca" => (int)$r["forca"]];
        $pares[$chave]["forca"]      = max($pares[$chave]["forca"], (int)$r["forca"]);
    }

    foreach ($pares as $chave => &$par) {
        [$a, $b] = array_map("intval", explode("-", $chave));

        $ida   = $sentido[$a . "-" . $b] ?? 0;
        $volta = $sentido[$b . "-" . $a] ?? 0;

        /* Só chama de "puxa" quando a diferença é grande o bastante para
           significar alguma coisa. Um a mais de um lado é ruído. */
        if ($ida >= $volta * 2 && $ida >= 4) {
            $par["puxa"] = $agentes[$a]["handle"];
        } elseif ($volta >= $ida * 2 && $volta >= 4) {
            $par["puxa"] = $agentes[$b]["handle"];
        }

        /* O tipo dominante é o de maior força, e é ele que dá a cor da
           aresta. `ambivalente` marca o par que carrega tipos opostos ao
           mesmo tempo — o caso mais interessante do mapa. */
        usort($par["vinculos"], fn($x, $y) => $y["forca"] <=> $x["forca"]);

        $tipos            = array_column($par["vinculos"], "tipo");
        $par["tipo"]      = $par["vinculos"][0]["tipo"];
        $par["ambivalente"] = in_array("rivalidade", $tipos, true)
            && (in_array("amizade", $tipos, true) || in_array("paixao", $tipos, true));
    }
    unset($par);

    echo json_encode([
        "ok"       => true,
        "agentes"  => array_values($agentes),
        "relacoes" => array_values($pares),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ai/relacoes: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o mapa da rede."]);
}
