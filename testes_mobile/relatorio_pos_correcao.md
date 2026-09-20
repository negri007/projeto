# Verificação pós-correção — responsividade e performance mobile

**Data:** 19/09/2026 · **Branch:** `feature/ia-agentes`
**Base da comparação:** commit `f2522e9`, o estado auditado em `relatorio.md`

Este documento é o registro vivo das 21 correções priorizadas: o corpo,
item a item, é a verificação da **1ª rodada**; os itens que a **2ª rodada**
fechou trazem uma nota em citação logo abaixo do título, com o que mudou e
o que foi medido. O Placar sempre reflete o estado atual.

| Rodada | Commit | O que entrou |
|---|---|---|
| 1ª | `b97cace` | `css/echo.css`, `rede_ia.html` e o `?v=` das 13 páginas — os 5 ALTOS |
| 2ª | `e4e906a` | `css/echo.css`, `assets/favicon.svg` e o `<head>` das 13 páginas — 7 baratos de CSS |

O escopo da 1ª rodada foi de dois arquivos:

```
 css/echo.css | 368 ++++++++++++++++++++++++++++++-----------
 rede_ia.html | 128 +++++++++++++++--
 2 files changed, 420 insertions(+), 76 deletions(-)
```

Isso define o alcance dela: **tudo que a lista original apontava em
`js/echo-bit.js`, `js/echo-ui.js`, `js/echo-feed.js`, `inicio.html`,
`explorar.html`, `index.html`, no banco e nos assets continuou como
estava** — e continua depois da 2ª, que também não tocou nenhum deles.

---

## Placar

Duas rodadas de correção até agora. A **1ª** (commit `b97cace`) fechou os
ALTOS; a **2ª** (commit `e4e906a`, mesmo dia) varreu os baratos de CSS.

| Veredito | Itens | Total |
|---|---|---|
| **RESOLVIDO** | 1, 2, 3, 4, 5, 7, 14 *(1ª rodada)* · 9, 10, 15, 18, 19, 20, 21 *(2ª)* | **14** |
| **PARCIALMENTE RESOLVIDO** | 6, 16 | **2** |
| **PENDENTE** | 8, 11, 12, 13, 17 | **5** |

Por severidade, que é a leitura que interessa:

| Severidade | Resolvido | Parcial | Pendente |
|---|---|---|---|
| **ALTO** (1–6) | 5 | 1 | 0 |
| MÉDIO (7–14) | 4 | 0 | 4 |
| BAIXO (15–21) | 5 | 1 | 1 |

**Os cinco problemas ALTOS que quebravam uso — barra cortada, menu com o
"Sair" atrás dela, Rede IA sem metade das funções no celular, polling para um
painel invisível e animação infinita de `color` por post — estão fechados.**
O sexto ALTO (long task / CLS) teve as causas atacadas, mas **não foi
remedido**, e essa medição é o que falta para fechar o item com número.

Os 5 pendentes restantes moram todos em arquivos que nenhuma das duas
rodadas tocou: `js/echo-bit.js`, `js/echo-ui.js`, `js/echo-feed.js`,
`inicio.html`, `explorar.html`, `index.html`, o banco e os assets.

### O que a 2ª rodada mediu

Chrome headless a 360×800, DPR 2, com `--fonte-meta` como sentinela de que
o `echo.css` já tinha sido aplicado antes de cada sonda — sem isso a fila
de um worker só do `php -S` faz a medição pegar a página ainda sem CSS.

| Item | Antes | Depois |
|---|---|---|
| 10 | mínimo 9,9px; 36,5% dos caracteres < 14px | **zero elementos < 12px** na tela; selo e assunto em 12px |
| 9 | `blur(12px)` no cabeçalho e na barra | `none` abaixo de 768px, fundo em 0,97 de opacidade |
| 18 | a 768 e a 992, os dois regimes valendo juntos | uma largura, um regime; nenhuma fronteira em conflito |
| 15 | 7 animações do logo rodando com o menu fechado | as 7 em `paused`; voltam a `running` ao abrir |
| 19 | toast em `bottom: 24px`, por cima da barra | base em 724 contra topo da barra em 740: 16px de folga |
| 21 | `favicon.ico` 404 em toda página | `<link rel="icon">` nas 13 páginas, nenhuma mensagem no console |

Nenhuma largura testada (767, 768, 991, 992, 1200) tem overflow horizontal,
em nenhuma das duas páginas medidas.

---

## Item a item

### 1 — Barra inferior com 7 itens: "Mensagens" cortado · **RESOLVIDO**

**O que o diff mostra** (`css/echo.css:349-382`): `.mobile-bottom-nav` passou
a `align-items: stretch` e cada `a` virou `flex: 1 1 0; min-width: 0;
padding: 0`, centrado por flex. Some o `padding: 8px 16px` que fixava ~55 px
por item e somava 389 px.

Com a célula em 1/7 da barra: **51,4 px em 360, 53,6 px em 375, 55,7 px em
390** — sempre menor que a largura da tela, por construção, e não por
acomodação de conteúdo.

**Evidência:** `pos_correcao/grupo1/barra_360x800.png`, `barra_375x812.png`,
`barra_390x844.png`. Nas três, os sete ícones aparecem inteiros e o último
(Mensagens) está dentro da tela, com folga da borda direita. Comparar com
`inicio_375x812.png` da rodada anterior.

### 2 — Menu móvel: "Sair" atrás da barra; logo e nome escondidos; links sem estilo · **RESOLVIDO**

Eram quatro defeitos num item só, e o diff resolve os quatro:

| Defeito | Correção no diff |
|---|---|
| "Sair" e perfil atrás da barra inferior | `z-index` da `.mobile-bottom-nav` caiu de **1050 para 1040** (`css/echo.css:364`), abaixo dos 1045 do offcanvas |
| "ECHO" e nome do usuário sumindo | a regra de `max-width: 992px` virou `.sidebar .logo-text` / `.sidebar .prof-info` (`css/echo.css:1196-1200`); antes apagava as classes em qualquer lugar |
| links azuis, sem estado ativo, ícone colado no texto | bloco novo `.offcanvas-dark .nav-link` (`css/echo.css:316-338`): cor do tema, `gap: 14px`, ícone de largura fixa, `:hover`/`.active` em azul com fundo |
| — (prevenção) | `.offcanvas-dark .offcanvas-body` (`:344`) ganhou `padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))` |

O seletor `.offcanvas-dark` casa: é a classe que `js/echo-ui.js:455` põe no
`#mobileSidebarOffcanvas`.

**Evidência:** `pos_correcao/grupo1/menu_360x800.png` (e `375`, `390`).
Aparecem o "ECHO" ao lado do logo, os oito links em branco com ícone
espaçado, "Início" destacado em azul com pílula de fundo, e no pé o bloco
"Alice (teste) / @alice" com o botão de sair **inteiro e clicável**, sem nada
por cima.

### 3 — Rede IA no celular perde "Falar com a IAlândia", "Seu agente", painel e IAlândia · **RESOLVIDO**

**`rede_ia.html:137-138`:** o `<aside>` deixou de ser `d-none d-lg-block` e
virou `class="col-lg-3 right-col offcanvas-lg offcanvas-end"
id="painelRede"`, com cabeçalho `d-lg-none` e botão de fechar.

**`rede_ia.html:90-100`:** `<nav class="ia-atalhos-painel d-lg-none">` com
quatro botões — Agentes, Provocar, Seu agente, IAlândia — chamando
`abrirPainelRede(id)`.

**`rede_ia.html:2007-2022`:** `abrirPainelRede()` abre o offcanvas e, em
`shown.bs.offcanvas`, rola até o cartão pedido (`scrollIntoView`); com o
painel já aberto, só rola.

**`css/echo.css:4184-4282`:** estilo dos atalhos e o bloco
`@media (max-width: 991.98px)` que devolve `position: fixed` ao
`.right-col.offcanvas-lg` — sem isso o `position: sticky` da `.right-col`
ganhava do offcanvas e o painel ficava preso no fim da grade. Mais
`scroll-margin-top: 72px` nos cartões (para o título não parar sob o
cabeçalho fixo) e `flex-shrink: 0` (para o cartão do painel não ser espremido
até o título).

Detalhe que importa para o resto do código: **são os mesmos nós do DOM nas
duas telas**, não uma cópia. Nenhum id duplicado; o JS que preenche saldo,
painel e provocação continua igual.

**Evidência:**
- `pos_correcao/grupo2/rede_ia_375x812_topo.png` — a fileira de 4 atalhos
  acima do banner.
- `..._painel_seu_agente.png` — painel aberto no "Seu agente", com
  **Créditos 10** e "Criar agente".
- `..._painel_agentes.png` — "Os agentes", 15 na rede, com a espinha e a
  borda roxa.
- `..._painel_provocar.png` — "Falar com a IAlândia" com textarea, os 15
  chips de escolha, o botão **Provocar** e o "Como funciona" logo abaixo.
- Idem em 360×800 e 390×844.
- `..._1366x900_desktop_topo.png` — no desktop nada mudou: coluna direita no
  lugar de sempre e **sem** a fileira de atalhos (`d-lg-none` cumprindo o
  papel).

### 4 — Polling de `status.php` com o painel oculto · **RESOLVIDO**

**`rede_ia.html:1896-1906`:** função nova `painelVisivel()`, com três testes
em cima de `#painelAgentes` — `offsetParent === null` (pega `display:none`),
`getComputedStyle(...).visibility === "hidden"` (pega o offcanvas fechado,
que **continua no layout**) e o retângulo contra a viewport (pega o painel
deslocado para fora da tela ou rolado para longe). `document.hidden` entrou
como primeiro teste.

Os dois pontos de entrada passaram a ser guardados:
- `buscarStatus()` (`:1909`) troca `if (document.hidden) return` por
  `if (!painelVisivel()) return`.
- `pulsarStatus()` (`:1951`) ganha `if (!painelVisivel()) return` **antes** de
  abrir a rajada — o `setInterval` de 600 ms nem chega a existir.

Como o `aplicarStatusAgentes()` só roda dentro do `buscarStatus()`, o painel e
os córtices **também deixam de ser montados** enquanto está escondido, que
era a segunda metade do item.

**`rede_ia.html:1991-1999`:** `vigiarPainel()` com `IntersectionObserver`
busca o status no instante em que o painel entra na vista, para ele não
aparecer com até 5 s de defasagem; `iniciarPollerStatus()` (`:1971`) só faz a
busca imediata quando o observer não existe.

No celular isso zera as ~44 requisições/min que a rodada anterior mediu
enquanto o painel sequer aparecia.

**Ressalva honesta:** `pingRedeIA()` (`tick.php`) continua disparando em toda
abertura (`rede_ia.html:307, 437`). Ele não é deste item — é um MÉDIO da
seção do `inicio.html` — e segue sem throttle.

### 5 — `echo-pulso-branco`: animação infinita de `color` em todo autor de post · **RESOLVIDO**

**`css/echo.css:2201-2204`:** o keyframe passou de `color` para **`opacity`**
(`1 → 0.55 → 1`), que o navegador anima no compositor. O desenho mudou de
"acende em azul" para "respira" — está registrado no comentário.

**`css/echo.css:2216-2222`:** `.echo-trend-body strong` e `.echo-author-link`
ganharam `animation-iteration-count: 1`. Nome de autor e tendência se repetem
por post: agora a animação **termina sozinha** em vez de rodar para sempre em
cada post que já rolou para fora da tela. Menu e títulos, que são poucos e
fixos, seguem no ciclo.

**`css/echo.css:2227-2237`:** bloco `prefers-reduced-motion` desligando o
pulso nos sete seletores, sem depender da regra global de `:2017`.

### 6 — Long task de ~450 ms e CLS de 0,11 na Rede IA · **PARCIALMENTE RESOLVIDO**

**O que o diff comprova:** as causas suspeitas foram atacadas de fato. Painel
e córtex não são mais montados com o painel escondido (item 4); as animações
de `left`/`top` do banner saíram da thread principal (item 7); a borda do
painel parou de repintar o card a cada quadro (item 14). São as três
hipóteses que o relatório original listava para a long task.

**O que falta, e é por isso que não é RESOLVIDO:**

1. **Nada foi remedido.** Não há nesta rodada uma execução de performance
   equivalente à anterior — `pos_correcao/` tem capturas de layout e uma
   comparação visual quadro a quadro, mas nenhum `dados.json` com long task,
   TBT, CLS ou FPS. Os números de 410–465 ms e CLS 0,109–0,122 seguem sem
   contraprova.
2. **O CLS não foi endereçado.** A sugestão era reservar altura para o banner
   e a conversa (skeleton de mesma altura). O diff não tem `min-height`,
   `aspect-ratio` nem skeleton em `.ia-banner` ou `#conversaContainer`. A
   fileira de atalhos nova é de altura fixa (`min-height: 56px` nos botões,
   `css/echo.css:4208`), então não piora — mas não conserta.

### 7 — Animações de layout: `ia-varredura`, `ia-espinha-pulso`, `ia-elo-desce`/`sobe` · **RESOLVIDO**

As quatro saíram de `left`/`top` para `transform`. Cada uma exigiu uma
conversão de unidade, porque `translate%` mede pelo **próprio** elemento e as
posições antigas mediam pelo pai:

| Animação | Antes | Depois | Como o percurso foi mantido |
|---|---|---|---|
| `ia-varredura` (`:2349`) | `left: -35% → 105%` do banner | `translateX(-77.78% → 233.33%) skewX(-18deg)` | o brilho tem 45% da largura do banner: −35/45 e 105/45 |
| `ia-espinha-pulso` (`:4034`) | `top: -10% → 100%` | `translateY(-10% → 100%)` | a caixa passou a ter `height: 100%` da lista e o pulso de 46 px virou `background … top / 100% 46px no-repeat` |
| `ia-elo-desce` (`:4160`) / `ia-elo-sobe` (`:4167`) | `top: -18px ↔ 100%` | `translateY(-18px ↔ 100%)` | mesma técnica: caixa com a altura do elo, segmento de 18 px como fundo preso no topo |

Os estados de repouso foram preservados junto: `transform: translateY(100%)`
no `::after` da espinha e `translateY(40%)` na regra de
`prefers-reduced-motion` do elo (`:4179`), onde antes havia `top`.

**Evidência numérica — `pos_correcao/comparacao_visual/resultado.json`.** Cada
cena foi capturada em 6 frações do ciclo (0.10 a 0.85), antes e depois, com
diferença pixel a pixel:

| Cena | Pior fração | Pixels diferentes | % da imagem |
|---|---|---|---|
| `banner_varredura` | todas | **0** | **0 %** |
| `espinha_pulso` | 0.40 | 222 | **0,44 %** |
| `elo_desce` | 0.85 | 109 | **0,216 %** |
| `elo_sobe` | 0.85 | 109 | **0,216 %** |
| `borda_gira` (item 14) | 0.25 | 229 | **0,079 %** |

A varredura do banner ficou **idêntica ao pixel** nas seis frações. As outras
ficam abaixo de meio por cento, e o que aparece é deslocamento sub-pixel na
ponta do pulso — visível em
`comparacao_visual/espinha_pulso_0.4_diff.png`, onde a marca vermelha é uma
tira de 3 px de largura, e nos pares `*_antigo.png` / `*_novo.png`.

**Nota sobre os arquivos:** a cena `borda_gira` tem as seis linhas no
`resultado.json`, mas **não tem PNGs salvos** na pasta (as outras três cenas
têm `antigo`/`novo`/`diff` das frações com diferença, mais um
`exemplo_novo`). Para essa cena só existem os números.

### 8 — Chamadas para cartões invisíveis no Início e no Explorar · **PENDENTE**

`inicio.html`, `explorar.html` e `js/echo-ui.js` não estão no diff. As 5
chamadas do Início (`hashtags/trending`, `circles/list`, `profile/get`,
`ai/feed` com repetição de 45 s, `friends/suggestions`) e as 2 do Explorar
continuam saindo no celular para alimentar uma coluna `display:none`.

### 9 — `backdrop-filter: blur(12px)` no cabeçalho fixo e na barra inferior · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** abaixo de 768px os dois vão para `backdrop-filter: none`
> com o fundo em `rgba(0,0,0,.97)`. Medido a 360×800: `backdropFilter` computa
> `none` no cabeçalho e na barra. Do `md` para cima o blur continua.

O que estava pendente na 1ª rodada:

`css/echo.css:357-358` (barra inferior) e `:408-409` (cabeçalho) seguem com
`blur(12px)` e sem nenhuma regra abaixo de 768 px que o desligue. A única
alteração na barra inferior foi de layout e `z-index`.

### 10 — Legibilidade: textos de 9,9–13,6 px · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** piso declarado em `--fonte-meta: 0.75rem` (12px) no
> `:root`, aplicado em 11 seletores. Selo 9,92 → **12px**, assunto 10,88 →
> **12px**, badge "observando" 11,52 → **12px**. Varredura da tela da Rede IA:
> **zero elementos com texto abaixo de 12px**, sem overflow horizontal.
> Conteúdo e nomes já passavam (`.ia-texto` em 15,2px) e não foram tocados.
> Duas exceções documentadas no código: contador do sino e letra inicial de
> avatar, caractere solto em disco de 18px. Junto foi o `max-width` do chip
> de assunto, que com a fonte maior truncava cedo demais numa linha vazia.

O que estava pendente na 1ª rodada:

Nenhum dos seletores citados mudou: `.ia-selo` continua em `0.62rem`
(≈ 9,9 px, `:2533`), `.ia-handle` em `0.8rem` (`:2530`), `.ia-tempo` em
`0.78rem` (`:2761`), e `.post-handle`/`.post-time` (`:719-720`) intactos.

Visível em `pos_correcao/grupo2/rede_ia_375x812_painel_agentes.png`: os
`@handle` abaixo dos nomes continuam visivelmente menores que o corpo.

### 11 — 500 em `provocacoes.php` e 404 nos avatares `_gen2` / `echo_sistema` · **PENDENTE**

É correção de banco e de assets, fora dos dois arquivos tocados. As capturas
confirmam que segue: em
`pos_correcao/grupo2/rede_ia_375x812_painel_agentes.png` e na faixa de chips
de `..._topo.png`, os sete primeiros agentes têm avatar e **todos os `*_gen2`
(Marave, Maredr, Tronha, Sidete, Donave, Fuinma, Donnha, Doneta) aparecem sem
imagem nenhuma** — nem foto, nem a letra inicial de fallback que o item
sugeria. O mesmo no desktop (`..._1366x900_desktop_topo.png`).

Esta rodada não capturou console, então o 500 do `provocacoes.php` não foi
reconferido; nada no diff mexe nele.

### 12 — Splash do login e scripts de CDN sem `defer` · **PENDENTE**

`index.html` não está no diff.

### 13 — Bit ligando em celular deitado · **PENDENTE**

`js/echo-bit.js` não está no diff; o critério continua só `innerWidth >= 768`.

### 14 — Borda do painel via `@property`: repinta o card a cada quadro · **RESOLVIDO**

**`css/echo.css:3873-3983`:** o `@property --painel-angulo` e o keyframe
`to { --painel-angulo: 360deg }` foram removidos. Agora o gradiente vive numa
variável `--painel-gradiente` (`:3910`) e quem gira é um `::before` com o
gradiente **fixo**, rodado por `transform: rotate()` — isso o navegador compõe
na GPU sem repintar. A máscara do anel continua no `.ia-painel-borda` e
recorta a camada girando por baixo.

Três detalhes que o diff acerta e vale registrar:

- A camada é um quadrado do tamanho da **diagonal** do card
  (`hypot(100cqw, 100cqh)`, com o card como container), para cobrir em
  qualquer ângulo.
- O keyframe repete `translate(-50%, -50%)` em cada quadro, porque a animação
  substitui o `transform` inteiro; sem isso a camada giraria em torno do
  canto.
- Tudo dentro de `@supports (width: hypot(3px, 4px)) and
  (container-type: size)` (`:3945`). Sem suporte, não existe `::before` e fica
  o `background: var(--painel-gradiente)` parado no próprio
  `.ia-painel-borda` — o mesmo degrade honesto de antes, agora **garantido**
  em vez de dependente da ausência de `@property`.

O `.ia-painel-vivo` (rede pensando) manteve os dois ritmos: 9 s → 3,4 s agora
no `::before`, e a opacidade 0,8 → 1 seguiu no elemento.

**Evidência:** cena `borda_gira` no `resultado.json` — diferença máxima de
**229 px (0,079 %)** entre as seis frações do ciclo antigo e do novo. A borda
gira igual. Visualmente confirmada em
`pos_correcao/grupo2/rede_ia_375x812_painel_agentes.png` (anel roxo no card
"Os agentes") e no desktop.

### 15 — Animações infinitas invisíveis no offcanvas fechado (logo) · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** `animation-play-state: paused` em
> `.offcanvas:not(.show):not(.showing):not(.hiding)`, o mesmo molde do painel
> da Rede IA que já existia. Medido com `getAnimations()`: menu fechado, as 7
> animações do logo em `paused`; abrindo, voltam a `running`.

O que estava pendente na 1ª rodada:

O item era sobre o `.logo-mark` do menu lateral móvel: `echo-onda` ×2 e
`echo-pulsa-icone` rodando com o menu fechado, porque o Bootstrap usa
`visibility: hidden`. Não há nenhuma regra nova de `.offcanvas:not(.show)`
para o `#mobileSidebarOffcanvas` — as ocorrências de `animation-play-state`
em `:3579, 3589, 3610, 3624` são as pré-existentes do córtex.

**Vale notar:** o painel novo **já nasceu com esse cuidado**
(`css/echo.css:4276-4281` pausa toda animação dentro do
`.right-col.offcanvas-lg:not(.show)`), então a mudança do item 3 não criou
uma segunda instância do problema. A regra existente é um bom molde para
fechar este item.

### 16 — Alvos de toque < 44 px · **PARCIALMENTE RESOLVIDO**

**Melhorou:** os links da barra inferior passaram de ~55×~38 px com padding
fixo para uma célula de **51–56 px de largura por 60 px de altura**
(`flex: 1 1 0` + `align-items: stretch`), acima dos 44 px nas três viewports.
Os botões novos dos atalhos nascem com `min-height: 56px`
(`css/echo.css:4208`).

**Continua:** hambúrguer 30×32, sino 38×36, botões de imagem e "@" do
compositor, "Postar", chips de 34 px da Rede IA e os links de texto do login —
nada disso está no diff.

### 17 — `<img class="post-image">` sem `loading="lazy"` nem dimensões · **PENDENTE**

`js/echo-feed.js` não está no diff.

### 18 — Breakpoints desencontrados com o Bootstrap · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** os dois blocos antigos viraram `991.98px` e `767.98px`.
> Conferido com `matchMedia` nos dois lados de cada fronteira, em duas
> páginas: a 768 a barra lateral vira coluna de 72px e a barra inferior some;
> a 992 a lateral volta a 165px com rótulos e a coluna direita abre. Nenhuma
> largura com os dois regimes valendo juntos, nenhuma com overflow.

O que estava pendente na 1ª rodada:

O bloco novo do painel usa o valor certo: `@media (max-width: 991.98px)`
(`css/echo.css:4229`). Os blocos antigos seguem em `max-width: 992px`
(`:1179`) e `max-width: 768px` (`:1217`), então em exatamente 768 e 992 px as
duas regras continuam valendo juntas.

### 19 — Toasts por cima da barra inferior no celular · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** `bottom: calc(60px + 16px + env(safe-area-inset-bottom))`
> abaixo de 768px. Medido a 360×800: base do toast em 724, topo da barra em
> 740, **16px de folga**. A regra ficou junto da declaração do toast, e não no
> bloco de 767.98px lá em cima, porque `.echo-toast-stack` é declarado depois
> dele e media query não soma especificidade.

O que estava pendente na 1ª rodada:

`.echo-toast-stack` continua em `bottom: 24px` (`css/echo.css:1252`), sem
regra para telas pequenas.

### 20 — Barra inferior sem `env(safe-area-inset-bottom)` · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** `height: calc(60px + env(safe-area-inset-bottom))` e
> `padding-bottom` na barra; o `padding-bottom` da `.main-col` acompanha, senão
> o último post fica atrás dela. `viewport-fit=cover` entrou na meta viewport
> das 13 páginas, sem o qual o `env()` devolve 0 sempre.

O que estava pendente na 1ª rodada:

A `.mobile-bottom-nav` segue com `height: 60px` e sem `padding-bottom` de área
segura. As duas ocorrências novas de `env(safe-area-inset-bottom)` no diff
estão em outros lugares: no `.offcanvas-dark .offcanvas-body` (`:345`,
item 2) e no painel deslizante (`:4241`, item 3). E o `<meta name="viewport">`
continua sem `viewport-fit=cover`, que é pré-requisito para o `env()` valer
alguma coisa.

### 21 — `favicon.ico` 404 · **RESOLVIDO** (2ª rodada)

> **19/09, `e4e906a`:** `assets/favicon.svg` novo, a marca da barra lateral
> redesenhada em SVG, com `<link rel="icon">` nas 13 páginas. É o link
> declarado que mata o 404: o navegador só pede `/favicon.ico` sozinho quando
> a página não declara ícone nenhum. Conferido: nenhuma mensagem de favicon no
> console.

O que estava pendente na 1ª rodada:

Nenhum `<link rel="icon">` em nenhum `.html`, e não há favicon na raiz.

---

## Itens fora da lista numerada

O relatório original trazia, nas seções por página, alguns achados que não
entraram nas 21 correções priorizadas. Situação deles:

| Achado | Estado |
|---|---|
| Banner da Rede IA ocupando a primeira tela inteira no celular | **PENDENTE** — e a fileira de atalhos, embora baixa, empurra o banner ~76 px para baixo. Em `pos_correcao/grupo2/rede_ia_375x812_topo.png` a conversa continua toda abaixo da dobra |
| Título "Sete agentes" com mais chips que sete | **PENDENTE** — o mesmo texto, agora com 15 chips (o painel confirma: "15 NA REDE") |
| `pingRedeIA()` em toda abertura de página | **PENDENTE** |
| SSE conectado com a aba oculta (`js/echo-ui.js`) | **PENDENTE** |
| Font Awesome inteiro e Bootstrap por CDN bloqueando a renderização | **PENDENTE** |
| `will-change: contents` sem efeito (`css/echo.css:852`) | **PENDENTE** |

---

## Observações sobre o próprio diff

Três coisas que valem registro, nenhuma delas um defeito encontrado:

1. **`painelVisivel()` faz `getComputedStyle` + `getBoundingClientRect`** a
   cada chamada, e na rajada isso é a cada 600 ms. É leitura de layout
   forçada, mas o custo é ordens de grandeza menor que o fetch que ela evita,
   e no celular ela sai no primeiro teste (`offsetParent`/`visibility`) sem
   chegar ao retângulo.
2. **`vigiarPainel()` cria o `IntersectionObserver` sem guardá-lo.** Não há
   `disconnect()`, mas o observer vive enquanto a página vive e é criado uma
   vez só (`iniciarPollerStatus` tem guarda de reentrada), então não vaza.
3. **A regra de pausa do painel fechado** usa o seletor universal
   (`… :not(.show) *`) — é o que garante que a borda, a espinha e as luzes de
   standby não rodem para ninguém ver enquanto o offcanvas está fechado, já
   que `visibility: hidden` não para animação CSS. Foi o ponto certo a cobrir
   ao trazer a coluna para o celular.

---

## O que ainda não foi verificado

- **Medição de performance — o que falta para fechar o item 6.** Nenhuma das
  duas rodadas mediu long task, TBT, CLS ou FPS. Sem isso o item 6 fica sem
  número, e o efeito real das correções 5, 7, 9 e 14 na thread principal é
  dedução a partir do código, não medida. **É a próxima coisa a fazer:**
  repetir a etapa 2 do `relatorio.md` (CPU 4×, as três viewports) e comparar
  contra a tabela de lá.
- **Console e rede pós-correção.** A 2ª rodada viu **um** erro de console na
  Rede IA a 360×800, o 500 do `provocacoes.php`. Os 404 dos avatares `_gen2`
  não apareceram nessa carga, mas a conversa capturada não trazia agente
  `_gen2` nenhum — não é prova de que sumiram, e o item 11 continua aberto.
- **O painel deslizante em uso real.** Foi capturado aberto nos três cartões
  e continua abrindo depois da mudança de breakpoint (medido: `left: 0`,
  360px de largura), mas não houve teste de envio de provocação nem de
  criação de agente pelo painel no celular.
- **Regressão no desktop além do topo.** Há uma captura
  (`rede_ia_1366x900_desktop_topo.png`) e ela está correta, mas cobre só a
  primeira tela.
- As mesmas ressalvas da rodada anterior seguem valendo: conta de teste sem
  amigos e sem posts, `php -S` de um worker só, Chrome emulando celular.

---

## Arquivos desta rodada

> **As capturas (`.png`) não estão versionadas.** Só os relatórios e os dados
> brutos (`.md`, `.json`) entram no git — são 81 imagens, 15 MB, e cada rodada
> de teste somaria outro tanto. Toda referência a um `.png` neste documento
> aponta para um arquivo que existe apenas na máquina onde o teste rodou.
> Regra em `.gitignore`: `testes_mobile/**/*.png`.

```
testes_mobile/pos_correcao/
├── grupo1/                                     itens 1 e 2
│   ├── barra_{360x800,375x812,390x844}.png     barra inferior, 7 ícones
│   └── menu_{360x800,375x812,390x844}.png      menu móvel aberto
├── grupo2/                                     item 3
│   ├── rede_ia_{360x800,375x812,390x844}_topo.png             atalhos + banner
│   ├── rede_ia_{360x800,375x812,390x844}_painel_agentes.png
│   ├── rede_ia_{360x800,375x812,390x844}_painel_provocar.png
│   ├── rede_ia_{360x800,375x812,390x844}_painel_seu_agente.png
│   └── rede_ia_1366x900_desktop_topo.png       controle de regressão
└── comparacao_visual/                          itens 7 e 14
    ├── resultado.json                          30 medições de pixel
    ├── {cena}_{fracao}_{antigo,novo,diff}.png
    └── {cena}_exemplo_novo.png
```
