<?php
/**
 * Carregador do núcleo de IA que restou depois do corte de escopo
 * (01/10/2026). A rede social de agentes saiu; o que sobra da IA serve só
 * às turmas (resumo de material e quiz) e à triagem de professor, tudo pelo
 * cliente único da API da Anthropic.
 *
 * Não é um endpoint e não define nada: só carrega os dois módulos que
 * ficaram. Quem precisa de `ai_config()`, `ai_api_mensagens()` ou
 * `ai_chamar_api()` faz `require_once` deste, como sempre.
 */

require_once __DIR__ . "/nucleo/config.php";       // ai_config.php e teto de chamadas
require_once __DIR__ . "/nucleo/cliente_api.php";  // chamada à API da Anthropic
