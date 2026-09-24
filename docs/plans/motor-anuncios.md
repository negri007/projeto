# Motor de Anúncios do Echo — como vai funcionar

> Como o Echo transforma o que uma loja já tem (uma foto, um nome, um preço)
> em um vídeo de marketing que **parece feito por IA, mas não é** — e por que
> dois anúncios do mesmo nicho quase nunca saem iguais.

---

## 1. A ideia central: estilo por nicho, não vídeo por nicho

O erro comum seria ter **um vídeo fixo por nicho** — aí toda pizzaria receberia
o mesmo anúncio, só trocando a foto. Vira xerox, e o cliente percebe.

O Echo faz diferente. Cada nicho é um **estilo** (uma "identidade visual"), não
um vídeo pronto. O estilo define a *linguagem*:

| Nicho | Tipografia | Cor | Clima | Trilha |
|-------|-----------|-----|-------|--------|
| Comida | pesada, condensada (Anton) | laranja quente | vapor, apetite | animada |
| Moda | serifada editorial (Georgia) | dourado/nude | elegante, drift lento | sofisticada |
| Joia | fina, luxo | preto + ouro | escuro, brilho | calma refinada |
| Tech | geométrica limpa | azul neon | grid, scanline | eletrônica |
| Beleza/Spa | leve, arejada | verde/bege | ambiente, calma | relaxante |
| Fitness | itálica forte | verde energia | diagonal, movimento | pulsante |

Dentro desse estilo, as **peças são trocáveis**. É a diferença entre um molde
de bolo (sempre o mesmo bolo) e uma receita (mesmo sabor, cada bolo sai um
pouco diferente).

---

## 2. As três camadas de cada anúncio

```
┌─────────────────────────────────────────────┐
│  CAMADA 3 — Marca / edição                   │
│  logo, cor da loja, CTA ("Peça no WhatsApp") │
├─────────────────────────────────────────────┤
│  CAMADA 2 — Movimento                        │
│  Ken Burns, texto animado, transições,       │
│  grão de filme, light leak, trilha           │
├─────────────────────────────────────────────┤
│  CAMADA 1 — Visual base                      │
│  foto da loja  OU  imagem gerada (futuro IA) │
└─────────────────────────────────────────────┘
```

- **Camada 1** é o que a loja fornece (foto do produto). Se ela não tiver foto,
  no plano Premium entra imagem gerada por IA.
- **Camada 2** é o "molho" — o que faz parecer profissional/IA. É 100% código
  (Remotion + ffmpeg), custo zero por render além de CPU.
- **Camada 3** é a assinatura da loja.

O que faz parecer IA é a **Camada 2**: câmera que se move, texto que entra letra
por letra, granulado de cinema, luz varrendo a cena. Ninguém faz isso à mão em
5 minutos — então a percepção é "isso foi gerado".

---

## 3. Como a repetição morre (o ponto que você levantou)

Cada anúncio é sorteado a partir do estilo do nicho:

```
nicho = comida
 ├─ variante de layout : [A, B, C, D]        → sorteia 1
 ├─ trilha             : [t1, t2, t3, t4, t5] → sorteia 1
 ├─ cor de destaque    : cor da loja  OU  paleta do nicho
 ├─ transição/efeito   : [suave, forte]       → sorteia 1
 └─ dados              : foto/nome/preço da loja → SEMPRE único
```

**Só a moldura já dá 4 × 5 × 2 = 40 combinações.** Somando os dados da loja
(que já são únicos), a chance de duas lojas do mesmo nicho receberem algo
parecido é mínima.

Variantes de layout (exemplo, comida):
- **A** — título gigante embaixo, produto ocupa tela toda
- **B** — título em cima, faixa de preço na diagonal
- **C** — foto num canto, fundo de cor sólida da marca
- **D** — split: metade foto, metade texto

Mesmo estilo (comida = laranja + Anton + vapor), quatro composições diferentes.

---

## 4. Os formatos (que viram os planos)

| Formato | Cenas | Estrutura | Plano | Peso do render |
|---------|-------|-----------|-------|----------------|
| **Flash** | 1 | produto + preço + CTA | Básico (grátis) | leve |
| **História** | 3 | abre → destaque → fecha | Pro (pago) | médio |
| **Completo** | 6–10 | hero → specs → preço → marca | Premium | pesado |

> Decisão atual: seguir só com **1 cena** (Flash) e **3 cenas** (História). O de
> 6–10 cenas foi o teto de demonstração (carro/moto) — fica guardado como prova
> de que o motor escala, mas não entra no produto agora.

- **Flash (1 cena, grátis):** qualquer loja usa. Chama atenção, mostra o
  produto e o preço. É o "chamariz" que traz o cliente pro app.
- **História (3 cenas, pago):** abre com o produto, um plano de detalhe
  (ingrediente/textura/ângulo), e fecha com preço + marca + CTA. Conta uma
  micro-história — parece anúncio de agência.

O plano pago não é "o mesmo vídeo com marca d'água tirada" — é **mais cenas,
mais montagem, mais trabalho de render**. O preço acompanha o peso real.

---

## 5. Como flui dentro do Echo

```
LOJA (canvas.html)                SERVIDOR (api/video)              FILA
──────────────────                ────────────────────             ─────
1. escolhe formato   ─POST─►  gerar.php                       
   (Flash / História)         ├─ checa modo (acervo = bloqueia)
2. envia:                     ├─ checa 1/hora por loja
   {nicho, fotos,             ├─ grava 'gerando'
    textos, preço,            └─ enfileira ──────────────►  processar.php
    cor, formato}                                            ├─ escolhe preset do nicho
                                                             ├─ sorteia layout+trilha
3. front faz poll   ◄─status─  status.php                    ├─ monta cenas (dados da loja)
   até 'pronto'                                              ├─ render (Remotion → MP4)
                                                             ├─ som (ffmpeg: trilha+SFX)
                              vídeo pronto  ◄────────────────┘ grava 'pronto' + url
```

O encanamento **já existe** hoje (`gerar.php`, `status.php`, `processar.php`,
tabela `videos_gerados`, freio de 1/hora, respeito ao modo acervo). O que falta
é o `processar.php` chamar o **motor** (Remotion) em vez da API paga como
caminho principal.

Camadas de custo por plano:
- **Flash/História** → render local (Remotion + ffmpeg). Custo = CPU do
  servidor. **$0 de API.**
- **Premium (futuro)** → foto gerada por IA (Gemini/Cloudflare grátis) e/ou
  animação por Kling (paga). Aí sim gasta crédito real.

---

## 6. Créditos e planos

| Plano | O que dá | Custo pro Echo | Cobrança |
|-------|----------|----------------|----------|
| **Básico** | Flash (1 cena), ilimitado com freio de 1/hora | CPU | grátis |
| **Pro** | História (3 cenas), variantes, sem freio apertado | CPU (mais pesado) | mensalidade ou créditos |
| **Premium** | imagem/vídeo por IA quando a loja não tem foto boa | API paga (Kling) | créditos por geração |

O sistema de créditos (`ai_credits` na tabela users) e o seletor de modo
(`acervo`/`hibrido`/`api`) já existem — encaixam direto aqui.

---

## 7. Estoque de estilos (o que construir)

Para cada nicho, o motor precisa de:

1. **Preset visual** — cor, fonte, filtro de cor, efeitos (já temos 6 nichos
   como base: Comida, Moda, Joia, Tech, Beleza, Fitness).
2. **3–4 variantes de layout** por nicho (hoje temos 1 cada — falta variar).
3. **Pool de trilhas** por humor — 4–5 faixas de domínio público por clima, pra
   sortear (hoje temos 1 por nicho — falta o pool).
4. **Transições** reutilizáveis (crossfade, wipe, zoom-blur).

Tudo isso é **conteúdo do acervo** — construído uma vez, reutilizado infinito,
sem custo por uso. É a filosofia de acervo do app aplicada a marketing.

---

## 8. Roadmap sugerido

- [ ] **Fase 1 — Motor genérico:** transformar os 6 nichos (1 cena) e o formato
      de 3 cenas em templates data-driven `{nicho, fotos, textos, preço, cor}`.
- [ ] **Fase 2 — Variação:** 3–4 layouts por nicho + pool de trilhas + sorteio.
- [ ] **Fase 3 — Integração Echo:** `processar.php` chama o motor; render pela
      fila; entrega em `videos_gerados`.
- [ ] **Fase 4 — Planos:** amarrar Flash/História a Básico/Pro; créditos.
- [ ] **Fase 5 — Premium/IA:** imagem por IA (Gemini/Cloudflare grátis) na
      Camada 1 quando a loja não tem foto; Kling na Camada 2 sob crédito.

---

## 9. Acervo de modelos (já construídos)

Cada modelo é um componente Remotion genérico, alimentado pelos dados da loja.
Estado em 24/09/2026 — 13 modelos, todos $0 de API:

| Modelo | Efeito | Forte para |
|--------|--------|-----------|
| Flash | 1 cena, título+preço | qualquer / Básico |
| Historia | 3 cenas, micro-história | qualquer / Pro |
| Manchete | gira e trava com impacto | oferta/promoção |
| Vitrine | vira em 3D, specs no verso | tech, eletro, produto |
| Ficha | números sobem contando | fitness, tech, automotivo |
| Luxo | brilho dourado varrendo | joia, moda, alto padrão |
| Glitch | RGB split + scanlines | tech |
| Editorial | revista, wipe + serif | moda |
| AntesDepois | divisória deslizante | estética, beleza, reforma |
| Depoimento | prova social com estrelas | qualquer |
| Combo | grade de itens + preços | lanchonete, menu, catálogo |
| Cupom | ticket com código | promoção |
| Countdown | relógio de urgência | vaga limitada, "só hoje" |

## 10. Formatos (aspect ratios)

Pesquisa de mercado (specs Instagram/Meta 2026):

- **9:16 — 1080×1920** — Story / Reels. Formato vertical de tela cheia. É o
  principal para anúncio hoje.
- **4:5 — 1080×1350** — Feed (master recomendado). Retrato que ocupa mais o
  feed que o quadrado.
- **1:1 — 1080×1080** — quadrado. Bom coringa/runner-up.
- Boas práticas: manter curto (≤30s) e **legenda queimada no vídeo** (a maioria
  assiste sem som) — o motor já grava todo texto no quadro, então já cumpre.

**Já verificado:** os modelos são *format-agnostic* — o layout ancora nas bordas
(topo/rodapé) e centraliza o miolo, então trocar a dimensão da composição
(1080×1920, 1080×1350) adapta sozinho, sem reescrever o template. O servidor só
passa `width/height` conforme o formato pedido.

## 11. Benchmark — como os concorrentes fazem

Referência de ferramentas que transformam foto do cliente em anúncio:

- **Predis.ai** — sobe foto do produto + legenda → gera várias variações de
  anúncio (estático e vídeo), escolhendo template, música e animação de texto
  automaticamente; tem brand kit e agenda publicação. É o modelo mais próximo do
  nosso: foto + dados → peça pronta, por créditos.
- **AdCreative.ai** — foco em criativos de anúncio + "Product Photoshoot AI"
  (transforma foto simples do produto em foto de estúdio, 100+ presets de
  estilo). Inspira a Camada 1 Premium (melhorar a foto por IA).
- **Canva** — controle manual total: templates, brand kit, redimensionar entre
  formatos, exportar. Inspira a edição pelo usuário (campos + prévia) e o
  resize entre 1:1 / 4:5 / 9:16.

O que copiar para o Echo: (a) foto → variações automáticas por nicho; (b)
multiformato num clique; (c) campos editáveis + prévia; (d) créditos. O que nos
diferencia: **render local sem custo de API** nos planos grátis/Pro — o
concorrente cobra crédito por geração; nós só cobramos quando há IA de verdade.

---

**Resumo em uma linha:** o nicho define o *estilo*, o sorteio de
layout+trilha+cor mata a repetição, os dados da loja garantem unicidade, o
número de cenas (1 ou 3) define o plano e a dimensão define o formato (1:1 / 4:5
/ 9:16) — tudo renderizado por código, com custo zero de API nos planos grátis e
Pro.
