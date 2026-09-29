# Roadmap v2 — Echo

Fonte da verdade do progresso deste trabalho longo. Se a conversa for
resumida, **releia este arquivo e o `git log` antes de continuar**.

Regras do trabalho (definidas pelo dono do projeto, 25/09/2026):

- Cada parte/fase: testes → commit(s) → push em `feature/videos-ia` →
  marcar aqui como concluída → começar a próxima sozinho.
- Parar e chamar o dono só se: um teste falhar sem solução; precisar de
  decisão dele; ou **antes da primeira chamada paga a qualquer API (FAL ou
  Claude) em cada fase**.
- Nunca mexer no `main`. Nunca pôr chave de API no código nem no git.

Custos: os valores em US$ abaixo são **estimativas de ordem de grandeza** para
planejar. Antes da primeira chamada paga de cada fase, o custo unitário real
é conferido na tabela de preços atual do provedor (FAL / Anthropic) e
mostrado ao dono junto com o pedido de autorização.

---

## Parte A — Fila, travados, tempo máximo, config, fontes, limpeza

- [x] **Concluída** (25/09/2026), exceto o que está em "Pendências" abaixo.

Commits: `e5184f3` protocol_whitelist · `3a7f7b9` fila global · `189b4cf`
travados · `aab22ae` uploads do bundle · `77014a1` tempo máximo · `5984dd6`
db_config · `c347af6` Windows/Linux · `cda63cc` fontes locais.

- **Fila global** do motor (`max_renders`, `na_fila`, `iniciado_em`,
  `video_fila_despachar()` com `GET_LOCK`, `posicao_fila` no front).
- **Travados**: 10 min em `gerando` → erro, processos encerrados pela marca
  `echo_render_v{id}_`, próximo despachado; CLI `api/video/limpar_travados.php`.
- **Tempo máximo** no `render.js` (`render_timeout_s`, padrão 480 s, teto 540 s).
- **Config**: `db_config.php` fora do git; disparo Windows/Linux;
  `-protocol_whitelist file` no ffmpeg do motor; `test_db.php` apagado.
- **Fontes locais** (Anton/Poppins em `motor/public/fonts`), vídeo idêntico.
- **Limpeza**: bundle copia só as fotos do job; sem uso há 7 dias sai; `nul` apagado.

Pendências da Parte A:

- [ ] Teste de ponta a ponta **no navegador** (gerar → Meus vídeos →
      publicar → toca no feed): precisa do dono fazer o login do usuário de
      teste (o assistente não digita senha em formulário).
- [ ] Trocar `redirect_uri` para `http://127.0.0.1:8080/api/auth/google_callback.php`
      — **só depois** do dono confirmar que cadastrou o 8080 no Google Console.
      Depois disso, propor remover o `Listen 8123` do `httpd-echo.conf`.
- [ ] Teste "render sem internet": o dono desliga a rede e roda o comando do
      relatório (o assistente não pode desligar a rede da própria sessão).

---

## Parte B — Este roadmap

- [x] **Concluída** (25/09/2026).

---

## Fase 1 — Motor: 4 intenções em vez de 13 modelos na tela

- [ ] Concluída

**O que muda.** O Canvas deixa de mostrar 13 modelos e passa a mostrar 4
intenções:

| Intenção | Modelos |
|---|---|
| Mostrar meu produto | Flash, Vitrine, Luxo, Glitch, Editorial |
| Contar uma história | Historia, Depoimento, Ficha |
| Fazer uma promoção | Cupom, Countdown, Manchete, Combo |
| Mostrar resultado | AntesDepois |

O sistema escolhe o modelo dentro da intenção por **nicho + sorteio**, sem
repetir o último modelo da mesma loja naquela intenção. Botão "gerar outra
versão" (mesma intenção e campos, outro modelo). Link "escolher modelo
manualmente" abre a lista antiga. Nenhum modelo é apagado.

**Arquivos/tabelas.** `api/video/motor_catalogo.php` (campo `intencao` em cada
modelo + pesos por nicho), `api/video/modelos.php` (devolve `intencoes`),
`api/video/marketing.php` (aceita `intencao` em vez de `modelo`; escolhe no
servidor), `js/canvas-video.js`, `docs/API_CONTRACT.md`. Sem tabela nova: o
"último modelo da loja" sai de `videos_gerados` (`loja_id`, `modelo`).

**Riscos.** Campos diferentes por modelo: a intenção precisa de um conjunto
de campos que sirva a todos os modelos dela (ou o formulário muda quando o
sorteio escolhe) — decidir campos comuns por intenção; campos extras de um
modelo ficam opcionais. Intenção com 1 modelo (AntesDepois) não tem "outra
versão" de modelo — varia só estilo/trilha.

**Custo de API.** Zero (motor local).

**Como testar.** Catálogo com `intencao` em todos os 13; 20 sorteios
seguidos da mesma loja/intenção nunca repetem o anterior; "outra versão"
gera modelo diferente; manual continua funcionando; render de uma peça por
intenção pela fila.

---

## Fase 2 — Fila de tarefas única

- [ ] Concluída

**O que muda.** Tabela `tarefas` (`tipo`, `payload` JSON, `status`,
`tentativas`, `run_at`, `iniciado_em`, `erro`, datas) e trabalhador CLI
`php api/tarefas/trabalhador.php`, rodado pelo Agendador de Tarefas do
Windows a cada minuto (o comando exato vai no relatório). O render do motor
vira o tipo `render_motor`, mantendo `max_renders` (vagas por tipo), tempo
máximo e limpeza de travados. Post agendado = tarefa `publicar_post` com
`run_at`.

**Arquivos/tabelas.** `banco.sql` (tabela `tarefas`), `api/tarefas/`
(`helpers.php`, `trabalhador.php`), `api/video/helpers.php` e
`marketing.php`/`processar.php` passam a enfileirar em `tarefas`
(`videos_gerados` continua sendo o registro que o front consulta),
`docs/API_CONTRACT.md`.

**Riscos.** Duas fontes de verdade (tarefa × `videos_gerados`) — o status do
vídeo passa a ser derivado/sincronizado num ponto só. Trabalhador rodando em
paralelo com ele mesmo (Agendador dispara de novo antes de terminar) —
trava por `GET_LOCK`. Migração dos pedidos que estiverem `na_fila` na hora.

**Custo de API.** Zero.

**Como testar.** Os testes da fila da Parte A de novo, agora pelo
trabalhador (inclusive matar o node no meio); tarefa com `run_at` no futuro
não roda antes; tentativas e erro gravados; dois trabalhadores simultâneos
não pegam a mesma tarefa.

---

## Fase 3 — Base do FAL

- [ ] Concluída

**O que muda.** `api/ai/fal.php`: enviar para a fila do FAL e consultar o
resultado. Chave `fal_api_key` em `ai_config.php` (documentada no
`.example`; **o dono coloca a chave**). Toda chamada: bloqueada no modo
`acervo`; confere e desconta `ai_credits` pela tabela de custos no config;
registra em `ai_api_uso` (provider `fal`, custo em US$). Remove a integração
direta com o Kling (o FAL tem o Kling); Pexels e Coverr ficam.

**Arquivos/tabelas.** `api/ai/fal.php`, `api/ai/ai_config.example.php`,
`ai_api_uso` (colunas `provider` e `custo_usd` se faltarem), `users.ai_credits`,
`api/video/helpers.php` e `video_config.example.php` (sai Kling),
`docs/API_CONTRACT.md`.

**Riscos.** Desconto de crédito sem resultado (falha do FAL depois de
cobrar) — reservar antes, estornar se falhar. Poll longo preso em requisição
web — o resultado é consultado pela fila de tarefas (Fase 2). Remover o
Kling quebra quem tem `kling_api_key` configurada — documentar.

**Custo de API.** Só o teste de fumaça: 1 chamada barata (ex.: geração de
imagem pequena), ordem de US$ 0,01–0,05. **PARADA antes da primeira
chamada real.**

**Como testar.** Sem chave → erro claro; modo `acervo` → bloqueia sem
chamar; crédito insuficiente → bloqueia sem chamar; com chave (após
autorização) → 1 chamada, crédito descontado, linha em `ai_api_uso`.

---

## Fase 4 — Foto melhorada + kit de campanha + Claude diretor de arte

- [ ] Concluída

**O que muda.**
- Foto melhorada via FAL (modelo de edição), com checagem de fidelidade
  (estrutura/cor comparadas com a original), salva em coluna separada; o
  lojista escolhe original ou melhorada.
- Claude diretor de arte (API do Claude com visão): recebe a foto e os dados
  da loja e devolve JSON com problemas da foto, 3–4 prompts de cena, prompt
  de movimento para vídeo, chamada e CTA. O conteúdo da loja entra como
  **dado**, nunca como instrução.
- Kit de campanha: 3–4 imagens do mesmo produto em cenários diferentes (mesa
  de madeira com luz quente, fundo liso na cor da marca estilo app de
  delivery, lifestyle, close), sempre preservando o produto, passando pela
  checagem de fidelidade, **sem texto nem preço** (quem escreve é o motor).
  O lojista aprova antes de usar.
- O modelo Historia pode usar as imagens do kit como suas 3 cenas.

**Arquivos/tabelas.** Tabela `kit_imagens` (loja, produto, origem, arquivo,
fidelidade, aprovada), coluna de foto melhorada em `loja_produtos`,
`api/ai/diretor_arte.php`, `api/ai/fal.php`, endpoints de kit em
`api/video/`, `motor/src/Historia.jsx` (só aceitar as imagens como props —
aparência dos outros modelos intacta), front do Canvas.

**Riscos.** Modelo de edição altera o produto (cor/forma) — a checagem de
fidelidade reprova e não mostra; texto "vazando" na imagem gerada — prompt
negativo + checagem; injeção de prompt pelo nome/descrição da loja — dados
delimitados e tratados como dado.

**Custo de API.** Por produto: diretor de arte 1 chamada Claude com imagem
(ordem de US$ 0,01–0,03) + 1 foto melhorada + 3–4 imagens do kit (ordem de
US$ 0,03–0,10 cada no FAL) → ordem de **US$ 0,15–0,50 por kit**. Testes:
1–2 kits. **PARADA antes da primeira chamada paga.**

**Como testar.** JSON do diretor validado por schema; foto com produto
alterado de propósito → fidelidade reprova; kit aprovado alimenta a
Historia; nenhum texto nas imagens; crédito e `ai_api_uso` corretos.

---

## Fase 5 — Agente da loja: permissões, modo sombra e relatório

- [ ] Concluída

**O que muda.**
- Os 4 níveis globais (`user_agents.autonomia` 0–3) viram **atalhos** que
  preenchem uma tabela de permissões por tipo de ação: responder mensagens,
  responder comentários, postar, curtir/comentar em outros perfis, gastar
  créditos, criar promoção — cada uma `nunca` / `sugerir` / `sozinho`.
- Ferramentas (tool use da API do Claude) com limites checados no PHP:
  cupom só até o desconto máximo do dono; teto diário de curtidas e
  comentários; segurança e pagamento fora do alcance do agente em qualquer
  nível.
- Mensagens de terceiros entram no prompt como dado (proteção contra
  manipulação).
- Botão de emergência: desliga todos os agentes (global) e o de cada usuário.
- Modo sombra: o agente decide sem executar e registra; a tela mostra "você
  concordou com X de Y decisões" e sugere liberar a autonomia daquela ação.
- Relatório do fim do dia: o que o agente fez + perguntas que não soube
  responder; as respostas do dono viram base de conhecimento da loja.
- Todo texto do agente para outras pessoas é identificado como resposta do
  agente.
- Modelo Haiku nas ações de rotina.

**Arquivos/tabelas.** `user_agent_permissoes`, `user_agent_decisoes`
(sombra), `loja_conhecimento`, flag global de emergência;
`api/user_agent/*`, `api/lojas/agente_configurar.php`, `api/ai/helpers.php`
(tool use), telas do agente.

**Riscos.** Agente agindo fora da permissão — toda ferramenta checa a
permissão no PHP, nunca confia no modelo; custo descontrolado — teto diário
por agente; migração dos níveis atuais para a tabela sem mudar
comportamento de quem já usa.

**Custo de API.** Haiku por ação de rotina: ordem de US$ 0,001–0,005 por
decisão; testes: algumas dezenas de decisões → centavos. **PARADA antes da
primeira chamada paga.**

**Como testar.** Cada permissão × cada modo; cupom acima do teto recusado
pelo PHP; mensagem com instrução maliciosa não muda o comportamento;
emergência desliga na hora; sombra não executa nada; relatório do dia
gerado; texto sai marcado como do agente.

---

## Fase 6 — Agente propõe anúncios sozinho

- [ ] Concluída

**O que muda.** Eventos (produto novo; produto com muitas visitas e sem
venda; data comemorativa) geram uma sugestão de anúncio já renderizada pelo
motor (via fila de tarefas), que o lojista aprova com 1 clique.

**Arquivos/tabelas.** `anuncio_sugestoes` (loja, evento, video_id, status),
detectores de evento em tarefas agendadas, tela de sugestões.

**Riscos.** Enxurrada de sugestões — teto por loja/semana e dedupe por
evento; render pesado sem ninguém pedir — respeita a fila e `max_renders`.

**Custo de API.** Zero no render; texto da chamada pode usar Haiku (centavos).

**Como testar.** Cada evento dispara 1 sugestão; aprovar publica; recusar não
volta a sugerir o mesmo evento.

---

## Fase 7 — Premium e acervo

- [ ] Concluída

**O que muda.**
- Premium: trecho de 5 s animado por IA (image-to-video no FAL, 768p) a
  partir de uma imagem aprovada do kit, usado como cena do motor.
- Acervo dos agentes da Rede IA: Pexels/Coverr/Pixabay + um lote único de
  clipes gerados uma vez e reaproveitados. Agentes não geram vídeo pago.
- Fora do escopo: vídeo de IA para usuários comuns; "melhorar" com IA um
  vídeo já renderizado.

**Riscos.** Custo por clipe alto — só Premium, crédito reservado antes;
clipe desfigura o produto — mesma checagem de fidelidade no 1º quadro.

**Custo de API.** Image-to-video 5 s 768p: ordem de US$ 0,25–0,50 por clipe.
Lote do acervo: **custo total mostrado ao dono antes de gerar**. **PARADA
antes da primeira chamada paga.**

**Como testar.** Clipe entra como cena do motor no formato certo; sem
crédito Premium → bloqueado; acervo servido sem nenhuma chamada paga nova.

---

## Fase 8 — Métricas para o TCC

- [ ] Concluída

**O que muda.** Painel com: tempo médio de resposta ao cliente com e sem
agente; % de sugestões aprovadas; anúncios gerados e publicados; custo de
API por loja.

**Arquivos/tabelas.** Consultas sobre `messages`, `user_agent_decisoes`,
`anuncio_sugestoes`, `videos_gerados`, `loja_posts`, `ai_api_uso`;
`api/metricas/`, página do painel.

**Riscos.** Métrica enviesada por dados de teste/seed — filtrar contas de
teste; consultas pesadas — agregados por dia.

**Custo de API.** Zero.

**Como testar.** Números do painel conferidos contra consultas SQL à mão num
conjunto de dados conhecido.
