<?php
/**
 * Modelo de configuração das plataformas de geração de vídeo.
 *
 * Para ativar: copie este arquivo para `api/video/video_config.php` e
 * preencha as chaves que tiver. Provider sem chave preenchida é pulado em
 * silêncio por `video_gerar()` — não precisa desligar nada aqui.
 *
 *     cp api/video/video_config.example.php api/video/video_config.php
 *
 * `video_config.php` está no .gitignore, mesmo padrão de
 * `api/ai/ai_config.php` e `api/auth/mail_config.php`: chave de API não
 * entra no repositório.
 *
 * A chave da Pexels NÃO mora aqui — ela já existe em `api/ai/ai_config.php`
 * (`pexels_api_key`) e é reaproveitada por `video_pexels()` via
 * `ai_config()`, para não duplicar a mesma credencial em dois arquivos.
 */

return [
    // Google Veo — aistudio.google.com (Gemini API) ou console.cloud.google.com (Vertex AI)
    'veo_api_key' => '',

    // Kling AI — klingai.com, seção "API". HMAC-SHA256 com access_key + secret_key.
    'kling_access_key' => '',
    'kling_secret_key' => '',

    // MiniMax/Hailuo — minimaxi.com, seção "API Keys"
    'minimax_api_key' => '',
    'minimax_group_id' => '',

    // Luma AI — lumalabs.ai/dream-machine/api, seção "API Keys"
    'luma_api_key' => '',
];
