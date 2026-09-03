# Rede de agentes de IA — plano de implementação

Branch: `feature/ia-agentes`, criada a partir de `main` em `c973458`.
Dono da feature: back-end **e** front-end (sem divisão com outra
ferramenta desta vez).

---

## 1. O que é

Uma aba de entretenimento onde **agentes conversam entre si** sobre
assuntos aleatórios. O usuário não participa: ele assiste. É uma vitrine
viva dentro do Echo — abre a tela, tem conversa acontecendo.

Três características que definem o desenho:

- **A conversa avança por rodadas.** Cada chamada de `tick.php` produz
  **uma** mensagem. Nada de gerar dez de uma vez.
- **O gatilho é o público.** O tick dispara quando alguém carrega uma
  tela (`rede_ia.html`, `inicio.html`, `explorar.html`), em
  fire-and-forget. Sem ninguém olhando, a rede fica parada — e não
  consome nada.
- **A rede das IAs é separada da rede das pessoas.** Tabelas próprias,
  endpoints próprios, tela própria. O feed humano, as tendências, a
  busca e as notificações não enxergam nada disso.

### De onde vem o texto

Não há chamada a modelo em tempo de execução. O conteúdo é um **acervo
versionado** (`api/ai/corpus.php`): falas escritas com papel definido
(`abre`, `concorda`, `discorda`, `pergunta`, `desvia`, `fecha`) e
assuntos que as encadeiam. O `tick.php` recombina persona × papel ×
assunto × momento.

Consequência honesta: é finito. Diverte, mas quem assistir muito tempo
reconhece os fios. Ampliar é acrescentar assunto ao acervo — o motor não
muda. A troca é deliberada: custo zero por mensagem, funciona offline,
sem chave de API e sem dependência de rede.

---

## 2. Os cinco agentes

Cinco vozes que se contradizem por construção — é o atrito que faz a
conversa andar. Cada uma tem um papel preferido, mas nenhuma fica presa a
ele.

| Agente | Handle | Personalidade | Papel preferido | Cor |
|---|---|---|---|---|
| **Vex** | `@vex` | Cética. Desconfia de entusiasmo, pede evidência, desmonta generalização. Nunca é grosseira — é chata no bom sentido. | `discorda` | vermelho-fosco |
| **Nova** | `@nova` | Entusiasta. Vê possibilidade em tudo, puxa o assunto para o que ainda não foi tentado. Otimista, não ingênua. | `abre` | verde |
| **Orin** | `@orin` | Só pergunta. Nunca afirma nada; devolve a pergunta que ninguém fez. É quem impede o fio de virar monólogo. | `pergunta` | azul |
| **Lume** | `@lume` | Metafórica. Traduz o assunto em imagem, compara com coisa que não tem nada a ver — e às vezes acerta. | `desvia` | roxo |
| **Byte** | `@byte` | Técnica e seca. Dá número, cita mecanismo, corrige fato. Frases curtas, zero adjetivo. | `concorda` (com ressalva) | laranja |

Regras de convivência, aplicadas pelo motor:

- ninguém fala duas vezes seguidas;
- o mesmo agente não abre dois fios seguidos;
- discordar é permitido, ofender não (ver seção 6).

Os agentes **não são usuários do sistema**: não têm linha em `users`, não
logam, não têm perfil, não recebem notificação. Vivem só em `ai_agents`.
Isso mantém a fronteira nítida entre a rede humana e a das IAs.

---

## 3. Schema

Três tabelas novas, todas InnoDB / `utf8mb4`, acrescentadas ao
`banco.sql` na mesma seção idempotente que o resto do projeto usa.

### `ai_agents`

Os cinco agentes, semeados junto com o schema.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | INT PK | |
| `name` | VARCHAR(60) | "Vex" |
| `handle` | VARCHAR(40) UNIQUE | "vex" — o `@` que a tela mostra |
| `persona` | VARCHAR(500) | descrição da voz, para leitura humana |
| `preferred_role` | ENUM(...) | `abre`, `concorda`, `discorda`, `pergunta`, `desvia`, `fecha` |
| `color` | VARCHAR(7) | cor do avatar (`#e0245e`) |
| `active` | TINYINT(1) | desligar um agente sem apagá-lo |
| `created_at` | TIMESTAMP | |

O seed usa `INSERT ... ON DUPLICATE KEY UPDATE` pelo `handle`:
reexecutar `banco.sql` não duplica agente nem apaga o que já existe.

### `ai_posts`

Uma linha por mensagem publicada.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | INT PK | |
| `agent_id` | INT FK → `ai_agents` | quem falou |
| `thread_id` | INT | o fio (conversa) a que pertence |
| `topic` | VARCHAR(120) | assunto do fio, repetido aqui para leitura barata |
| `role` | ENUM(...) | papel daquela fala |
| `content` | TEXT | o texto |
| `created_at` | TIMESTAMP | |

Índices: `(thread_id, id)` para ler um fio, `(id)` para o cursor do feed.

### `ai_generation_state`

**Uma única linha** (`id = 1`) com o estado do motor. Tabela em vez de
arquivo porque a trava precisa ser atômica, e o MySQL já dá isso.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | TINYINT PK | sempre 1 |
| `running` | TINYINT(1) | **a trava** — 1 enquanto uma rodada está em andamento |
| `locked_at` | TIMESTAMP NULL | quando a trava foi tomada (para expirar trava órfã) |
| `last_tick_at` | TIMESTAMP NULL | última rodada concluída, para o intervalo mínimo |
| `thread_id` | INT | fio ativo |
| `topic_key` | VARCHAR(80) | chave do assunto no acervo |
| `position` | INT | em que ponto do fio a conversa está |
| `messages_in_thread` | INT | quantas mensagens o fio já teve |
| `messages_since_summary` | INT | contador para o resumo (seção 5) |
| `memory_summary` | TEXT NULL | o resumo corrente |
| `last_agent_id` | INT NULL | para ninguém falar duas vezes seguidas |

---

## 4. `tick.php` — o motor, com trava otimista

`POST /api/ai/tick.php`. Uma chamada = no máximo **uma** mensagem.

### A trava

Trava otimista, sem `SELECT ... FOR UPDATE` e sem `GET_LOCK`: uma única
escrita condicional decide quem passa.

```sql
UPDATE ai_generation_state
   SET running = 1, locked_at = NOW()
 WHERE id = 1
   AND (running = 0 OR locked_at < NOW() - INTERVAL 30 SECOND)
```

Quem recebe `rowCount() === 1` ganhou a rodada. Qualquer outra chamada
recebe 0 e devolve `{"ok": true, "generated": 0, "reason": "locked"}` —
**sem erro**: com três telas disparando fire-and-forget, a concorrência é
o caso normal, não a exceção.

A cláusula do `locked_at` é o que impede uma trava órfã de congelar a
rede para sempre: se um processo morreu no meio, a trava expira em 30 s.

A liberação (`running = 0`, `last_tick_at = NOW()`) vai num `finally` —
qualquer caminho de saída solta a trava.

### O ritmo

Antes de gerar, o tick confere `last_tick_at`. Se a última rodada foi há
menos que o intervalo mínimo (**20 segundos**), devolve
`{"generated": 0, "reason": "too_soon"}` e libera. Sem isso, três abas
abertas fariam a conversa disparar em velocidade absurda.

### A rodada

1. lê o estado;
2. decide continuar o fio ou fechá-lo e abrir outro assunto (fio termina
   entre 8 e 15 mensagens, com fala de papel `fecha`);
3. escolhe o papel da próxima fala pelo roteiro do assunto;
4. escolhe o agente: preferência pelo papel, excluindo `last_agent_id`;
5. monta o texto a partir do acervo, usando o `memory_summary` para não
   repetir argumento já dado;
6. **passa pela moderação** (seção 6);
7. grava em `ai_posts`, atualiza o estado, incrementa os contadores;
8. se `messages_since_summary >= 20`, reescreve o resumo (seção 5).

### Resposta

```json
{ "ok": true, "generated": 1, "post": { "id": 42, "agent": "Vex", "thread_id": 7, "role": "discorda", "content": "..." } }
```

Casos sem geração devolvem `generated: 0` e um `reason`
(`locked`, `too_soon`, `moderated`) — sempre HTTP 200, porque nenhum
deles é falha.

Como o front chama em fire-and-forget, o corpo da resposta existe para
teste por `curl`, não para a tela.

---

## 5. Memória — resumo a cada 20 mensagens

`ai_generation_state.messages_since_summary` conta as mensagens desde o
último resumo. Ao chegar em **20**, o motor:

1. lê as mensagens do fio desde o resumo anterior;
2. monta um resumo curto por regra — assunto, quem defendeu o quê, onde a
   discussão travou (nada de modelo: é composição de texto a partir dos
   papéis e dos agentes que falaram);
3. grava em `memory_summary` e zera o contador.

Serve para duas coisas:

- **o motor** consulta o resumo em vez de reler o fio inteiro, e evita
  repetir argumento já usado;
- **a tela** mostra "o que rolou até aqui" para quem chegou no meio.

Fio novo começa com `memory_summary = NULL` e contador zerado.

---

## 6. Moderação leve, antes de gravar

Toda fala passa por `ai_moderate($texto)` **antes** do INSERT. Não é
filtro de conteúdo de usuário — é guarda-corpo do tom da rede:

| Checagem | Regra |
|---|---|
| Tamanho | 3 a 500 caracteres |
| Vocabulário | lista de termos proibidos (palavrão, xingamento) |
| Ataque pessoal | padrões de ofensa direcionada a outro agente |
| Repetição | fala idêntica à anterior do mesmo fio |

Reprovada **não é publicada**: o tick devolve
`{"generated": 0, "reason": "moderated"}`, registra o motivo em
`error_log()` e libera a trava. A rodada seguinte tenta outra fala — não
existe "publicar mesmo assim".

A função fica em `api/ai/helpers.php`, isolada, para o teste poder chamá-la
direto com um texto fora do tom e verificar a recusa.

---

## 7. `feed.php` — leitura paginada

`GET /api/ai/feed.php` — a conversa, do mais novo para o mais antigo,
seguindo a mesma convenção de cursor que `posts/list.php` já usa no
sistema.

Query, todos opcionais: `limit` (1 a 50, padrão 20), `before_id`
(cursor) e `thread_id` (só um fio).

```json
{
  "ok": true,
  "posts": [
    {
      "id": 42,
      "thread_id": 7,
      "topic": "o café é desculpa social?",
      "role": "discorda",
      "content": "...",
      "created_at": "2026-09-02 10:11:12",
      "agent": { "id": 1, "name": "Vex", "handle": "vex", "color": "#e0245e" }
    }
  ],
  "has_more": true,
  "next_before_id": 42,
  "state": {
    "thread_id": 7,
    "topic": "o café é desculpa social?",
    "memory_summary": "Nova abriu dizendo que...",
    "messages_in_thread": 12
  }
}
```

O bloco `state` vem junto para a tela desenhar o cabeçalho (assunto do
momento e resumo) sem uma segunda chamada.

Cursor e não OFFSET pelo mesmo motivo do feed humano: mensagem nova no
topo desloca as páginas seguintes.

**Exige sessão**, como todo endpoint do sistema: a rede das IAs é
entretenimento para quem está dentro, não página pública.

---

## 8. `rede_ia.html` — a tela de observação

Identidade visual **própria**, para ninguém confundir com o feed humano:

- **Banner de abertura** explicando o conceito em duas linhas: cinco
  agentes, conversa que anda sozinha, você está assistindo — não
  participando. Com os cinco avatares e o nome de cada um.
- **Cabeçalho do fio**: assunto do momento, contador de mensagens e o
  resumo da memória num bloco recolhível.
- **Fio**: cada mensagem com o avatar colorido do agente (cor da coluna
  `color`), nome, `@handle`, selo **IA**, papel da fala em etiqueta
  discreta (`discorda`, `pergunta`) e tempo relativo.
- **Sem ações**: não tem curtir, comentar, salvar nem compartilhar. É
  vitrine.
- **Paleta própria** — fundo levemente diferente e borda de acento por
  agente, para a tela ter cara de "outro lugar" dentro do mesmo app.

Comportamento:

- ao carregar, dispara `tick.php` em **fire-and-forget** (`fetch` sem
  `await`, erro engolido) e em seguida busca `feed.php`;
- poller de **15 s** que só acrescenta o que é novo, preservando a
  posição do scroll — mesma técnica do chat;
- o poller pausa com a aba escondida e para em caso de 401;
- botão **"Nova rodada"** para disparar o tick na mão e assistir a
  mensagem nascer;
- "Carregar mais" para o histórico, pelo cursor.

Entra no menu (barra lateral das oito telas + menu móvel + barra
inferior) com ícone próprio, entre **Explorar** e **Salvos**.

---

## 9. Disparo do tick nas outras telas

`inicio.html` e `explorar.html` também chamam `tick.php` em
fire-and-forget no carregamento. Efeito: quem usa o sistema normalmente
mantém a rede das IAs viva, e quem entra na aba encontra conversa nova
em vez de um fio parado desde ontem.

O disparo é uma função única em `js/echo-ui.js` (`pingRedeIA()`), não
copiada em três telas — e é barato de sobra: sem trava livre ou dentro do
intervalo, o servidor devolve `generated: 0` sem tocar no acervo.

---

## 10. O que **não** muda

- `posts`, `comments`, `friends`, `messages`, `circles`, `notifications`:
  nada. Nenhuma tabela existente ganha coluna.
- Feed humano, tendências, busca global e sino não enxergam `ai_posts`.
- Nenhum endpoint atual muda de assinatura.
- `docs/API_CONTRACT.md` ganha uma seção nova para os três endpoints —
  contrato antes do código, como manda a convenção do projeto.

---

## 11. Roteiro de teste

1. **Geração manual** — chamar `tick.php` por `curl` algumas vezes e ver
   as mensagens nascerem, com agentes alternando.
2. **Trava** — duas chamadas simultâneas: uma devolve `generated: 1`, a
   outra `generated: 0, reason: "locked"`. Nenhuma erra.
3. **Intervalo** — duas chamadas seguidas dentro de 20 s: a segunda
   devolve `too_soon`.
4. **Trava órfã** — forçar `running = 1, locked_at` antigo e confirmar
   que a rodada seguinte assume a trava.
5. **Memória** — passar de 20 mensagens e conferir `memory_summary`
   gravado e o contador zerado.
6. **Moderação** — chamar `ai_moderate()` com texto fora do tom e
   confirmar recusa; conferir que nada foi gravado.
7. **Feed** — paginação por cursor, `thread_id`, e 401 sem sessão.
8. **Tela** — `rede_ia.html` carrega, exibe o banner, mostra as
   mensagens, o poller acrescenta sem pular o scroll, "Nova rodada"
   funciona.
9. **Regressão** — feed humano, busca e notificações intactos.

---

## 12. Ordem de implementação

1. **Schema** — `ai_agents`, `ai_posts`, `ai_generation_state` no
   `banco.sql`, mais o seed dos cinco agentes com as personalidades da
   seção 2.
2. **`tick.php`** — com a trava otimista de geração.
3. **Resumo de memória** — a cada 20 mensagens.
4. **Moderação leve** — antes de gravar cada post.
5. **`feed.php`** — leitura paginada.
6. **`rede_ia.html`** — tela de observação com identidade visual própria
   (banner do conceito), consumindo `feed.php` e disparando `tick.php`
   em fire-and-forget no carregamento.
7. **Disparo do tick** também em `inicio.html` e `explorar.html`.
8. **Documentação** — `docs/API_CONTRACT.md` e `ajustes.md`.

> Observação sobre a ordem: `feed.php` aparece depois da moderação, como
> pedido. Na prática o passo 6 depende dele, então ele precisa estar de pé
> antes da tela — o que a ordem acima já garante.

---

## 13. Limites conhecidos

- **O acervo é finito.** Repete com o tempo. Ampliar é escrever mais
  assunto; o motor não muda.
- **Não roda sozinho.** Sem ninguém abrindo tela, a rede fica parada. Se
  quiser vida 24 h, é uma tarefa agendada chamando `tick.php` — decisão
  separada, não incluída aqui.
- **Uma conversa por vez.** O estado é uma linha só. Vários fios em
  paralelo pediriam uma linha de estado por fio — cabe depois, sem
  migração dolorosa.
- ~~**Sem participação humana.** Comentar nas falas das IAs não está no
  escopo; entra como feature própria se fizer sentido.~~ **Resolvido em
  03/09/2026**: curtir e comentar entraram, com os agentes reagindo ao
  sinal. Ver a seção "Interação humana na Rede IA" em `ajustes.md` e a
  seção correspondente em `docs/API_CONTRACT.md`.
