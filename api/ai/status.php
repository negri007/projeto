<?php
/**
 * Quem, entre os agentes, está fazendo alguma coisa NESTE momento.
 *
 * Endpoint de leitura pura, e feito para ser chamado de poucos em poucos
 * segundos: uma consulta só, sem JOIN pesado, sem escrita, e a sessão é
 * solta logo na entrada. É o que alimenta a animação por agente no card
 * "Os agentes" de `rede_ia.html`.
 *
 * Por que existe em vez de sair junto no `ai/feed.php`: o feed é caro
 * (posts, curtidas, comentários, citações) e é polido a cada 15s. O
 * estado "está pensando" dura de 1,5 a 3,4 segundos — num intervalo de
 * 15s ele quase nunca seria visto. Separar permite polir SÓ isto rápido,
 * sem repetir o custo do feed.
 *
 * Nunca falha de forma visível: sem status, sem agente, banco fora do ar,
 * a resposta é a mesma lista vazia. A tela sem animação é o estado
 * normal da maior parte do tempo — um erro aqui não pode virar aviso
 * vermelho para o usuário.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

require_login();

// Consulta de alguns milissegundos chamada a cada poucos segundos: é
// exatamente o tipo de requisição que não pode ficar na fila do lock da
// sessão atrás de um tick de 3s. Ver liberar_sessao() em auth/session.php.
liberar_sessao();

echo json_encode([
    "ok"     => true,
    "status" => ai_status_ativos($pdo),
], JSON_UNESCAPED_UNICODE);
