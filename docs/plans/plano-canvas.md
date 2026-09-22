# Canvas — transformar conteúdo em peça de marketing

## Objetivo

Uma tela onde a pessoa pega conteúdo que já existe (um post, uma imagem
enviada, um produto do catálogo) e transforma numa **peça de marketing** —
uma imagem estática pronta para postar, ou um vídeo curto. É "mais uma
opção" de criar, ao lado de escrever um post à mão: em vez de partir do
zero, parte do que já está na conta e monta a peça em cima.

## Decisões (definidas com o dono do projeto)

1. **Público: os dois, começando pelo lojista.** A estrutura serve
   qualquer usuário, mas a primeira versão foca no lojista — é onde
   "marketing" tem dono claro (a loja, o catálogo, o preço). A abertura
   para o usuário comum vem depois, reusando a mesma tela.

2. **Imagem: template agora, IA depois.** A v1 compõe a imagem **no
   navegador**, com `<canvas>`: sobrepõe texto, logo, preço e moldura numa
   foto (do post, do produto, ou enviada). Custo zero, sem API. Fica um
   gancho `canvas_ia_imagem()` preparado para geração por IA quando o
   projeto tiver uma chave de imagem — hoje não tem (só Anthropic para
   texto e Pexels para foto pronta).

3. **Saídas: imagem, vídeo e post pronto.** A peça pode ser **baixada**,
   **gerada como vídeo** (via a engine de vídeo que já existe) e
   **publicada direto no feed** (da loja, para lojista; do usuário, depois).

## Limite técnico que atravessa o plano

- **Não há geração de imagem por IA hoje.** A imagem da v1 é composição por
  template, não "crie uma arte nova". O gancho de IA fica escrito e
  desligado, igual ao resto.
- **A geração por IA está em modo `acervo`** (`ai_generation_state.mode`).
  O Canvas respeita o seletor: o vídeo só dispara quando a geração está
  ligada; com o modo em acervo, o botão de vídeo explica que está desligado,
  como a Rede IA e as sugestões do agente já fazem. A composição de imagem
  por template **não** depende disso — é client-side e sempre funciona.
- **Vídeo hoje é por loja.** `api/video/gerar.php` pega a loja da sessão.
  Vídeo de marketing na v1 é do lojista. Vídeo para usuário comum fica para
  quando o módulo de vídeo aceitar origem sem loja.

## O que a tela reusa (nada disto é novo)

- **Fontes de conteúdo**: `api/lojas/produtos.php` (catálogo), `api/lojas/feed.php`
  e `api/posts/list.php` (posts existentes) — para escolher o que transformar.
- **Vídeo**: `api/video/gerar.php` (dispara), `api/video/status.php` (poll).
  Chaves já configuradas (Kling/Veo/MiniMax/Luma + Pexels de piso).
- **Publicar**: `api/lojas/post_criar.php` (lojista, multipart com `imagem`)
  e `api/posts/create.php` (usuário, multipart com `image`). A peça sai do
  `<canvas>` como PNG (`toBlob`), vira arquivo no `FormData` e passa pela
  mesma validação de MIME real (`posts_store_image`) de qualquer upload.

## Partes

### Parte 1 — Base e composição de imagem (client-side)

Tela `canvas.html` + `js/canvas.js` + estilos em `css/echo.css`. Entra no
menu (ícone próprio) e, no comércio, como opção "criar peça".

Fluxo:

1. **Escolher a fonte**: um post da loja, um produto do catálogo, ou uma
   imagem enviada. A foto base vem daí (produto tem `imagem`; post tem
   `imagem`; upload é do dispositivo).
2. **Escolher um template**: alguns formatos fixos — "Produto" (foto +
   nome + preço + logo), "Promoção" (faixa + chamada), "Novidade",
   "Info". São os mesmos tipos que o feed de comércio já usa, para a peça
   nascer coerente com o badge do post.
3. **Editar o texto**: chamada, preço, e um rótulo curto. O logo da loja
   entra automático (a loja já tem `logo`).
4. **Exportar**: `canvas.toBlob()` → PNG. Botão "Baixar" resolve a saída de
   imagem sozinho, sem servidor.

A composição é determinística e roda offline: `<canvas>` desenha a foto,
aplica a moldura/faixa do template, escreve o texto com quebra por palavra
(igual ao `cortar()` que já existe no front) e carimba o logo. Nenhuma
chamada de API, nenhum custo.

**Gancho de IA (escrito, desligado):** `canvas_ia_imagem(prompt, base)` —
retorna `null` hoje (sem chave), e a tela cai na composição por template.
Quando houver chave de imagem, é o único ponto a preencher.

### Parte 2 — Vídeo de marketing

Botão "Gerar vídeo" na tela do Canvas (visível para lojista). Monta um
prompt a partir da peça (nome do produto/loja + chamada + nicho) e chama
`api/video/gerar.php`. O acompanhamento reusa o que o módulo de vídeo já
tem: `status.php` por poll, com o "gerando…" honesto (some quando volta).

Regras que já valem no módulo de vídeo e o Canvas herda: respeita o modo
`acervo` (desligado → avisa, não gasta), passa pelo freio por pessoa, e cai
no Pexels quando nenhum provider de IA responde — vídeo sempre sai, nem que
seja um clipe de banco de imagem com a chamada por cima.

### Parte 3 — Publicar no feed

Depois de compor a imagem (Parte 1) ou gerar o vídeo (Parte 2), o botão
"Publicar":

- **Lojista** → `api/lojas/post_criar.php` com `conteudo` (a chamada),
  `tipo` (o template escolhido, que vira o badge), `preco` (se houver) e o
  arquivo em `imagem`. Curtida e comentário desse post já notificam o dono
  pelo `loja_like`/`loja_comment` que acabamos de ligar.
- **Usuário comum** (quando a abertura chegar) → `api/posts/create.php` com
  `content` e `image`.

A imagem publicada é o PNG do `<canvas>`; o vídeo publicado é a URL que o
`video_gerar()` devolveu. Nenhum caminho novo de publicação — o Canvas é só
mais uma origem para os mesmos endpoints, com a mesma moderação e o mesmo
gancho de notificação.

## Persistência

A v1 é **sem rascunho salvo**: a peça é composta e publicada/baixada na
hora. Guardar rascunho de peça (uma tabela `canvas_pecas`) fica para depois
— entra só se o uso pedir, para não carregar o banco com estado que talvez
ninguém releia.

## O que fica de fora nesta versão

- Geração de imagem por IA (sem chave; gancho pronto).
- Vídeo para usuário comum (o módulo de vídeo é por loja hoje).
- Rascunho salvo de peça.
- Edição livre estilo Canva (arrastar N camadas): a v1 tem templates
  fixos com campos, não um editor aberto. Editor livre é um passo grande e
  vem só se os templates não bastarem.
- Agendamento de publicação.

## Contrato (docs/API_CONTRACT.md)

A v1 **não cria endpoint novo** — reusa `lojas/produtos.php`, `lojas/feed.php`,
`posts/list.php`, `video/gerar.php`, `video/status.php`, `lojas/post_criar.php`
e `posts/create.php`. A única entrada nova no contrato é registrar a página
`canvas.html` e o fluxo, para o mapa de telas ficar completo. Se a Parte 1
precisar de um endpoint só-leitura para listar "o que dá para transformar"
(um resumo de posts+produtos numa chamada), ele entra no contrato antes do
código, como manda a convenção.
