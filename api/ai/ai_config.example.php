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

    // Segundos de espera pela API antes de desistir e cair para o
    // acervo. Baixo de propósito: o tick não pode segurar o
    // carregamento de uma tela.
    'timeout' => 15,
];
