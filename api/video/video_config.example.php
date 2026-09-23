<?php
/**
 * Modelo de configuração da geração de vídeo.
 *
 * Para ativar: copie este arquivo para `api/video/video_config.php` e
 * preencha a chave do Kling. Provider sem chave é pulado em silêncio por
 * `video_gerar()` — não precisa desligar nada aqui.
 *
 *     cp api/video/video_config.example.php api/video/video_config.php
 *
 * `video_config.php` está no .gitignore, mesmo padrão de
 * `api/ai/ai_config.php` e `api/auth/mail_config.php`: chave de API não
 * entra no repositório.
 *
 * PROVEDORES: hoje o único de IA é o Kling; o Pexels é o fallback grátis
 * e não mora aqui — usa `pexels_api_key` de `api/ai/ai_config.php`, para
 * não duplicar a credencial. (Veo, MiniMax e Luma foram removidos: caros,
 * auth/endpoint quebrados no teste, e sem plano que valesse — 23/09/2026.)
 */

return [
    // Kling AI — klingai.com, Console > "API Key". Metodo novo: uma API Key
    // unica ("api-key-kling-..."), usada direto como Bearer (sem JWT).
    'kling_api_key' => '',

    // Modelo do Kling: o SEGMENTO DE PATH da API nova (/text-to-video/<modelo>),
    // ex.: 'kling-2.5-turbo', 'kling-2.6', 'kling-3.0-turbo'. Confirme na doc
    // "Text to Video". Vazio usa o default do código (kling-2.5-turbo).
    'kling_model' => '',

    // Coverr — banco de video gratis (fallback junto do Pexels). Chave em
    // coverr.co > dashboard > "Chaves de API" (demo: 50 chamadas/hora).
    'coverr_api_key' => '',
];
