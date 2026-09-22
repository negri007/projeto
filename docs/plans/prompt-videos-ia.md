# Prompt de execução — Vídeos de IA para o Echo

Leia o `ajustes.md` completo e depois leia `docs/plans/plano-videos-ia.md` inteiro antes de escrever uma linha de código.

Crie uma branch nova chamada `feature/videos-ia` e trabalhe nela do início ao fim.

Execute as etapas em ordem. Commita ao final de cada etapa com mensagem descritiva.

Se encontrar conflito com o que já existe no projeto, para e descreve antes de continuar.

---

## ETAPA 1 — Schema e configuração

Aplica as tabelas `video_providers` e `videos_gerados` em `banco.sql` seguindo o padrão idempotente do projeto. Aplica no banco local e confirma que as duas tabelas foram criadas com o seed dos 5 providers.

Cria `api/video/video_config.example.php` com todos os campos de chave descritos no plano, cada um com comentário indicando onde obter a chave. Adiciona `api/video/video_config.php` no `.gitignore`.

Cria a pasta `uploads/videos/lojas/` e adiciona um `.gitkeep` pra versionar a estrutura sem versionar os arquivos.

Commita: `feat(videos): schema, config e estrutura de pastas`

---

## ETAPA 2 — Back-end: helpers e processamento

Cria `api/video/helpers.php` com todas as funções descritas no plano:

- `video_gerar()` — função principal com fallback em ordem
- `video_veo()` — integração com Google Veo (fluxo assíncrono com poll)
- `video_kling()` — integração com Kling AI (HMAC-SHA256 + poll)
- `video_minimax()` — integração com MiniMax (poll por task_id)
- `video_luma()` — integração com Luma AI (poll por generation id)
- `video_pexels()` — busca por palavra-chave, retorna URL direta
- `video_baixar_e_salvar()` — download com validação MIME real
- `video_prompt_sugerido()` — prompt automático por nicho

Para cada provider: se a chave não estiver configurada em `video_config.php`, pula para o próximo silenciosamente. Se a chamada falhar ou retornar erro, registra em `video_providers.ultimo_erro` e passa para o próximo.

O poll de cada provider tem timeout de 120 segundos. Se ultrapassar, considera falha e tenta o próximo.

Cria `api/video/processar.php` — só roda via CLI (bloqueia HTTP com 404). Recebe o `video_id` como argumento de linha de comando, chama `video_gerar()` e atualiza o registro em `videos_gerados`.

Verifica com `php -l` todos os arquivos criados.

Commita: `feat(videos): helpers e processamento por provider`

---

## ETAPA 3 — Endpoints HTTP

Cria os três endpoints seguindo as convenções do projeto (require_login, resposta JSON, sem getMessage() para o cliente):

**`api/video/gerar.php`** — POST, recebe `{loja_id, prompt}`
- Verifica que o usuário é dono da loja
- Verifica rate limit: 1 geração por loja por hora (tabela `videos_gerados`, conta registros da última hora com status != 'erro')
- Modera o prompt com `ai_moderate()` — rejeita se reprovar
- Cria registro com status 'gerando'
- Dispara `processar.php` em background via `proc_open` (fire-and-forget, igual ao padrão do tick.php)
- Retorna `{ok: true, video_id: N}`

**`api/video/status.php`** — GET, recebe `?video_id=N`
- Verifica que o vídeo pertence a uma loja do usuário logado
- Retorna `{ok, video_id, status, arquivo, url_plataforma, provider, erro}`

**`api/video/loja.php`** — GET, recebe `?loja_id=N`
- Público (não precisa de sessão)
- Retorna o vídeo mais recente com status 'pronto' da loja
- Retorna `{ok, tem_video: bool, video: {arquivo, url_plataforma, provider} | null}`

Documenta os três endpoints em `docs/API_CONTRACT.md` na seção de vídeo (cria a seção se não existir).

Commita: `feat(videos): endpoints gerar, status e loja`

---

## ETAPA 4 — Front-end: painel da loja

Em `meu_echo.html`, na aba "Echo da Loja", adiciona a seção "Vídeo de apresentação" conforme o layout do plano:

- Campo de texto para o prompt com placeholder de exemplo do nicho
- Ao abrir a aba, carrega a sugestão automática via `api/video/loja.php` — se já tem vídeo mostra ele, se não tem preenche o campo com a sugestão do nicho
- Botão "Gerar vídeo" chama `api/video/gerar.php`
- Durante a geração: substitui o botão por barra de progresso animada + texto "Gerando seu vídeo... pode levar até 60 segundos"
- Poll a cada 3s em `api/video/status.php` — para quando status for 'pronto' ou 'erro'
- Quando pronto: mostra player `<video autoplay muted loop playsinline>` com o vídeo gerado + botão "Regenerar"
- Quando erro: mostra mensagem amigável + botão para tentar novamente

O poll para automaticamente quando a aba não está visível (`document.hidden`).

Commita: `feat(videos): painel da loja com gerador`

---

## ETAPA 5 — Front-end: perfil da loja e feed

**`loja_perfil.html`**

Modifica o carregamento do perfil para verificar se a loja tem vídeo via `api/video/loja.php`. Se tiver:
- Substitui o banner estático por um `<video autoplay muted loop playsinline>` no topo do perfil
- Fallback: se o vídeo falhar ao carregar, mostra o banner estático normalmente

Se não tiver vídeo: comportamento atual mantido.

**`comercio.html` e `js/loja-feed.js`**

Modifica o card de post de loja para suportar vídeo:
- Detecta se o campo `imagem` começa com `video:` (padrão já definido no seed)
- Se sim: renderiza `<video autoplay muted loop playsinline loading="lazy">` em vez de `<img>`
- O vídeo ocupa o mesmo espaço da imagem (aspect-ratio 16:9, object-fit: cover)
- Badge "🎬" no canto superior direito do card quando for vídeo

Bumpa o `?v=` do CSS e JS alterados.

Commita: `feat(videos): suporte a vídeo no perfil da loja e feed`

---

## ETAPA 6 — Testes e relatório

Testa o fluxo completo sem chaves de API configuradas (só com Pexels como fallback):

1. Loga como dono de uma loja do seed
2. Abre `meu_echo.html` → aba Echo da Loja → seção Vídeo
3. Verifica que a sugestão de prompt aparece baseada no nicho da loja
4. Clica em "Gerar vídeo" — deve cair no fallback do Pexels
5. Verifica que o poll funciona e o vídeo aparece quando pronto
6. Abre `loja_perfil.html` e verifica que o vídeo aparece como banner
7. Verifica que o card no feed de comércio mostra o badge 🎬

Testa também:
- Rate limit: segunda geração na mesma hora deve retornar erro claro
- Prompt vazio: deve rejeitar com mensagem clara
- Prompt com conteúdo bloqueado pela moderação: deve rejeitar

Gera um relatório no terminal com o resultado de cada teste.

Após os testes, atualiza o `ajustes.md` com uma seção nova descrevendo o que foi implementado.

Commita: `feat(videos): testes e documentação`

---

## Notas importantes

- `video_config.php` nunca entra no repositório — está no `.gitignore`
- As chaves reais precisam ser configuradas manualmente após clonar
- O Pexels como fallback garante que o sistema nunca retorna vídeo vazio
- O fluxo assíncrono (fire-and-forget + poll) é obrigatório porque a geração leva 30-90 segundos
- Nunca bloquear a requisição HTTP esperando a geração terminar
