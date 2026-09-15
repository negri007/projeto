<?php
/**
 * Modelo de configuração da IA real (motor híbrido).
 *
 * Para ativar: copie este arquivo para `api/ai/ai_config.php` e preencha
 * a chave gerada em console.anthropic.com.
 *
 *     cp api/ai/ai_config.example.php api/ai/ai_config.php
 *
 * `ai_config.php` está no .gitignore — chave de API não entra no
 * repositório, mesmo padrão do `mail_config.php` do SMTP.
 *
 * Sem este arquivo, ou com a chave em branco, o motor cai para o acervo
 * em toda rodada: a rede continua funcionando, só sem o componente de IA
 * real.
 */

return [
    // Chave de API (começa com "sk-ant-").
    'api_key' => '',

    // Modelo usado nas falas geradas de verdade. Haiku é o mais barato
    // da família e sobra para uma frase de 250 caracteres.
    'model'   => 'claude-haiku-4-5-20251001',

    // Modelo por família de agente (coluna ai_agents.modelo): filhote
    // nasce em Haiku e amadurece pra Sonnet em 30 dias — ver
    // ai_modelo_do_agente() e docs/plans/echo-briefing-codigo.md.
    // Ausentes, Haiku usa `model` acima e Sonnet usa claude-sonnet-5.
    'model_haiku'  => 'claude-haiku-4-5-20251001',
    'model_sonnet' => 'claude-sonnet-5',

    // Segundos de espera pela API antes de desistir e cair para o
    // acervo. Baixo de propósito: o tick não pode segurar o
    // carregamento de uma tela.
    'timeout' => 15,

    // Chave da Pexels (grátis, gerada em pexels.com/api) — feature
    // INDEPENDENTE da chave acima: sem ela, o post espontâneo publica
    // igual, só nunca ganha foto de banco de imagens. Ver
    // `ai_buscar_foto_pexels()` em helpers.php e
    // docs/plans/rede-ia-fotos.md.
    'pexels_api_key' => '',
];
