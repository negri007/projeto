# Plano — Geração de Vídeo com IA para o Echo

> Salvar em `docs/plans/plano-videos-ia.md` antes de executar.
> Leia o `ajustes.md` completo antes de começar.
> Branch: `feature/videos-ia`

---

## Visão geral

Sistema de geração de vídeo com IA para o feed de comércio do Echo.
O lojista digita um prompt e clica em gerar — o sistema tenta as plataformas
na ordem de qualidade e armazena o vídeo localmente com URL da plataforma como backup.

**Ordem de fallback:**
1. Google Veo (melhor qualidade)
2. Kling AI
3. MiniMax/Hailuo
4. Luma AI
5. Pexels Vídeo (fallback sempre disponível)

---

## Schema — `banco.sql` (idempotente)

```sql
-- Chaves de API das plataformas de vídeo
CREATE TABLE IF NOT EXISTS video_providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL,         -- 'veo', 'kling', 'minimax', 'luma', 'pexels'
  ativo TINYINT NOT NULL DEFAULT 1,
  creditos_restantes INT NULL,        -- atualizado após cada chamada
  ultimo_erro TEXT NULL,              -- último erro registrado
  ultimo_uso DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Vídeos gerados e armazenados
CREATE TABLE IF NOT EXISTS videos_gerados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NULL,                   -- NULL se for vídeo de agente de IA
  agent_id INT NULL,                  -- NULL se for vídeo de loja
  prompt TEXT NOT NULL,               -- prompt usado para gerar
  provider VARCHAR(50) NOT NULL,      -- qual plataforma gerou
  arquivo_local VARCHAR(500) NULL,    -- caminho em uploads/videos/
  url_plataforma VARCHAR(1000) NULL,  -- URL da plataforma como backup
  duracao_segundos TINYINT NULL,
  status ENUM('gerando','pronto','erro') NOT NULL DEFAULT 'gerando',
  erro TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_vg_loja ON videos_gerados(loja_id, status);
CREATE INDEX IF NOT EXISTS idx_vg_agent ON videos_gerados(agent_id, status);

-- Seed dos providers na ordem de prioridade
INSERT IGNORE INTO video_providers (id, nome, ativo) VALUES
(1, 'veo', 1),
(2, 'kling', 1),
(3, 'minimax', 1),
(4, 'luma', 1),
(5, 'pexels', 1);
```

---

## Configuração das chaves — `api/video/video_config.php`

Arquivo fora do repositório (no `.gitignore`). Modelo em `api/video/video_config.example.php`:

```php
<?php
// Google Veo — aistudio.google.com
define('VIDEO_VEO_API_KEY', '');
define('VIDEO_VEO_PROJECT_ID', '');

// Kling AI — klingai.com (usar MCP ou API Key)
define('VIDEO_KLING_API_KEY', '');
define('VIDEO_KLING_ACCESS_KEY', '');
define('VIDEO_KLING_SECRET_KEY', '');

// MiniMax/Hailuo — minimax.io
define('VIDEO_MINIMAX_API_KEY', '');
define('VIDEO_MINIMAX_GROUP_ID', '');

// Luma AI — lumalabs.ai
define('VIDEO_LUMA_API_KEY', '');

// Pexels — já existe em ai_config.php, reutiliza
// define('PEXELS_API_KEY', ''); -- já definido
```

---

## Back-end — `api/video/helpers.php`

### Função principal

```php
/**
 * Gera um vídeo tentando cada provider na ordem de prioridade.
 * Salva localmente e retorna o id do registro em videos_gerados.
 *
 * @param PDO    $pdo
 * @param string $prompt    Prompt descrevendo o vídeo
 * @param int    $lojaId    ID da loja (ou 0 para agente de IA)
 * @param int    $agentId   ID do agente (ou 0 para loja)
 * @return array {ok, video_id, arquivo, url, provider, erro}
 */
function video_gerar(PDO $pdo, string $prompt, int $lojaId = 0, int $agentId = 0): array
```

### Funções por provider

```php
function video_veo(string $prompt): ?string        // retorna URL do vídeo ou null
function video_kling(string $prompt): ?string
function video_minimax(string $prompt): ?string
function video_luma(string $prompt): ?string
function video_pexels(string $query): ?string      // busca por palavra-chave, não gera
```

### Função de download e armazenamento

```php
/**
 * Baixa o vídeo da URL e salva em uploads/videos/[hash].mp4
 * Valida que é realmente um vídeo (MIME real via finfo)
 * Limite de 50MB
 * Retorna o caminho local ou null se falhar
 */
function video_baixar_e_salvar(string $url): ?string
```

### Função de prompt automático por nicho

```php
/**
 * Gera um prompt otimizado para vídeo baseado no nicho da loja.
 * Usado como sugestão quando o lojista abre o gerador.
 */
function video_prompt_sugerido(string $nicho, string $nome_loja): string
```

---

## Endpoints — `api/video/`

### `POST api/video/gerar.php`

Recebe: `{loja_id, prompt}`

- Verifica que o usuário é dono da loja
- Verifica se a loja não tem vídeo sendo gerado no momento (status 'gerando')
- Cria registro em `videos_gerados` com status 'gerando'
- Dispara a geração em background (fire-and-forget, igual ao tick da rede IA)
- Retorna imediatamente: `{ok: true, video_id: N, status: 'gerando'}`

A geração real acontece em `api/video/processar.php` chamado internamente.

### `GET api/video/status.php?video_id=N`

Retorna: `{ok, video_id, status, arquivo, url, provider, erro}`

O front faz poll a cada 3s até status ser 'pronto' ou 'erro'.

### `GET api/video/loja.php?loja_id=N`

Retorna o vídeo mais recente com status 'pronto' daquela loja.
Usado para exibir no perfil da loja e no feed.

### `POST api/video/processar.php`

Só roda via CLI ou chamada interna — bloqueia HTTP externo com 404.
Executa a geração real: tenta cada provider em ordem, salva localmente, atualiza o registro.

---

## Front-end

### No painel da loja — `meu_echo.html`

Nova seção na aba "Echo da Loja" chamada **"Vídeo da loja"**:

```
┌─────────────────────────────────────────┐
│ 🎬 Vídeo de apresentação                │
│                                         │
│ Descreva sua loja em uma frase e        │
│ geramos um vídeo profissional pra você. │
│                                         │
│ [Hambúrguer artesanal sendo montado...] │
│  ↑ sugestão automática pelo nicho       │
│                                         │
│ [Gerar vídeo]                           │
│                                         │
│ ℹ️ Leva cerca de 30 a 60 segundos.      │
│    Você pode fechar e voltar depois.    │
└─────────────────────────────────────────┘
```

Quando vídeo está sendo gerado:
- Barra de progresso animada
- Poll a cada 3s em `api/video/status.php`
- Quando pronto: mostra o player de vídeo inline com botão de regenerar

### No perfil da loja — `loja_perfil.html`

- Se a loja tem vídeo: aparece no topo do perfil, acima dos posts, como banner em vídeo
- Player simples com `autoplay muted loop playsinline`
- Sem controles visíveis — é decorativo, não interativo
- Fallback: se não tem vídeo, mostra o banner estático que já existe

### No feed de comércio — `comercio.html`

- Posts com vídeo mostram `<video>` em vez de `<img>`
- Detecta pelo prefixo `video:` no campo imagem (padrão já planejado no seed)
- `autoplay muted loop playsinline` com `loading="lazy"`
- Badge "🎬 Vídeo" no canto do card

---

## Detalhes de integração por provider

### Google Veo

API via Google AI Studio / Vertex AI.
Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/veo-2.0-generate-001:predictLongRunning`

Fluxo assíncrono — retorna um operation ID, precisa fazer poll até completar.
Tempo médio: 30 a 90 segundos.
Formato de saída: MP4, resolução mínima 720p.

```php
// Chamada inicial
POST /v1beta/models/veo-2.0-generate-001:predictLongRunning
{
  "instances": [{"prompt": "..."}],
  "parameters": {"sampleCount": 1, "durationSeconds": 5, "aspectRatio": "16:9"}
}
// Retorna: {"name": "operations/OPERATION_ID"}

// Poll até done = true
GET /v1beta/operations/OPERATION_ID
```

### Kling AI

Usar a API REST (não o MCP para geração em background).
Endpoint base: `https://api.klingai.com/v1`

Requer HMAC-SHA256 para autenticação com access_key e secret_key.
Fluxo assíncrono — retorna task_id, faz poll.

```php
POST /v1/videos/text2video
{
  "model": "kling-v1",
  "prompt": "...",
  "duration": "5",
  "aspect_ratio": "16:9"
}
```

### MiniMax/Hailuo

Endpoint: `https://api.minimaxi.chat/v1/video_generation`

```php
POST /v1/video_generation
{
  "model": "video-01",
  "prompt": "...",
  "duration": 6
}
// Retorna task_id para poll em /v1/query/video_generation?task_id=
```

### Luma AI

Endpoint: `https://api.lumalabs.ai/dream-machine/v1/generations`

```php
POST /dream-machine/v1/generations
{
  "prompt": "...",
  "aspect_ratio": "16:9",
  "duration": "5s"
}
// Retorna id para poll em /dream-machine/v1/generations/{id}
```

### Pexels Vídeo (fallback)

Não gera — busca. Usa palavras-chave do nicho da loja.
Chave já em `PEXELS_API_KEY` no projeto.

```php
GET https://api.pexels.com/videos/search?query={nicho}&per_page=1&orientation=landscape
// Retorna URL direta do vídeo em SD (sem download necessário para fallback)
```

---

## Prompts otimizados por nicho

Cada nicho tem um prompt base que o sistema usa como sugestão e como base para geração automática:

```php
const VIDEO_PROMPTS_NICHO = [
    'Alimentação'  => '{nome}, comida fresca sendo preparada, câmera lenta, luz quente de cozinha profissional, sem texto',
    'Moda'         => '{nome}, roupas em destaque, modelo em movimento suave, iluminação de estúdio, paleta de cores harmoniosa',
    'Petshop'      => '{nome}, animal feliz brincando, fundo limpo, luz natural, movimento alegre',
    'Tecnologia'   => '{nome}, dispositivo tecnológico em close, luz azul dramática, superfície espelhada, câmera lenta',
    'Beleza'       => '{nome}, produto de beleza em destaque, pétalas ou glitter caindo, fundo neutro, cinematográfico',
    'Saúde'        => '{nome}, ambiente limpo e moderno, luz clara, transmite confiança e bem-estar',
    'Serviços'     => '{nome}, profissional trabalhando com cuidado, ambiente organizado, luz natural',
    'Outro'        => '{nome}, produto ou serviço em destaque, qualidade cinematográfica, sem texto',
];
```

---

## Segurança

- Prompt do lojista passa por moderação antes de ir para a API (mesmo `ai_moderate()` já existente)
- Vídeo baixado é validado por MIME real via `finfo` — aceita só `video/mp4`, `video/webm`
- Tamanho máximo: 50MB por vídeo
- Rate limiting: 1 geração por loja por hora (evita abuso de créditos das plataformas)
- Chaves de API ficam só em `video_config.php` fora do repositório
- `processar.php` bloqueia acesso HTTP externo

---

## Estrutura de pastas

```
uploads/
  videos/
    lojas/
      [loja_id]/
        [hash].mp4      -- vídeo da loja
    agentes/
      [agent_id]/
        [hash].mp4      -- vídeo do agente de IA (fase futura)

api/
  video/
    helpers.php
    gerar.php
    status.php
    loja.php
    processar.php
    video_config.php        -- fora do git
    video_config.example.php -- no git

docs/
  plans/
    plano-videos-ia.md
```

---

## O que fica de fora desta fase

- Vídeos para os agentes de IA do sistema (Malboro, Tia Bet, etc.) — próxima fase
- Geração de vídeo a partir de imagem (image-to-video) — próxima fase
- Múltiplos vídeos por loja — por enquanto um vídeo ativo por loja
- Edição ou corte do vídeo gerado
- Áudio/narração no vídeo

---

## Ordem de implementação

1. Schema e seed dos providers em `banco.sql`
2. `video_config.example.php` com estrutura das chaves
3. `api/video/helpers.php` com todas as funções
4. `api/video/processar.php` (CLI only)
5. `api/video/gerar.php` e `status.php` e `loja.php`
6. Front-end em `meu_echo.html` — seção de vídeo na aba da loja
7. Front-end em `loja_perfil.html` — banner em vídeo
8. Front-end em `comercio.html` — suporte a `<video>` nos cards
9. Testes com cada provider (precisa das chaves em `video_config.php`)
