# Auditoria de performance e responsividade mobile — Echo

**Data:** 19/09/2026 · **Branch:** `feature/ia-agentes` (commit `f2522e9`)
**Escopo:** só análise. Nenhum arquivo do projeto foi alterado.

---

## Como foi feito

**Etapa 1, análise estática:** leitura de `js/echo-bit.js`, `js/echo-ui.js`,
`js/echo-feed.js`, `css/echo.css`, `rede_ia.html` e `inicio.html`, com foco nos
dez critérios pedidos.

**Etapa 2, Puppeteer:**
- Chrome 140 headless, via `puppeteer-core`, instalado numa pasta temporária
  para não criar `package.json` nem `node_modules` no projeto.
- Três viewports: 375×812, 390×844 e 360×800. Todas com `isMobile`,
  `hasTouch`, DPR 2 e user agent de Android.
- CPU desacelerada 4× com `Emulation.setCPUThrottlingRate`. A rede não foi
  limitada: servidor local.
- Login feito pela tela, como `alice@echo.local` / `senha123`.
- Em cada página: screenshot da página inteira, console, overflow horizontal,
  elementos cobertos, texto cortado, tamanho de fonte, alvos de toque,
  animações rodando (`document.getAnimations()`), long tasks, CLS, FPS em
  repouso (3 s) e a abertura do menu lateral.

### Ressalvas que mudam a leitura dos números

1. **A conta `alice@echo.local` não existia neste banco.** O banco tem só os 7
   usuários reais, sem as contas de teste citadas no `ajustes.md`. Criei a
   conta pelo próprio `api/auth/register.php` (id 8, nome "Alice (teste)").
   Foi a única alteração de dados. Para remover:
   `DELETE FROM users WHERE email = 'alice@echo.local';`
2. **A conta é nova:** não tem amigos, posts nem círculos. Por isso o Início
   ficou vazio, o Explorar mostrou 1 post e o Perfil ficou sem posts. Tudo o
   que cresce com o número de posts ficou **subestimado**: animações por autor,
   imagens, long tasks do feed. A Rede IA foi a única página com volume real
   (25 falas).
3. **O DCL e o load brutos estão inflados pelo `php -S`.** O servidor atende uma
   requisição por vez. Cada página logada abre o SSE do sino
   (`notifications/stream.php`), que segura esse único worker por 6 s. A página
   seguinte fica 3–5 s na fila antes de receber o primeiro byte: é o TTFB alto
   da tabela. O custo real no navegador é **DCL − TTFB**, na coluna própria.
   O mesmo efeito já está descrito no `ajustes.md` (17/09). No Apache isso não
   acontece.

---

## Resumo por página (Etapa 2)

Todos os valores com CPU 4×. "Cliente" = tempo gasto no navegador, sem a fila
do servidor.

| Página | Viewport | TTFB | DCL | Load | **DCL cliente** | Maior long task | TBT aprox. | CLS | FPS repouso | Erros console |
|---|---|---|---|---|---|---|---|---|---|---|
| index | 375×812 | 18 | 428 | 432 | **410** | 63 | 13 | 0 | 60 | 2 |
| index | 390×844 | 4908 | 5390 | 5394 | **482** | 66 | 16 | 0 | 60 | 1 |
| index | 360×800 | 5144 | 5550 | 5553 | **406** | 71 | 39 | 0 | 60 | 1 |
| inicio | 375×812 | 2 | 441 | 447 | **439** | 72 | 22 | 0 | 60 | 0 |
| inicio | 390×844 | 3 | 457 | 465 | **454** | 84 | 45 | 0 | 60 | 0 |
| inicio | 360×800 | 3 | 425 | 436 | **422** | 74 | 35 | 0 | 60 | 0 |
| rede_ia | 375×812 | 4242 | 4683 | 4687 | **441** | **465** | **457** | **0,122** | 58 | 10 |
| rede_ia | 390×844 | 5170 | 5676 | 5685 | **506** | **409** | **410** | **0,109** | 59 | 10 |
| rede_ia | 360×800 | 5278 | 5762 | 5767 | **484** | **460** | **410** | **0,113** | 59 | 9 |
| explorar | 375×812 | 3536 | 3927 | 3931 | **391** | 160 | 178 | 0 | 60 | 0 |
| explorar | 390×844 | 4488 | 4892 | 4904 | **404** | 188 | 199 | 0,04 | 60 | 0 |
| explorar | 360×800 | 2689 | 3077 | 3083 | **388** | 223 | 258 | 0 | 60 | 0 |
| perfil | 375×812 | 5073 | 5527 | 5549 | **454** | 148 | 98 | 0 | 60 | 0 |
| perfil | 390×844 | 5208 | 5720 | 5741 | **512** | 166 | 125 | 0 | **35** ¹ | 0 |
| perfil | 360×800 | 4885 | 5442 | 5479 | **557** | 267 | 237 | 0 | 60 | 0 |

¹ Um quadro de 1067 ms caiu dentro da janela de medição, provavelmente uma
resposta de API renderizando atrasada. Não se repetiu nas outras viewports.

**O que está bom, e ficou bom nas 15 execuções:**
- Nenhuma página tem overflow horizontal do documento: `scrollWidth` igual à
  largura da tela.
- Nenhum erro de JavaScript (`pageerror`).
- FPS em repouso perto de 60 mesmo com CPU 4×.
- A sidebar desktop some corretamente abaixo de 768 px e o hambúrguer aparece.
- O menu lateral entra inteiro na tela.
- Nenhum campo de formulário tem fonte menor que 16 px, então o iOS não dá
  zoom automático ao focar.

### index.html (login)

| Sev. | Problema |
|---|---|
| MÉDIO | O formulário só aparece depois do splash GSAP: **3,5 s após o início da navegação** na execução sem fila de servidor (375×812). Num aparelho lento, a pessoa encara uma animação antes de poder digitar. |
| MÉDIO | `bootstrap.bundle.min.js` e `gsap.min.js` (CDN) ficam no topo do `<body>`, sem `defer`, antes de todo o markup. Bloqueiam o parser. |
| BAIXO | Console: 401 de `me.php`, esperado sem sessão mas polui o console, e 404 de `favicon.ico`. |
| BAIXO | Alvos de toque pequenos: "Criar conta" e "Esqueci minha senha" com 22 px de altura; olho da senha com 30×30. |

### inicio.html (feed)

| Sev. | Problema |
|---|---|
| **ALTO** | **Barra inferior corta o último ícone (Mensagens) nas três viewports.** Os 7 links somam 389 px (menor link: 48 px) contra 360–390 px de tela. Visível em `inicio_375x812.png`. Afeta todas as páginas logadas. |
| **ALTO** | **Menu lateral móvel (hambúrguer):** o bloco do perfil, com nome e botão **Sair**, fica atrás da barra inferior. A barra tem `z-index: 1050` e o offcanvas do Bootstrap, 1045. Ver `inicio_360x800_menu.png`. No mesmo menu: o texto "ECHO" e o nome do usuário somem, porque a regra de `max-width: 992px` esconde `.logo-text` e `.prof-info` em qualquer lugar, não só na sidebar. Os ícones também ficam colados no texto e todos os links saem azuis, sem estado ativo, porque o estilo é `.sidebar .nav-link` e o offcanvas não tem `.sidebar`. |
| MÉDIO | Faz 5 requisições para a coluna direita, que fica `display:none` abaixo de 992 px: `hashtags/trending`, `circles/list`, `profile/get`, `ai/feed` (com repetição a cada 45 s) e `friends/suggestions`. No celular, nada disso aparece. |
| MÉDIO | `pingRedeIA()` (`ai/tick.php`, até 3,4 s no servidor) dispara em toda abertura, também no celular. |
| BAIXO | Alvos pequenos: hambúrguer 30×32, sino 38×36, botões de imagem e "@" 32×40, "Postar" 94×34. |
| BAIXO | 16% dos caracteres visíveis abaixo de 14 px: selo "Amigos" 12 px, alternador 13,1 px. |

### rede_ia.html (a página mais pesada)

| Sev. | Problema |
|---|---|
| **ALTO** | **Funcionalidades inteiras somem no celular.** Toda a coluna direita fica `display:none` abaixo de 992 px: "Seu agente" (criar agente e créditos), "Os agentes" (painel e córtex), "IAlândia", **"Falar com a IAlândia"** e "Como funciona". Não há caminho alternativo no mobile. |
| **ALTO** | **Continua fazendo polling do painel invisível.** `status.php` roda a cada 5 s, mais **rajadas de 8 chamadas a cada 600 ms** a cada 15 s: são ~44 requisições/min, o próprio comentário do código faz essa conta. `tick.php` roda a cada 15 s e o painel com córtex é montado mesmo oculto. Na execução medida, foram 10 chamadas a `status.php` nos primeiros 5 s, todas enfileiradas atrás do SSE, de 1,6 a 5,3 s cada. |
| **ALTO** | **Long task de 410–465 ms** com CPU 4× em todas as viewports, TBT ≈ 410–457 ms. Não foi perfilado função a função. Candidatos: montagem da conversa (25 falas), banner com 17 chips e o painel/córtex renderizado mesmo oculto. |
| MÉDIO | **CLS 0,109–0,122**, acima do limite "bom" de 0,1. A página inteira desloca durante a carga. |
| MÉDIO | O banner "Sete agentes conversando sozinhos" ocupa a **primeira tela inteira** no celular: texto longo e 17 chips. A conversa só começa abaixo da dobra (`rede_ia_375x812_tela_topo.png`). O título fala em sete agentes, mas há 17 chips. |
| MÉDIO | **Legibilidade:** 36,5% dos caracteres visíveis abaixo de 14 px, mínimo de **9,9 px** (selo "IA"). Assunto 10,9 px, `@handle` 12,8 px, tempo 12,5 px, citação 12–12,5 px. |
| MÉDIO | **Console, 9–10 erros por carga:** `api/ialandia/provocacoes.php` → **500**. A tabela `ai_provocacoes` não existe no banco local: a migração de `banco.sql:1373` não foi aplicada. Os 8 avatares `*_gen2.svg` e o `echo_sistema.svg` → **404**: o banco aponta para arquivos que não existem em `assets/ai/avatares/`, e os chips aparecem sem foto. |
| MÉDIO | `ia-varredura` (brilho do banner) anima `left` em loop infinito. É visível no mobile e causa layout a cada quadro. |
| BAIXO | 143 alvos de toque menores que 40 px: chips com 34 px de altura, nomes e handles como links de texto. |

### explorar.html

| Sev. | Problema |
|---|---|
| ALTO | Barra inferior cortada: mesmo problema do Início. |
| MÉDIO | Chama `friends/suggestions` e `ai/feed`, que alimentam cartões da coluna direita oculta. |
| BAIXO | 38,6% dos caracteres abaixo de 14 px: handle, tempo e contadores do post em 13,6 px; subtítulo "por curtidas…" em 13,3 px. Com um único post, a amostra é pequena. |
| BAIXO | Placeholder da busca truncado ("Pessoas, publicações,"). O cabeçalho "Em alta esta semana" quebra em duas colunas estreitas. |
| BAIXO | Long task de 160–223 ms com 1 post. Deve crescer com o feed cheio. |

### perfil.html

| Sev. | Problema |
|---|---|
| ALTO | Barra inferior cortada: mesmo problema do Início. |
| BAIXO | Cabeçalho apertado em 360 px: título, sino, chave e "Editar". Cabe, mas no limite. O botão da chave mede 44×30 e o "Editar", 86×30. |
| BAIXO | Os números do perfil quebram linha em 360 px ("0 círculos" desce). Aceitável. |

---

## Correções priorizadas (do mais crítico para o menos crítico)

| # | Sev. | Problema | Onde | Sugestão |
|---|---|---|---|---|
| 1 | **ALTO** | Barra inferior com 7 itens não cabe: "Mensagens" cortado | `css/echo.css:312-340`, `js/echo-ui.js:509` | Itens com `flex: 1 1 0` e `padding: 8px 0`, sem padding lateral fixo. Ou reduzir para 5 itens e deixar o resto no hambúrguer. |
| 2 | **ALTO** | Menu móvel: "Sair" e perfil atrás da barra inferior; logo e nome escondidos; links sem estilo | `css/echo.css:1132-1160`, `:312` | Limitar as regras de 992 px a `.sidebar .logo-text`/`.sidebar .prof-info`. Pôr a barra inferior abaixo de 1045 no `z-index`, ou escondê-la com o offcanvas aberto. Aplicar o estilo de `.sidebar .nav-link` também em `.offcanvas .nav-link`. |
| 3 | **ALTO** | Rede IA no celular perde "Falar com a IAlândia", "Seu agente", painel e IAlândia | `rede_ia.html:108-218` | Levar esses cartões para a coluna principal abaixo de 992 px: seção recolhível, aba ou offcanvas à direita. |
| 4 | **ALTO** | Polling de `status.php` (5 s + rajadas de 600 ms) e montagem do painel mesmo com o painel oculto | `rede_ia.html:1653-1910` | Antes de buscar, conferir se o painel está visível (`painel.checkVisibility()` ou `matchMedia('(min-width: 992px)')`). Com o painel oculto, não iniciar `pollerStatus` nem `pulsarStatus`. Com o item 3 feito, usar `IntersectionObserver` no painel. |
| 5 | **ALTO** | `echo-pulso-branco`: animação **infinita de `color`** em todo `.echo-author-link`, `.echo-trend-body strong`, títulos e itens de menu. Uma animação por post, na thread principal, para sempre. | `css/echo.css:2145-2160` | Tirar dos elementos repetidos (autor, tendência) e manter só no título do cabeçalho. Se for manter o efeito, animar a `opacity` de um pseudo-elemento azul sobreposto: roda no compositor. |
| 6 | **ALTO** | Long task de ~450 ms e CLS de 0,11 na Rede IA | `rede_ia.html` (carga inicial) | Perfilar no DevTools (Performance, CPU 4×). Não montar o painel e o córtex quando oculto. Reservar altura para o banner e a conversa (skeleton com a mesma altura). |
| 7 | MÉDIO | Animações de layout: `ia-varredura` (`left`), `ia-espinha-pulso` (`top`), `ia-elo-desce`/`ia-elo-sobe` (`top`) | `css/echo.css:2265, 3907, 4027, 4034` | Trocar por `transform: translateX()`/`translateY()`. O elemento fica parado e só a transformação anda. |
| 8 | MÉDIO | Chamadas para cartões invisíveis no Início e no Explorar (5 + 2) | `inicio.html:213-217`, `explorar.html` | Chamar `renderTrending`/`renderMyCircles`/`renderMeuResumo`/`renderRedeAgora`/`renderSuggestions` só se `matchMedia('(min-width: 992px)')` casar, e ouvir mudanças do media query. |
| 9 | MÉDIO | `backdrop-filter: blur(12px)` no cabeçalho fixo e na barra inferior. Refaz o blur a cada quadro de scroll, caro em Android intermediário. | `css/echo.css:321, 361` | Abaixo de 768 px: `backdrop-filter: none` com fundo quase opaco (`rgba(0,0,0,.95)`). Visualmente quase igual. |
| 10 | MÉDIO | Legibilidade: textos de 9,9–13,6 px na Rede IA e no feed | `css/echo.css` (`.ia-selo`, `.ia-assunto`, `.ia-tempo`, `.ia-handle`, `.post-handle`, `.post-time`) | Piso de 12 px para selos e metadados, 14 px para conteúdo e nomes. O selo "IA" pode ficar em 11–12 px em caixa alta com `letter-spacing`. |
| 11 | MÉDIO | 500 em `provocacoes.php` e 404 nos avatares `_gen2` / `echo_sistema` | banco local; `assets/ai/avatares/` | Aplicar a migração de `ai_provocacoes` (`banco.sql:1373`). Gerar ou corrigir os SVGs referenciados, ou fazer o front cair na letra inicial quando a imagem falhar (`onerror`). |
| 12 | MÉDIO | Splash do login segura o formulário por ~3 s; scripts de CDN bloqueiam o parser | `index.html:21-24` | Mostrar o formulário imediatamente e o splash por cima, ou pular o splash em `(max-width: 768px)`/`prefers-reduced-motion`. Pôr `defer` nos `<script>`. |
| 13 | MÉDIO | Bit liga em **celular deitado**: a regra é só `innerWidth >= 768`, e um iPhone na horizontal tem 844 px. Com ele, vêm o `rAF` a 60 fps permanente e a varredura de até 1400 elementos. | `js/echo-bit.js:54, 65` | Exigir também `matchMedia('(hover: hover) and (pointer: fine)')`. |
| 14 | MÉDIO | Borda do painel via `@property`: o fallback existe e está correto (gradiente parado). O comentário, porém, diz que roda no compositor, e **não roda**: animar custom property num `conic-gradient` repinta o card a cada quadro, para sempre (9 s ou 3,4 s por volta). Só afeta telas ≥ 992 px. | `css/echo.css:3794-3866` | Girar um pseudo-elemento com o gradiente fixo via `transform: rotate()`, recortado pela mesma máscara. Isso sim vai para o compositor. Ou pausar quando a aba ou o card não estiverem visíveis. |
| 15 | BAIXO | Animações infinitas invisíveis: logo do offcanvas (`echo-onda` ×2, `echo-pulsa-icone`) roda com o menu fechado, porque o Bootstrap usa `visibility:hidden`. `getAnimations()` confirmou em todas as páginas logadas. | `css/echo.css:1998-2128` | `.offcanvas:not(.show) .logo-mark, … { animation-play-state: paused; }` |
| 16 | BAIXO | Alvos de toque < 44 px: hambúrguer, sino, botões do post, chips da Rede IA e links do login | vários | `min-height: 44px` / `min-width: 44px` nos botões de ícone; `padding` maior nos links de texto do login. |
| 17 | BAIXO | `<img class="post-image">` sem `loading="lazy"`, `decoding="async"` nem dimensões | `js/echo-feed.js:182` | Adicionar `loading="lazy" decoding="async"` e `aspect-ratio`/`width`/`height` para não deslocar o layout. |
| 18 | BAIXO | Breakpoints desencontrados: o CSS usa `max-width: 768/992px` e o Bootstrap, `min-width: 768/992px`. Em exatamente 768 e 992 px, as duas regras valem juntas. | `css/echo.css:1132, 1164` | Usar `max-width: 767.98px` / `991.98px`, como o próprio Bootstrap. |
| 19 | BAIXO | Toasts em `bottom: 24px` ficam por cima da barra inferior no celular | `css/echo.css:1197` | Abaixo de 768 px: `bottom: calc(60px + 16px + env(safe-area-inset-bottom))`. |
| 20 | BAIXO | Barra inferior sem `env(safe-area-inset-bottom)`: no iPhone com home indicator, os ícones encostam na barra do sistema | `css/echo.css:312` | `padding-bottom: env(safe-area-inset-bottom)` e `height: calc(60px + env(...))`. Exige `viewport-fit=cover` na meta viewport. |
| 21 | BAIXO | `favicon.ico` 404 em todas as páginas | raiz | Adicionar um favicon ou `<link rel="icon">`. |

---

## Etapa 1 — Análise estática por arquivo

### `js/echo-bit.js` (mascote)

Contexto que reduz o risco no celular: **ele não entra em cena abaixo de
768 px** nem com `prefers-reduced-motion` (`podeAparecer()`, linha 65). No
retrato, nenhum laço roda. O SVG é criado mesmo assim e fica `display:none`.

| Risco | Achado | Linha | Sugestão |
|---|---|---|---|
| MÉDIO | O critério de "tela pequena" é só largura. Um celular deitado (844 px) ou um tablet (768–1024 px) liga o laço. | 54, 65 | Somar `(hover: hover) and (pointer: fine)`. |
| MÉDIO | O `requestAnimationFrame` roda a 60 fps **sempre**, mesmo com o bicho pousado e parado por segundos. Não há throttle nem modo ocioso. O `document.hidden` só evita o salto de `dt`, porque o próprio navegador já congela o rAF em aba oculta. | 1515-1523 | No repouso sem gesto, cair para ~20–30 fps (pular quadros com acumulador) ou parar o laço até o próximo evento ou timer. |
| MÉDIO | **Leitura depois de escrita no mesmo quadro.** `desenha()` escreve ~40 atributos SVG e depois chama `linhaDo()` → `getBoundingClientRect()` (linha 1430), o que força um recálculo de layout e estilo por quadro. `passo()` também lê `linhaDo()` (1104) e `getBoundingClientRect()` do alvo (845). | 1104, 1308-1430, 445 | Ler todas as posições uma vez no início do quadro, antes de qualquer `setAttribute`, e passar adiante. |
| MÉDIO | `listarPoleiros()` examina até **1400 elementos**, cada um com `getBoundingClientRect` + `getComputedStyle` (com cache) + `elementFromPoint`, e `fundoAtras()` sobe a árvore chamando `getComputedStyle` sem cache. Roda a cada troca de poleiro (~12 s) e em `levarAoLixo`. | 326-334, 384-440 | Cachear `fundoAtras` no mesmo `WeakMap`. Reduzir o teto ou filtrar antes por `IntersectionObserver` (só o que está na tela). |
| MÉDIO | O **cache de estilos é jogado fora** em toda chamada de `conferirLargura`, e ela dispara por `resize` **e** por `ResizeObserver` em `document.documentElement`. Esse observer dispara toda vez que a altura da página muda: carregar mais posts, abrir comentários. Não há debounce, e os dois gatilhos disparam juntos. | 1822-1831 | Debounce de ~150 ms. Observar só a largura (comparar `contentRect.width` com a anterior) e não zerar o cache quando só a altura mudou. |
| BAIXO | Filtros SVG `feGaussianBlur` na sombra e na "vergadura", com `cx`/`d` mudando a cada quadro. O blur é refeito a cada quadro. | 103-114, 1437-1447 | Sombra como elipse com gradiente radial, sem filtro. |
| BAIXO | `ecoNoPeito()` abre 3 laços rAF extras por evento. | 640-654 | Aceitável: curto e raro. |
| BAIXO | `mousemove` sem throttle, mas só atribui duas variáveis. | 510 | OK. Pode ser `passive: true`. |
| BAIXO | `prefers-reduced-motion` é lido uma vez só, e mudar a preferência com a página aberta não tem efeito. | 52 | `matchMedia(...).addEventListener("change", …)`. |

### `js/echo-ui.js`

| Risco | Achado | Linha | Sugestão |
|---|---|---|---|
| MÉDIO | **SSE continua conectado com a aba oculta.** O servidor fecha a cada 6 s e o `EventSource` reconecta sozinho, então uma aba em segundo plano reconecta ~10×/min. O listener de `visibilitychange` só reabre, nunca fecha. No `php -S`, cada conexão ocupa o único worker por 6 s (ver a tabela de TTFB). | 60-121 | Fechar o `EventSource` em `document.hidden` e reabrir na volta, porque o `fetchNotificationsAPI()` já cobre o intervalo. |
| MÉDIO | `renderRedeAgora` repete a cada 45 s (`visibilityState` conferido) e faz o fetch mesmo com o cartão em coluna `display:none` no celular. | 1814-1862 | Não iniciar o `setInterval` se `#redeAgoraCard` não estiver visível. |
| MÉDIO | `pingRedeIA()` dispara `tick.php` em toda abertura de página: até 3,4 s de trabalho no servidor, e custo de API quando houver chave. | 1351-1364 | Throttle por sessão (ex.: no máximo 1 a cada 60 s, guardado em `sessionStorage`). |
| MÉDIO | O menu móvel reaproveita as classes da sidebar, mas o CSS de 992 px as esconde globalmente (ver o item 2 das correções). | 451-515 | Ver o item 2. |
| BAIXO | `void btn.offsetWidth` para reiniciar a animação do sino. É leitura forçada de layout, mas rara (só quando o contador sobe). | 337 | OK. Alternativa sem reflow: `el.getAnimations().forEach(a => a.cancel())` e depois recolocar a classe. |
| BAIXO | `getComputedStyle(pai)` no autocomplete de menção: uma vez por campo. | 1548 | OK. |
| OK | Busca e menção com debounce de 250 ms. O polling reserva de 20 s respeita `document.hidden`. | 1431, 1582, 147-152 | — |

### `js/echo-feed.js`

| Risco | Achado | Linha | Sugestão |
|---|---|---|---|
| BAIXO | Imagens de post sem `loading="lazy"`, `decoding="async"`, `width`/`height`: baixam todas de uma vez e deslocam o layout ao chegar. | 182 | Ver o item 17. |
| BAIXO | Todo post novo entra com `echo-post-entra` (atraso escalonado por `--ordem`, até 8) e cada imagem com `echo-imagem-entra`. As duas são `transform`/`opacity`, então são baratas, mas somam 10–20 animações simultâneas por página carregada. | 166-167; CSS 1802, 1939 | OK. Pode-se limitar a animação aos 3–4 primeiros. |
| BAIXO | `void botao.offsetWidth` para reiniciar a animação da curtida: uma leitura forçada por clique. | 267 | OK. |
| OK | Sem polling, sem listener de scroll. A paginação é por botão, e o `insertAdjacentHTML` é feito em lote. | — | — |

### `css/echo.css`

**Animações que NÃO usam só `transform`/`opacity`:**

| Risco | Keyframes | Propriedade | Onde roda | Linha |
|---|---|---|---|---|
| **ALTO** | `echo-pulso-branco` | `color`, infinita, 10 s | Títulos, itens da sidebar, **todo autor de post**, tendências | 2145-2160 |
| MÉDIO | `ia-varredura` | **`left`**, infinita, 9 s | Banner da Rede IA, visível no celular | 2261-2269 |
| MÉDIO | `ia-espinha-pulso` | **`top`**, infinita, 5,2 s / 2,3 s | Painel dos agentes (≥ 992 px) | 3901-3912 |
| MÉDIO | `ia-elo-desce` / `ia-elo-sobe` | **`top`**, infinita, 1,15 s | Elo do painel (≥ 992 px) | 4020-4039 |
| MÉDIO | `ia-painel-gira` | custom property `@property` num `conic-gradient`: repinta, **não** vai para o compositor | Borda do painel (≥ 992 px) | 3794-3855 |
| MÉDIO | `echo-brilho` | `background-position`, infinita, 3,2 s | `.logo-text`: sidebar, offcanvas e login | 2064-2073 |
| BAIXO | `ia-cortex-sinal` / `ia-cortex-no` | `stroke-dashoffset` e `r` (SVG), infinitas | Córtex: ~30 elementos por agente, só com o agente ativo (pausado nos demais) | 3486-3518 |
| BAIXO | `echo-destaque`, `ia-piscada` | `background`/`background-color`, uma vez | Post destacado, fala destacada | 1471, 2914 |

**Propriedades que forçam repintura:**
- `backdrop-filter: blur(12px)` no cabeçalho sticky (361) e na barra inferior
  (321): **MÉDIO** no celular, porque roda a cada quadro de scroll.
- `backdrop-filter: blur(3px)` no fundo do diálogo (1269): BAIXO, é temporário.
- `box-shadow` nos estados ativos do painel (`.ia-painel-vivo`,
  `.ia-agente-ativo .ia-avatar`, `.ia-painel-led`, `.ia-painel-elo-aceso`):
  estáticos. Quem pulsa é `opacity`/`transform` do próprio elemento, então o
  navegador rasteriza a sombra uma vez. BAIXO.
- `filter: blur(0.4px)` no pulso da espinha (3899), combinado com a animação
  de `top`: repinta o blur a cada quadro. BAIXO/MÉDIO, porque só existe em
  ≥ 992 px.
- `mask-image` em cada `.ia-cortex` e máscara dupla na borda do painel:
  compõem uma camada a mais por bloco. BAIXO.

**Outros:**
- `body::before`/`::after` (58-87): duas camadas fixas de tela inteira com
  `radial-gradient`, animando `opacity` infinita em todas as páginas. A
  animação é barata (compositor), mas são duas camadas de tela cheia
  permanentes na memória da GPU. BAIXO.
- `will-change: contents` (805) não tem efeito prático. BAIXO: remover.
- `@property --painel-angulo` (3794): **o fallback existe e está correto.**
  Sem suporte (Safari < 16.4, Firefox < 128), o gradiente aparece parado. O
  único erro é o comentário que diz "roda no compositor".
- `prefers-reduced-motion` é bem coberto: a regra global da linha 1964 e as
  regras específicas do painel e do córtex.
- Os breakpoints `max-width: 768px` / `992px` batem de frente com o Bootstrap
  (ver o item 18).

### `rede_ia.html`

| Risco | Achado | Linha | Sugestão |
|---|---|---|---|
| **ALTO** | Rajada `setInterval` de **600 ms** (< 1000 ms). Confere `document.hidden`, mas não se o painel está visível, e no celular ele nunca está. | 1654, 1884-1898 | Ver o item 4. |
| **ALTO** | `setInterval(pulsarStatus, 3000)` durante a provocação, que também renova as rajadas de 600 ms. Hoje só é alcançável no desktop, porque o formulário some no celular. | 1960 | Mesmo guarda de visibilidade. |
| MÉDIO | Poller de feed + `pingRedeIA` + `pulsarStatus` a cada 15 s. Respeita `document.hidden`. | 394-402 | Tirar o `pulsarStatus` quando o painel estiver oculto. |
| MÉDIO | `desenharElo()` lê `offsetTop`/`offsetHeight` logo depois de `aplicarStatusAgentes()` trocar `innerHTML` e classes dos blocos: layout forçado a cada poll com agente ativo (5 s, ou 600 ms na rajada). | 1789-1795, 1803-1841 | Aceitável pelo volume. Dá para ler as posições dentro de um `requestAnimationFrame`. |
| MÉDIO | `getComputedStyle(origem)` a cada `desenharElo`, só para ler `--cor-agente`, que já está no `style` inline do bloco. | 1795 | Usar `origem.style.getPropertyValue("--cor-agente")`. |
| BAIXO | `cortexSVG()`: 7 topologias com 8–11 nós e ~15–25 linhas, ~30–35 elementos animáveis por agente. Com 10–17 agentes, são ~350–550 nós SVG com animação **pausada**: custo de DOM e memória, não de quadro. Só animam no agente ativo. | 1408-1466 | OK no desktop. No celular, nem montar enquanto o painel estiver oculto. |

**Todas as animações CSS que existem na Rede IA**

Medido com `getAnimations()` no celular, e completado pela leitura do CSS para
o que só aparece em ≥ 992 px:

| Animação | Alvo | Infinita? | Propriedade | Roda no celular? |
|---|---|---|---|---|
| `echo-luz-respira` ×2 | `body::before/::after` | sim | opacity | **sim** |
| `echo-pulso-branco` | título do cabeçalho (+ `.echo-author-link` se houver) | sim | **color** | **sim** |
| `ia-varredura` | `.ia-banner-glow` | sim | **left** + opacity | **sim** |
| `echo-onda` ×2 | `.logo-mark` do offcanvas (fechado!) | sim | transform/opacity | **sim, invisível** |
| `echo-pulsa-icone` | ícone do logo do offcanvas | sim | transform | **sim, invisível** |
| `echo-brilho` | `.logo-text` | sim | background-position | não (escondido por `display:none`) |
| `ia-painel-gira` | `.ia-painel-borda` | sim | custom property → repinta | só ≥ 992 px |
| `ia-espinha-pulso` | `.ia-painel-lista::after` | sim | **top** + opacity | só ≥ 992 px |
| `ia-no-standby` | `.ia-agente-bloco::before` (1 por agente) | sim | opacity | só ≥ 992 px |
| `ia-barra-respira` | barra do agente ativo | sim | opacity | só ≥ 992 px |
| `ia-pontinho` ×3 | pontinhos do agente ativo | sim | opacity/transform | só ≥ 992 px |
| `ia-cortex-sinal` | ~15–25 `<line>` por agente ativo | sim | stroke-dashoffset | só ≥ 992 px |
| `ia-cortex-no` | ~8–11 `<circle>` por agente ativo | sim | **r** + opacity | só ≥ 992 px |
| `ia-avatar-respira` | avatar do agente ativo | sim | transform | só ≥ 992 px |
| `ia-painel-led` | LED do topo | sim | opacity | só ≥ 992 px |
| `ia-painel-varre` | topo do painel | sim | transform/opacity | só ≥ 992 px |
| `ia-elo-desce`/`sobe` | elo entre dois agentes | sim | **top** + opacity | só ≥ 992 px |
| `ia-chegou` | fala nova | não | transform/opacity | sim |
| `ia-piscada` | fala destacada | não | background-color | sim |
| `echo-surge`, `echo-post-entra`, `echo-sino`, `echo-badge-entra`… | entradas pontuais | não | transform/opacity | sim |

No **celular**, 7 animações infinitas rodando em repouso, das quais 3 são
invisíveis (offcanvas fechado). No **desktop** (≥ 992 px), com a rede em
repouso e 10 agentes, são pelo menos 7 + 10 (`ia-no-standby`) + borda +
espinha = **~19 infinitas**. Com um agente ativo, entram mais ~35–40, somando
as linhas e os nós do córtex dele.

### `inicio.html`

| Risco | Achado | Linha | Sugestão |
|---|---|---|---|
| MÉDIO | 5 renderizações da coluna direita (`d-none d-lg-block`) disparadas também no celular. | 213-217 | Ver o item 8. |
| MÉDIO | Bootstrap CSS e **Font Awesome `all.min.css`** (todos os ícones) vêm de CDN e bloqueiam a renderização em toda página. `echo.css` tem 4048 linhas num arquivo só. | 8-10 | Font Awesome com subconjunto (só os ícones usados) ou SVG inline. `preconnect` para os CDNs. |
| BAIXO | `echo-bit.js` (2000 linhas) é baixado e executado no celular só para criar um SVG que fica oculto. | 179 | Carregar sob demanda: `if (matchMedia(...).matches) import(...)`, ou `defer`. |
| BAIXO | Os scripts do fim do `<body>` estão sem `defer`. | 176-179 | `defer` nos quatro. O código já espera o `DOMContentLoaded`. |
| OK | Sem `setInterval`, rAF ou listener de scroll próprios. | — | — |

---

## Arquivos gerados em `testes_mobile/`

> **As capturas (`.png`) não estão versionadas.** Só os relatórios e os dados
> brutos (`.md`, `.json`) entram no git — são 81 imagens, 15 MB, e cada rodada
> de teste somaria outro tanto. Toda referência a um `.png` neste documento
> aponta para um arquivo que existe apenas na máquina onde o teste rodou.
> Regra em `.gitignore`: `testes_mobile/**/*.png`.

- `{pagina}_{viewport}.png`: página inteira (15 arquivos). Na captura de
  página inteira, a barra inferior fixa aparece na altura da primeira tela, e
  não no fim da imagem. Isso é efeito da captura, não do layout.
- `{pagina}_{viewport}_menu.png`: menu lateral aberto (12 arquivos, páginas
  logadas).
- `rede_ia_375x812_tela_topo.png`, `rede_ia_375x812_tela_meio.png`,
  `explorar_375x812_tela_*.png`: só a área visível, como a pessoa vê.
- `dados.json`: todos os dados brutos de cada execução: console, requisições
  de API com início e duração, elementos fora da tela, grupos de fonte
  pequena, alvos de toque, animações e long tasks.

## O que ficou de fora

- **Perfil de CPU função a função** da long task da Rede IA. Os números mostram
  que ela existe, mas não apontam a função exata.
- **Rede limitada** (3G/4G). O servidor é local, então os CDNs e o
  `echo.css` pesam mais no mundo real do que aqui.
- **Feed cheio.** A conta de teste é nova, e Início, Perfil e Explorar tinham 0–1
  post. Vale repetir com uma conta que tenha amigos e posts com imagem.
- **Apache.** Os tempos de servidor foram medidos no `php -S` pedido. No
  Apache, a fila do SSE some (ver `ajustes.md`, 17/09).
- **Tablet (768–991 px)** e **celular deitado**, a faixa em que o Bit liga e a
  sidebar vira só ícones. Não estavam no pedido, mas é onde vivem os itens 13 e
  18.
- Safari/iOS real: o teste foi com Chrome emulando celular.
