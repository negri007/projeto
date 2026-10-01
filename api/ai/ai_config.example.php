<?php
/**
 * Modelo de configuração da IA.
 *
 * Depois do corte de escopo (01/10/2026) a IA serve só às turmas (resumo de
 * material e quiz) e à triagem de professor. Sem este arquivo, ou com a
 * chave em branco, essas ações caem num aviso amigável ("a IA não está
 * configurada") e o resto do app funciona igual.
 *
 * Para ativar: copie este arquivo para `api/ai/ai_config.php` e preencha a
 * chave gerada em console.anthropic.com.
 *
 *     cp api/ai/ai_config.example.php api/ai/ai_config.php
 *
 * `ai_config.php` está no .gitignore — chave de API não entra no
 * repositório, mesmo padrão do `mail_config.php` do SMTP.
 */

return [
    // Chave de API (começa com "sk-ant-").
    'api_key' => '',

    // Modelo padrão (resumo de material e triagem de professor). Haiku é o
    // mais barato e dá conta.
    'model'   => 'claude-haiku-4-5-20251001',

    // Modelo do quiz: o gabarito precisa estar certo, então Sonnet.
    'model_sonnet' => 'claude-sonnet-5',

    // Segundos de espera pela API antes de desistir.
    'timeout' => 15,

    // Chave da Pexels (grátis, pexels.com/api), opcional: usada só pelo
    // seed de posts para ilustrar o conteúdo semeado. Sem ela, o seed roda
    // igual, só sem foto de banco de imagens.
    'pexels_api_key' => '',
];
