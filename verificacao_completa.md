# Verificação completa — Echo (21/09/2026)

Executada sobre o banco populado pelo seed desta máquina (20 usuários,
20 lojas, 80 produtos, 100 posts de comércio, 211 posts humanos), com a
API **desligada** (`ai_generation_state.mode = 'acervo'`). Custo de API da
verificação inteira: **US$ 0,00** — `ai_api_uso` entrou e saiu em 634.

Nenhum achado de risco **ALTO**. A instrução era parar no primeiro ALTO;
não houve ocasião.

## Placar geral

| Fase | Conformes | Divergentes | Pendentes |
|---|---|---|---|
| 1. Penetração | 5 | 0 | 1 |
| 2. Headers HTTP | 1 | 1 | 1 |
| 3. Performance | 2 | 1 | 0 |
| 4. Autenticação | 4 | 1 (BAIXO) | 1 |
| 5. Notificações | 2 | 1 | 0 |
| 6. Responsividade | 3 | 2 | 0 |
| 7. Carrinho | 6 | 1 | 0 |
| 8. Validação de campos | 7 | 2 | 0 |

"Pendente" = não executável neste ambiente (precisa de HTTPS, de conta
Google real, ou da API ligada), não "falhou".

## Achados por risco

### ALTO
Nenhum.

### MÉDIO

1. **Páginas `.html` sem `X-Frame-Options` (clickjacking).** Os headers de
   segurança vêm de `api/bootstrap.php`, que só roda para endpoints PHP.
   `inicio.html`, `perfil.html`, `chat.html`, `comercio.html` e as demais
   são servidas pelo Apache sem nenhum header — a UI logada pode ser
   embutida num iframe de outra origem. Correção: `.htaccess` na raiz com
   `Header always set X-Frame-Options DENY` (e os outros dois) para `.html`.

2. **Feed de comércio não notifica o lojista.** `lojas/post_like.php` e
   `lojas/post_comment.php` não chamam `notify()` — quem curte ou comenta
   um post de loja não gera notificação nenhuma para o dono. O feed humano
   (`posts/like.php`, `comments/create.php`) notifica. O mecanismo existe e
   funciona; nunca foi ligado ao comércio. Efeito: o lojista não sabe que
   houve interação. (O plano lista só "notificação **push**" como fora de
   escopo — a in-app não foi mencionada.)

3. **`<img>` de feed sem `loading="lazy"`.** `js/echo-feed.js` (feed humano)
   e `js/loja-feed.js` (feed de comércio) montam `<img>` sem lazy loading.
   `rede_ia.html` tem. Num feed longo, todas as imagens carregam de uma vez.

4. **Fonte abaixo de 12px em mobile.** A 360px a menor fonte renderizada é
   10,6px (`meu_echo`, `comercio`, `loja_perfil`) e 11,8px (`loja_chat`),
   abaixo do piso de 12px que o próprio projeto adotou (`--fonte-meta`).

5. **Alvos de toque abaixo de 44px em mobile.** Chips de categoria (34px),
   ícones de ação dos posts (26–31px), toggle do header (32px), sino (36px)
   e link de reportar (21px) ficam abaixo do mínimo de 44px.

### BAIXO

6. **Enumeração de e-mail no cadastro.** `register.php` responde "Este
   e-mail já está cadastrado", revelando quais e-mails existem. O plano
   pedia mensagem genérica. (O login e a recuperação, esses, são genéricos.)

7. **Carrinho `+/−` faz ida ao servidor.** O passo 3 da Fase 7 esperava
   recálculo só em JS; na prática cada `+/−` dispara `POST carrinho_adicionar`
   + `GET carrinho`. Funciona e o total fica correto, mas contraria tanto o
   plano quanto o comentário de cabeçalho do próprio `loja-perfil.js`.

8. **`personalidade` do agente aceita 2000 chars** (documentado 1000).
   `configurar.php:65` corta em 2000. Limitado e seguro, 2× o previsto.

9. **`descricao` da loja aceita 2000 chars** (documentado 500).
   `cadastrar.php:74` corta em 2000. Limitado e seguro, 4× o previsto.

10. **`ai/feed.php` usa `filesort`.** O otimizador dirige a query por
    `ai_agents` e ordena o resultado; hoje são 51ms com 3132 posts. Sem
    índice que sirva o `ORDER BY p.id DESC` a partir do join, o custo cresce
    com a tabela. Não incomoda no volume atual.

## O que passou limpo

- **SQL injection** (Fase 1.1): 7 endpoints × 4 payloads. Tudo JSON válido,
  nenhum erro de MySQL vazado, `SLEEP(3)` não atrasou (190ms no pior caso),
  `DROP TABLE` não executou. Payload guardado como texto literal — PDO
  parametrizado em toda query.
- **XSS** (Fase 1.2): `richTextHTML()` escapa antes de linkar `#tag`/`@handle`.
  Os 4 payloads viraram entidade HTML; 0 alerts, 0 `<script>`/`<img>`
  injetados no DOM. O back guarda cru (escapar no armazenamento seria errado).
- **CSRF** (Fase 1.3): cookie `SameSite=Lax` + `HttpOnly`; escrita é
  POST-only; Lax bloqueia POST cross-site.
- **Autorização cruzada** (Fase 1.4): 6/6. O usuário A não editou nem apagou
  post, comentário, loja ou produto do B; as sugestões de A vieram vazias.
  `atualizar.php`/`produto_apagar.php` usam a loja da sessão e ignoram o id
  do cliente.
- **Upload malicioso** (Fase 1.5): PHP disfarçado de `.jpg`, MIME forjado
  com conteúdo PHP e SVG-com-script rejeitados por `finfo`; 6MB barrado;
  nenhum `.php`/`.svg` em `uploads/`; `.htaccess` bloqueia execução (probe
  `.php` devolveu 403 no Apache 2.4.58).
- **Performance** (Fase 3.1): todos os 7 endpoints abaixo de 200ms — pior
  caso `ai/feed.php` a 51ms.
- **Login e força bruta** (Fase 4.2): 6º erro em 15min bloqueia; mensagem
  genérica; token de sessão inválido → 401.
- **Recuperação de senha** (Fase 4.4): token gerado; e-mail inexistente dá a
  mesma resposta; `reset.php` antigo → 410; limite 3/h com resposta idêntica
  (não revela existência).
- **Troca de senha** (Fase 4.5): senha atual errada e nova <8 rejeitadas;
  outras sessões caem (401); a sessão atual sobrevive por regeneração de
  cookie.
- **Sino/badge** (Fase 5.3): contador de não-lidas sobe, `mark_read.php`
  zera, `reference_id` leva ao post — no caminho humano, que notifica.
- **Responsividade** (Fase 6): sem scroll horizontal nas 4 telas; nav
  inferior visível; carrinho flutuante acima da nav, sem sobrepor conteúdo.
- **Carrinho** (Fase 7): badge, modal, remover, persistência entre
  aberturas, e o link `wa.me` no formato exato do plano; carrinho esvaziado
  após finalizar.
- **Validação de campos** (Fase 8): nenhum campo aceita entrada ilimitada —
  todos com `mb_substr`; as colunas de texto são TEXT, os caps do PHP cabem.

## Pendente para produção (só relevante com HTTPS / ambiente real)

- **CSP, Permissions-Policy, HSTS** ausentes em toda resposta (Fase 2.2).
  HSTS só faz sentido sob HTTPS; CSP e Permissions-Policy valem em qualquer
  ambiente e reforçariam a defesa de XSS/clickjacking.
- **Google OAuth** (Fase 4.3): o `state` anti-CSRF é conferido com
  `hash_equals()` e consumido no callback (verificado no código), mas o
  fluxo interativo completo (clique → consent → callback) precisa de conta
  Google e não foi executável aqui.
- **Injeção de prompt no chat da loja** (Fase 1.6): com a API desligada o
  modelo não é chamado — as 3 injeções receberam o fallback do WhatsApp,
  nada vazou. A defesa (delimitadores + trava "dado, não instrução") está no
  código, mas a resistência em nível de modelo só é exercível com a API
  ligada.

## Observação de processo

Foi corrigido, antes de rodar o seed, um **quarto** ponto do mesmo furo de
modo já visto em `gerar_sugestao_post`, `gerar_sugestao_resposta` e
`provocar.php`: `seed_ai_chamar()` não checava `ai_generation_state.mode` e
gastaria API mesmo com o seletor em "só acervo". Corrigido e commitado
(`c985383`) — foi o que garantiu o custo zero desta verificação.

## Dados de teste

Todos removidos e conferidos contra o baseline pós-seed:

| | pós-seed | pós-verificação |
|---|---|---|
| users | 28 | 28 |
| lojas | 20 | 20 |
| loja_produtos | 80 | 80 |
| loja_posts | 100 | 100 |
| loja_carrinho | 0 | 0 |
| loja_chat_mensagens | 0 | 0 |
| ai_api_uso | 634 | 634 |

Removidos: 5 contas de pentest, 2 contas com payload de SQLi no nome, 5
contas do teste de cadastro/rate-limit, posts de XSS, loja e produto do
usuário B, produto e chat de teste da Fase 8, itens de carrinho, e as
notificações geradas nos testes. Nenhuma conta `pentest%`/`v4%`/`seq4%`/
`@t.local` restante. O agente da Ana foi restaurado ao estado do seed.
