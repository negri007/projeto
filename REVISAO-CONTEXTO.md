# Contexto do projeto Echo — para revisão externa

> Cole este arquivo inteiro numa conversa nova do Claude e peça a revisão que
> quiser. Ele é autocontido: descreve o projeto, o que foi construído, o estado
> atual e o que vale revisar. (Arquivo temporário, não faz parte do produto —
> pode apagar depois.)

---

## O que é o Echo

Rede social + comércio com agentes de IA autônomos. Projeto de TCC (faculdade).
**Stack:** PHP 8.2 + MySQL/MariaDB no back, JavaScript vanilla + Bootstrap no
front, rodando no Apache do XAMPP (Windows) em `http://127.0.0.1:8080`. Um
módulo de vídeo usa Node.js + Remotion.

Convenções do projeto (do CLAUDE.md):
- PDO com prepared statements em toda query.
- Erros sempre como `{"error":"..."}`, nunca vaza `$e->getMessage()` pro cliente.
- Identidade **sempre da sessão** (`require_login()` / `current_user_id()`);
  nenhum endpoint aceita `user_id`/`loja_id` vindo do cliente.
- Upload validado por MIME real (`finfo`/GD), com limite de tamanho.
- Contrato de API em `docs/API_CONTRACT.md` é a fonte da verdade.

---

## A grande feature construída: "Motor de anúncios"

Gera **vídeos de marketing** da loja renderizados **localmente** (Remotion +
Chrome headless), **sem custo de API** — o diferencial do produto. A ideia:
transformar foto + textos da loja em anúncio que "parece feito por IA, mas não
é". Nicho = **estilo visual** (não categoria); o sorteio de layout/trilha + os
dados da loja garantem que dois anúncios não saiam iguais.

### O que tem
- **13 modelos** (componentes Remotion data-driven): Flash (1 cena), Historia
  (3 cenas), Manchete (gira e trava), Vitrine (flip 3D), Ficha (números
  contando), Luxo (brilho dourado), Glitch (RGB split), Editorial (revista),
  AntesDepois, Depoimento (estrelas), Combo/Cardápio, Cupom, Countdown.
- **12 estilos/nichos** (presets: cor, fonte, filtro, trilha): comida, moda,
  joia, tech, beleza, fitness, **neutro/universal** (premium, pra qualquer
  produto que não cai num tema), saúde, casa, pet, serviços, infantil. Estilo ≠
  categoria — a loja escolhe o look.
- **Formatos** via prop (calculateMetadata): story 9:16, feed 4:5, quadrado
  1:1, paisagem 16:9. O mesmo modelo sai em qualquer formato.
- **Encaixe da foto**: "Preencher a tela" (cover, com crop de arrastar/zoom via
  `foco {x,y,zoom}`) ou "Foto inteira" (contain + fundo desfocado, nunca corta).
  Vale nos modelos full-bleed (Flash, Historia, Ficha, Countdown).
- **Trilha embutida** no render (`<Audio>` do Remotion) — não precisa de ffmpeg
  externo.
- **Upload de qualquer foto**: normaliza tudo pra JPEG (GD cobre
  JPEG/PNG/WebP/GIF/AVIF/BMP; ffmpeg cobre HEIC/TIFF), corrige rotação EXIF,
  achata transparência, reduz gigantes pra 1600px, limite 25MB.

### Como flui
1. Front (aba **Canvas → Loja**, `js/canvas-video.js`) monta o formulário a
   partir de `GET api/video/modelos.php` (catálogo), o lojista escolhe modelo +
   formato, edita campos (rótulos de leigo + exemplos), sobe foto, ajusta o
   encaixe/crop.
2. `POST api/video/marketing.php` (multipart) valida, salva a foto em
   `motor/public/uploads`, grava em `videos_gerados` (colunas `modelo`,
   `formato`, `params` JSON) e dispara o processamento em background.
3. `api/video/processar.php` (CLI, background): se a linha tem `modelo`,
   renderiza pelo motor (`video_motor_render()` em `api/video/helpers.php`
   chama `node motor/render.js <job.json>`); senão segue o caminho antigo de
   vídeo por IA/banco (Kling/Pexels/Coverr).
4. `motor/render.js` renderiza o modelo no formato pedido, com trilha, e
   devolve UMA linha JSON no stdout (`{"ok":true,"out":"..."}`) — o PHP lê isso.
5. Front acompanha em **Comércio → "Meus vídeos"** (`api/video/meus.php`, só os
   vídeos da loja da sessão): card "Gerando…" que vira player quando fica
   pronto; dali publica na loja (1 clique) ou baixa.
6. Publicar: `api/video/... / api/lojas/post_criar.php` aceita `video_id`,
   confere que o vídeo é da loja da sessão + está `pronto` + arquivo existe,
   evita publicar 2x, e cria o post referenciando o arquivo (`video:<caminho>`),
   sem re-upload.
7. No feed, o vídeo **toca sozinho** (autoplay mudo, loop, `IntersectionObserver`
   toca quando ~50% visível e pausa ao sair; só um por vez; botão de som).

### Regras de negócio / custo
- Modo de geração (`ai_generation_state.mode`): `acervo` (IA desligada, $0),
  `hibrido`, `api`. O motor de vídeo **não** passa por esse seletor — é local e
  grátis; roda mesmo com IA desligada.
- Planos pensados: Básico (grátis) / Pro / Premium (IA paga por crédito).
- **IA de texto já usa a API do Claude** (Anthropic Messages): `api/ai/` com
  `ai_config.php` (`api_key` sk-ant-, modelos Haiku/Sonnet), agentes, e
  contador `ai_api_uso`. Ligado/desligado pelo modo acima.

---

## Estado atual

- Branch: **`feature/videos-ia`** (a `main` ainda está sem o motor — merge é
  opcional, não foi feito).
- Tudo commitado e no GitHub (`negri007/projeto`). Últimos commits incluem:
  integração do motor, estilos universais, textos de leigo, encaixe/crop,
  upload de qualquer formato, feed proporcional, logo/capa da loja corrigidas,
  "Meus vídeos" + publicar direto + autoplay, e os hooks de guardrail em
  `.claude/`.
- Roda no Apache do XAMPP (8080). `SETUP.md` na raiz tem o passo a passo pra
  outra máquina (PHP/MySQL/Node/ffmpeg, banco, configs, `npm install` +
  `remotion browser ensure`).
- **Validado ponta a ponta**: gerar → aparece em Meus vídeos → publicar →
  toca sozinho no feed. O render leva ~40s–2min por vídeo.

### Pendências / em andamento
- **Otimização de performance (em andamento):** trocar `motor/render.js` do CLI
  (que re-empacota o projeto a cada render, ~15-20s perdidos) pro modo
  programático do Remotion com **bundle cacheado em disco** — mesmo vídeo, só
  mais rápido. Contrato do stdout e a aparência do vídeo NÃO podem mudar.
- **Login Google**: o `redirect_uri` em `api/auth/google_config.php` precisa
  bater com a porta que serve o app (agora 8080) e estar cadastrado no Google
  Console; senão dá `redirect_uri_mismatch`.
- **Merge na main**: opcional.

---

## O que vale revisar (pedidos sugeridos pro revisor)

1. **Segurança dos endpoints novos** (`api/video/marketing.php`,
   `api/video/meus.php`, `api/lojas/post_criar.php` com `video_id`): a
   identidade/posse vem só da sessão? Dá pra publicar/listar vídeo de outra
   loja? Validação de MIME/tamanho no upload está correta?
2. **A ponte PHP → Node** (`video_motor_render()` e `video_php_cli()` em
   `api/video/helpers.php`): sob Apache (mod_php), `PHP_BINARY` é o httpd; o
   código acha o `php.exe` CLI certo? O `shell_exec` que chama o `node` está
   seguro (sem input do cliente caindo cru no shell)?
3. **A otimização de bundle cacheado** (quando estiver pronta): o vídeo sai
   idêntico? O cache invalida quando um modelo em `motor/src` muda?
4. **Autoplay do feed** (`js/loja-feed.js`): performance com muitos vídeos,
   pausa fora da tela, política de autoplay mudo.
5. **Qualidade/UX dos modelos** e dos textos de leigo.

## Arquivos-chave
- Motor: `motor/src/*.jsx` (modelos), `motor/src/presets.js` (estilos),
  `motor/src/Foto.jsx` (encaixe/crop), `motor/src/Root.jsx` (registro +
  formato + trilha), `motor/render.js` (ponte).
- Back vídeo: `api/video/helpers.php`, `marketing.php`, `modelos.php`,
  `meus.php`, `processar.php`, `motor_catalogo.php`.
- Front: `js/canvas-video.js` (painel do motor), `js/comercio.js` +
  `js/loja-feed.js` (feed/Meus vídeos), `css/echo.css`.
- Docs: `docs/API_CONTRACT.md`, `docs/plans/motor-anuncios.md`, `SETUP.md`.
