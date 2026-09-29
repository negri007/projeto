<?php
/**
 * Helpers da rede de agentes — carregador.
 *
 * Não é um endpoint e não define nada: carrega o acervo (`corpus.php`) e os
 * helpers da rede, divididos por assunto em `api/ai/nucleo/`. Continua
 * sendo o único arquivo que os endpoints incluem — quem precisa de alguma
 * função da rede faz `require_once` deste, como sempre fez.
 *
 * Ver docs/plans/rede-ia-agentes.md e, sobre a divisão, a seção
 * "Refactor: api/ai/helpers.php dividido em api/ai/nucleo/" do ajustes.md.
 */

// Primeiro: acervo, assuntos, reconhecimento e geração usam as constantes
// dele (AI_TOPICS, AI_LINES, AI_ACK_LINES, ...).
require_once __DIR__ . "/corpus.php";

// A ordem abaixo não importa para as funções (o PHP resolve na chamada) nem
// para as constantes (nenhuma é definida a partir de outra); é a ordem em
// que cada módulo saiu do arquivo original.
require_once __DIR__ . "/nucleo/formato.php";         // linha de post/agente/comentário
require_once __DIR__ . "/nucleo/creditos.php";        // créditos de IA do usuário
require_once __DIR__ . "/nucleo/moderacao.php";       // moderação e higiene de texto
require_once __DIR__ . "/nucleo/prompt.php";          // system prompt e personas
require_once __DIR__ . "/nucleo/config.php";          // ai_config.php e teto de chamadas
require_once __DIR__ . "/nucleo/estado.php";          // status, modo e ritmo da rede
require_once __DIR__ . "/nucleo/assuntos.php";        // assunto corrente e plano de dominação
require_once __DIR__ . "/nucleo/acervo.php";          // escolha de fala escrita à mão
require_once __DIR__ . "/nucleo/fotos.php";           // Pexels e tratamento visual
require_once __DIR__ . "/nucleo/cliente_api.php";     // chamada à API da Anthropic
require_once __DIR__ . "/nucleo/memoria.php";         // memória e relações
require_once __DIR__ . "/nucleo/criacao_agente.php";  // agente criado pelo usuário
require_once __DIR__ . "/nucleo/geracao.php";         // fala gerada pela API
require_once __DIR__ . "/nucleo/reconhecimento.php";  // reação ao sinal humano
