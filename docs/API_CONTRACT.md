# Contrato de API — Sistema Echo (fonte única da verdade)

Este documento é a referência que Antigravity (front-end) e Claude Code
(back-end) seguem em paralelo. Se algo aqui precisar mudar, mude **neste
arquivo primeiro** e avise a outra ferramenta — nunca implemente um desvio
silencioso de um lado só.

## Sessão / autenticação
Depois do login, sessão via cookie PHP padrão (`PHPSESSID`). O front nunca
envia `email`/`user_id` em nenhuma chamada. Toda chamada `fetch` do front
inclui `credentials: "same-origin"`.

**POST /api/auth/login.php**
Request: `{ "email": string, "password": string }`
Response 200: `{ "success": true, "user": { "id": int, "name": string, "email": string } }`
Response 200 (erro): `{ "error": string }`

**POST /api/auth/register.php**
Request: `{ "name": string, "email": string, "password": string }`
Response 200: `{ "success": true, "user": { "id": int, "name": string, "email": string } }`
Response 200 (erro): `{ "error": string }`
Efeito colateral: **abre a sessão** — quem se cadastra já entra logado, o
front manda direto para `inicio.html` em vez de voltar ao login.
Validações: nome até 100 caracteres, e-mail válido até 150, senha entre
8 e 72 caracteres. E-mail duplicado devolve
`{ "error": "Este e-mail já está cadastrado." }` (a corrida entre a
checagem e o INSERT também cai nessa mensagem, pela chave única).

**POST /api/auth/logout.php**
Request: `{}`
Response 200: `{ "ok": true }`

**GET /api/auth/me.php**
Response 200 (logado): `{ "authenticated": true, "user": { "id": int, "name": string, "email": string } }`
Response 401 (não logado): `{ "authenticated": false }`

Nem `login.php` nem `register.php` aplicam `trim()` na senha: espaço no
começo ou no fim faz parte dela. O front também não deve aparar.

**Freio de força bruta no login.** `login.php` conta as tentativas
erradas em `login_attempts`, por e-mail **e** por IP:

- 5 erros no mesmo e-mail, ou 20 no mesmo IP, dentro de 15 minutos,
  bloqueiam novas tentativas por 15 minutos a partir do último erro;
- durante o bloqueio, **até a senha certa é recusada** — senão o freio
  não freia nada;
- um login bem-sucedido apaga o histórico de erros daquele e-mail.

Response 429: `{ "error": "Muitas tentativas de login. Tente de novo em 15 minutos." }`
O front deve mostrar essa mensagem como qualquer outro erro; o status
429 distingue "travado" de "senha errada", se quiser tratar diferente.

## Endpoints existentes que mudam de assinatura
Todos deixam de receber `email`/`user_id`. Erro por falta de sessão em
qualquer um: HTTP 401, `{ "error": "Não autenticado." }`.

| Endpoint | Antes | Depois |
|---|---|---|
| POST /api/posts/create.php | `email`, `content`, `image` | `content`, `image` |
| POST /api/posts/delete.php | `email`, `post_id` | `post_id` |
| POST /api/posts/like.php | `email`, `post_id` | `post_id` |
| POST /api/posts/share.php | `email`, `post_id` | `post_id` |
| GET /api/posts/list.php | `email` (query) | nenhum |
| POST /api/comments/create.php | `email`, `post_id`, `body` | `post_id`, `body` |
| POST /api/friends/send.php | `email`, `friend_email` | `friend_email` ou `user_id` |
| POST /api/friends/accept.php | `email`, `sender` | `user_id` |
| POST /api/friends/reject.php | `email`, `friend_email` | `user_id` |
| POST /api/friends/cancel.php | `email`, `friend_email` | `user_id` |
| POST /api/friends/remove.php | (não existia) | `user_id` |
| GET /api/friends/list.php | `email` (query) | nenhum |
| GET /api/friends/list_pending.php | `email` (query) | nenhum |
| GET /api/friends/sent_list.php | `email` (query) | nenhum |
| GET /api/friends/suggestions.php | `email` (query) | nenhum |
| GET /api/friends/search.php | `email`, `q` (query) | `q` (query) |
| GET /api/messages/list.php | `me`, `friend` | `friend` |
| POST /api/messages/send.php | `email`, `friend_email`, `body` | `body` + `user_id` ou `friend_email` |
| POST /api/circles/create.php | `email`, `name`, `description` | `name`, `description` |
| GET /api/circles/list.php | `email` (query) | nenhum |
| GET /api/circles/list_members.php | `circle_id` (query) | `circle_id` (query) |
| POST /api/circles/add_member.php | `email`, `circle_id`, `friend_email` | `circle_id` + `user_id` ou `friend_email` |
| POST /api/circles/remove_member.php | `email`, `circle_id` | `circle_id` + `user_id` ou `friend_email` |
| GET /api/circle_messages/list.php | `circle_id` (query) | `circle_id` (query) |
| POST /api/circle_messages/send.php | `email`, `circle_id`, `message` | `circle_id`, `message` |
| GET /api/profile/get.php | `email` (query) | nenhum, ou `user_id` (query) |

Endpoints criados em 31/08/2026 (não existiam antes, então não têm
coluna "Antes"):

| Endpoint | Parâmetros |
|---|---|
| POST /api/posts/edit.php | `post_id`, `content` |
| POST /api/comments/delete.php | `comment_id` |
| POST /api/circles/delete.php | `circle_id` |
| GET /api/messages/conversations.php | nenhum |
| POST /api/messages/mark_read.php | `user_id` ou `friend_email` |
| POST /api/profile/update.php | `email`, `name`, `bio` | `name`, `bio`, `avatar` |

**Todos os módulos estão migrados.** Nenhum endpoint do sistema aceita
mais `email` ou `user_id` do cliente como identidade.

## Convenção de resposta (vale para todos os módulos)

Regras que valem para posts, comentários, amigos, mensagens e círculos —
inclusive os que ainda não foram migrados:

1. **Todo item de lista traz o `user_id` do dono.** O front compara esse
   campo com o `user.id` devolvido por `GET /api/auth/me.php` para decidir
   se mostra ações de dono (apagar, editar). Nunca comparar por `email` ou
   por `name`.
2. Toda resposta de sucesso é um **objeto**, nunca um array na raiz, e
   traz `"ok": true` mais uma chave nomeada com os dados
   (`posts`, `comments`, `friends`, `messages`, ...).
3. Toda resposta de erro é `{ "error": string }`.
4. Sem sessão: HTTP 401 + `{ "error": "Não autenticado." }`.
5. Ids e contadores são inteiros JSON (sem aspas); datas são strings
   `"YYYY-MM-DD HH:MM:SS"`; campos opcionais vêm `null`, não `""`.

## Formato de resposta — posts (implementado e testado)

**GET /api/posts/list.php** — feed ordenado por `id` DESC.

Query, todos opcionais: `limit` (1 a 50, padrão 20), `before_id`
(cursor) e `user_id` (só os posts daquele autor — é o que o perfil usa).
```json
{
  "ok": true,
  "posts": [
    {
      "id": 5,
      "user_id": 6,
      "content": "texto do post",
      "image": "img_68f0c1a2b3.png",
      "created_at": "2026-08-28 14:09:18",
      "edited_at": null,
      "name": "Alice Teste",
      "email": "alice.teste@echo.local",
      "avatar": null,
      "comment_count": 3,
      "like_count": 1,
      "share_count": 1,
      "liked_by_me": 0
    }
  ],
  "has_more": true,
  "next_before_id": 5
}
```
- `user_id` — dono do post. Mostrar o botão de apagar somente quando
  `post.user_id === me.user.id`.
- `image` — nome do arquivo em `uploads/`, ou `null`.
- `avatar` — foto do autor em `uploads/`, ou `null`.
- `edited_at` — `null` se nunca editado; o front mostra "editado".
- `liked_by_me` — `1` ou `0`, referente ao usuário da sessão.

**Paginação por cursor, não por OFFSET.** `next_before_id` é o id do
último post da página; a próxima chamada manda esse valor em
`before_id`. `has_more` diz se ainda existe página; quando é `false`,
`next_before_id` vem `null`.

O motivo de ser cursor: com OFFSET, um post novo no topo desloca todas
as páginas seguintes, e o item da borda aparece repetido ou some. Com
`before_id`, cada página é um recorte estável.

Mudança (31/08/2026): antes `list.php` devolvia **a tabela inteira** de
posts, sem limite. Chamadas antigas sem `limit` continuam funcionando,
mas passam a receber 20 posts em vez de todos.

**POST /api/posts/create.php** (multipart/form-data: `content`, `image`)

Mudança de formato (31/08/2026): antes devolvia só `{ "ok": true }`.
Agora devolve o post criado, no mesmo formato de `list.php`, para o front
inserir no topo do feed sem recarregar a lista.
```json
{ "ok": true, "post": { "id": 7, "user_id": 1, "content": "texto", "image": null, "created_at": "2026-08-31 14:26:45", "name": "Alice Teste", "email": "alice.teste@echo.local", "comment_count": 0, "like_count": 0, "share_count": 0, "liked_by_me": 0 } }
```
Erros: `{ "error": "Envie texto ou uma imagem." }`,
`{ "error": "Post é longo demais (máx. 5000 caracteres)." }`,
`{ "error": "Formato de imagem inválido." }` (aceita jpg, jpeg, png, gif, webp),
`{ "error": "Imagem é grande demais (máx. 5 MB)." }`,
`{ "error": "Erro ao salvar a imagem." }`

O tipo da imagem é decidido pelo **MIME real** do arquivo (`finfo`), não
pela extensão que o cliente informa — extensão é texto escolhido por
quem envia, e um `.png` pode conter qualquer coisa.

**POST /api/posts/edit.php** — `{ "post_id": int, "content": string }`
Só o autor edita. Sucesso devolve o post atualizado, no formato de
`list.php`, já com `edited_at` preenchido.
```json
{ "ok": true, "post": { "id": 21, "edited_at": "2026-08-31 15:16:02", "...": "demais campos iguais a list.php" } }
```
Erros: `{ "error": "Dados inválidos." }`,
`{ "error": "Post não encontrado ou não é seu." }` (post inexistente e
post de outra pessoa devolvem o mesmo, de propósito),
`{ "error": "O post não pode ficar vazio." }` (texto vazio num post sem
imagem), `{ "error": "Post é longo demais (máx. 5000 caracteres)." }`,
`{ "error": "Método inválido." }`.

**POST /api/posts/delete.php** — `{ "post_id": int }`
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Dados inválidos." }`,
`{ "error": "Post não encontrado ou não é seu." }`

**POST /api/posts/like.php** — `{ "post_id": int }` (alterna curtida)
Sucesso: `{ "ok": true, "liked": true }` ou `{ "ok": true, "liked": false }`
Erros: `{ "error": "Dados inválidos." }`, `{ "error": "Post não encontrado." }`

**POST /api/posts/share.php** — `{ "post_id": int }`
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Post inválido." }`, `{ "error": "Post não encontrado." }`
O compartilhamento passou a gravar o autor (`post_shares.user_id`).

## Formato de resposta — comentários (implementado e testado)

Atualizado em 31/08/2026: cada comentário passou a trazer `avatar` e
`can_delete`, e ganhou `comments/delete.php`.

**`can_delete`** vem resolvido pelo servidor — é `true` para o autor do
comentário **e** para o dono do post (moderar a própria publicação é
esperado). O front só mostra o botão quando vier `true`; quem decide de
verdade é o back.

**POST /api/comments/delete.php** — `{ "comment_id": int }`
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Dados inválidos." }`,
`{ "error": "Comentário não encontrado." }` (também quando existe mas
não é seu nem do seu post), `{ "error": "Método inválido." }`.
Efeito colateral: se aquele autor não tiver mais nenhum comentário no
post, a notificação de `comment` correspondente é removida.

`comments/create.php` passou a limitar o corpo em 2000 caracteres:
`{ "error": "Comentário é longo demais (máx. 2000 caracteres)." }`.

**GET /api/comments/list.php?post_id=int**

Atenção, mudança de formato: antes devolvia um **array na raiz**; agora
devolve um objeto com `ok` + `comments`, seguindo a convenção acima.
```json
{
  "ok": true,
  "comments": [
    {
      "id": 1,
      "post_id": 5,
      "user_id": 7,
      "body": "Comentario do Bruno",
      "created_at": "2026-08-28 14:09:32",
      "name": "Bruno Teste",
      "email": "bruno.teste@echo.local"
    }
  ]
}
```
Ordem: `created_at` ASC. Erro: `{ "error": "post_id é obrigatório." }`

**POST /api/comments/create.php** — `{ "post_id": int, "body": string }`
Sucesso: devolve o comentário já pronto para renderizar sem recarregar a
lista:
```json
{ "ok": true, "comment": { "id": 1, "post_id": 5, "user_id": 7, "body": "...", "created_at": "2026-08-28 14:09:32", "name": "Bruno Teste", "email": "bruno.teste@echo.local" } }
```
Erros: `{ "error": "post_id e comentário são obrigatórios." }`,
`{ "error": "Post não encontrado." }`, `{ "error": "Método inválido." }` (só POST).

## Formato de resposta — amigos (implementado e testado)

Módulo migrado em 28/08/2026. Nenhum endpoint de `friends/` aceita mais
`email` do cliente como identidade; o usuário vem sempre da sessão.

**Chave canônica do módulo: `user_id`.** Em toda lista deste módulo,
`user_id` é o id do **outro usuário** (o amigo, o solicitante ou o
sugerido) — nunca o id da linha da tabela `friends`. As quatro ações que
recebem um alvo (`accept`, `reject`, `cancel`, `remove`) recebem esse
mesmo `user_id` de volta, então o front pode passar o item da lista
direto, sem tradução.

Atenção, renomes de campo: `list_pending.php` devolvia `sender_id`,
`sent_list.php` devolvia `receiver_id`, e `list.php`, `search.php` e
`suggestions.php` devolviam `id`. **Todos passaram a se chamar
`user_id`.** Os nomes antigos não existem mais na resposta.

### Objeto de usuário

Toda lista do módulo devolve itens com esta base:
```json
{ "user_id": 2, "name": "Bruno Teste", "email": "bruno.teste@echo.local", "avatar": null }
```
`avatar` é o nome do arquivo em `uploads/`, ou `null`.

### Listas

**GET /api/friends/list.php** — amigos confirmados, ordem `name` ASC.
```json
{
  "ok": true,
  "friends": [
    {
      "user_id": 2,
      "name": "Bruno Teste",
      "email": "bruno.teste@echo.local",
      "avatar": null,
      "friends_since": "2026-08-28 15:55:38"
    }
  ]
}
```

**GET /api/friends/list_pending.php** — pedidos que EU recebi e ainda não
respondi. Ordem: mais recentes primeiro.
```json
{
  "ok": true,
  "requests": [
    {
      "user_id": 1,
      "name": "Alice Teste",
      "email": "alice.teste@echo.local",
      "avatar": null,
      "requested_at": "2026-08-28 15:55:38"
    }
  ]
}
```
`user_id` = quem mandou o pedido. É esse valor que vai para `accept.php`
e `reject.php`.

**GET /api/friends/sent_list.php** — pedidos que EU enviei e ainda estão
pendentes. Mesmo formato, chave `sent`; `user_id` = destinatário, e é o
valor que vai para `cancel.php`.
```json
{
  "ok": true,
  "sent": [
    {
      "user_id": 3,
      "name": "Carla Teste",
      "email": "carla.teste@echo.local",
      "avatar": null,
      "requested_at": "2026-08-28 15:55:38"
    }
  ]
}
```

**GET /api/friends/suggestions.php** — até 15 usuários sem nenhuma relação
comigo (nem amizade, nem pedido pendente em qualquer direção), ordem
`name` ASC. Chave `users`, objeto de usuário base, sem campos extras.

**GET /api/friends/search.php?q=texto** — até 20 usuários cujo `name` ou
`email` contenha `q`, ordem `name` ASC. O próprio usuário logado nunca
aparece. `q` vazio devolve `{ "ok": true, "users": [] }` (não é erro).
`%` e `_` digitados são tratados como texto literal, não como curinga.
```json
{
  "ok": true,
  "users": [
    {
      "user_id": 2,
      "name": "Bruno Teste",
      "email": "bruno.teste@echo.local",
      "avatar": null,
      "status": "pending_sent"
    }
  ]
}
```
`status` é a relação do usuário logado com aquele usuário:

| valor | significado | ação que o front deve oferecer |
|---|---|---|
| `none` | sem relação | Adicionar (`send.php`) |
| `pending_sent` | eu mandei, ele não respondeu | Cancelar (`cancel.php`) |
| `pending_received` | ele mandou, eu não respondi | Aceitar / Recusar |
| `friends` | amizade confirmada | Remover (`remove.php`) |

### Ações

**POST /api/friends/send.php** — `{ "friend_email": string }` ou
`{ "user_id": int }` (envie um dos dois; `user_id` tem precedência).
```json
{ "ok": true, "status": "pending", "auto_accepted": false }
```
Se o outro usuário já tinha um pedido pendente para mim, a amizade é
fechada na hora e a resposta é
`{ "ok": true, "status": "accepted", "auto_accepted": true }`.
Erros: `{ "error": "Usuário não encontrado." }`,
`{ "error": "Você não pode adicionar a si mesmo." }`,
`{ "error": "Vocês já são amigos." }`,
`{ "error": "Pedido já enviado." }`,
`{ "error": "Método inválido." }` (só POST),
`{ "error": "Erro ao enviar solicitação." }`.

**POST /api/friends/accept.php** — `{ "user_id": int }`, onde `user_id` é
quem mandou o pedido (vem de `list_pending.php`).
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Usuário não encontrado." }`,
`{ "error": "Solicitação não encontrada." }` (não existe pedido pendente
daquele usuário para mim — inclui tentar aceitar pedido de outra pessoa),
`{ "error": "Método inválido." }`, `{ "error": "Erro ao aceitar solicitação." }`.

**POST /api/friends/reject.php** — `{ "user_id": int }`, mesmo `user_id`
de `accept.php`. Apaga o pedido pendente. Mesmas respostas e erros de
`accept.php` (erro genérico: `{ "error": "Erro ao recusar solicitação." }`).

**POST /api/friends/cancel.php** — `{ "user_id": int }`, o destinatário do
pedido que EU enviei (vem de `sent_list.php`). Apaga o pedido pendente.
Sucesso: `{ "ok": true }`. Erros iguais, genérico
`{ "error": "Erro ao cancelar solicitação." }`.

**POST /api/friends/remove.php** — **endpoint novo** (o front já chamava
esta rota, mas o arquivo não existia). `{ "user_id": int }`, o amigo a ser
removido. Desfaz a amizade nos dois sentidos.
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Usuário não encontrado." }`,
`{ "error": "Amizade não encontrada." }` (não há amizade aceita entre os
dois), `{ "error": "Método inválido." }`,
`{ "error": "Erro ao desfazer amizade." }`.

### Regras do modelo de amizade

- A amizade é **uma única linha** em `friends`, com `status` `pending` ou
  `accepted`; ela pode estar gravada em qualquer uma das duas direções.
  Por isso `list.php` e `remove.php` olham os dois sentidos.
- Depois de `remove.php` ou `reject.php` a linha some, então um novo
  `send.php` entre os mesmos usuários volta a funcionar normalmente.
- Um usuário só aceita ou recusa pedidos endereçados a ele, e só cancela
  pedidos que ele mesmo enviou. Tentar agir sobre a relação de outra
  pessoa devolve `{ "error": "Solicitação não encontrada." }`.

## Formato de resposta — círculos (implementado e testado)

Módulo migrado em 28/08/2026. Nenhum endpoint de `circles/` aceita mais
`email` do cliente como identidade; o usuário vem sempre da sessão.

Duas chaves canônicas:

- **`circle_id`** identifica o círculo nas requisições; na resposta, o
  círculo se chama `id` (igual a `posts`).
- **`user_id`** identifica a pessoa. No objeto de círculo, `user_id` é o
  **dono** (coluna `owner_id` no banco), seguindo a regra 1 da convenção
  — o front compara `circle.user_id === me.user.id`, ou usa o
  `is_owner` já pronto. Nas ações de membro, `user_id` é o membro alvo.

### Objeto de círculo

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Equipe Echo",
  "description": "time do projeto",
  "created_at": "2026-08-28 16:41:21",
  "member_count": 2,
  "is_owner": true
}
```
- `user_id` — dono do círculo. **Não existe campo `owner_id` na
  resposta.**
- `description` — `null` quando não informada, nunca `""`.
- `member_count` — membros em `circle_members`, **sem contar o dono**.
- `is_owner` — booleano, relativo ao usuário da sessão. É o que decide
  se o front mostra "Gerenciar".

### Objeto de membro

```json
{ "user_id": 2, "name": "Bruno Teste", "email": "bruno.teste@echo.local", "avatar": null, "joined_at": "2026-08-28 16:41:36" }
```
O objeto `owner` tem os mesmos campos, menos `joined_at`.

### Listas

**GET /api/circles/list.php** — sem parâmetros. Devolve os círculos que
eu criei **mais** aqueles em que fui incluído como membro, ordem `name`
ASC.

Mudança de comportamento: antes só devolvia `WHERE owner_id = eu`, então
quem era só membro via a lista vazia. Agora vê o círculo com
`is_owner: false`.
```json
{
  "ok": true,
  "circles": [
    {
      "id": 1,
      "user_id": 1,
      "name": "Equipe Echo",
      "description": "time do projeto",
      "created_at": "2026-08-28 16:41:21",
      "member_count": 2,
      "is_owner": true
    }
  ]
}
```

**GET /api/circles/list_members.php?circle_id=int** — só o dono e os
membros conseguem ler. Ordem: `name` ASC.

Mudança de formato: antes devolvia só `members` com `name` e `email`.
Agora devolve também o círculo e o dono, e cada membro traz `user_id`.
```json
{
  "ok": true,
  "circle": {
    "id": 1,
    "user_id": 1,
    "name": "Equipe Echo",
    "description": "time do projeto",
    "created_at": "2026-08-28 16:41:21",
    "member_count": 2,
    "is_owner": true
  },
  "owner": {
    "user_id": 1,
    "name": "Alice Teste",
    "email": "alice.teste@echo.local",
    "avatar": null
  },
  "members": [
    {
      "user_id": 2,
      "name": "Bruno Teste",
      "email": "bruno.teste@echo.local",
      "avatar": null,
      "joined_at": "2026-08-28 16:41:36"
    }
  ]
}
```
**O dono não aparece em `members`** — ele vem em `owner`. `members` pode
vir `[]` num círculo recém-criado.

Erro: `{ "error": "Círculo não encontrado." }` — mesma resposta para
círculo inexistente e para círculo de terceiros, de propósito: quem não
participa não descobre que o círculo existe. `circle_id` ausente ou zero
cai nesse mesmo erro.

### Ações

**POST /api/circles/create.php** — `{ "name": string, "description": string }`
(`description` é opcional). O dono é o usuário da sessão.
Sucesso: devolve o círculo pronto para o front inserir na lista sem
recarregar.
```json
{ "ok": true, "circle": { "id": 1, "user_id": 1, "name": "Equipe Echo", "description": "time do projeto", "created_at": "2026-08-28 16:41:21", "member_count": 0, "is_owner": true } }
```
Erros: `{ "error": "Nome do círculo é obrigatório." }`,
`{ "error": "Nome do círculo é longo demais (máx. 100 caracteres)." }`,
`{ "error": "Descrição é longa demais (máx. 255 caracteres)." }`,
`{ "error": "Método inválido." }` (só POST),
`{ "error": "Erro ao criar círculo." }`.

**POST /api/circles/add_member.php** —
`{ "circle_id": int, "user_id": int }` ou
`{ "circle_id": int, "friend_email": string }`. Envie um dos dois
identificadores do membro; `user_id` tem precedência se vierem juntos.
**Só o dono adiciona**, e **só amigos podem ser adicionados** (amizade
aceita entre o dono e o convidado) — é a mesma lista que preenche o
select do modal.

Sucesso: devolve o membro pronto para renderizar.
```json
{ "ok": true, "member": { "user_id": 2, "name": "Bruno Teste", "email": "bruno.teste@echo.local", "avatar": null, "joined_at": "2026-08-28 16:41:36" } }
```
Erros: `{ "error": "Círculo não encontrado." }` (inclui círculo de
terceiros), `{ "error": "Apenas o dono do círculo pode gerenciar membros." }`,
`{ "error": "Usuário não encontrado." }`,
`{ "error": "O dono já faz parte do círculo." }`,
`{ "error": "Só é possível adicionar amigos ao círculo." }`,
`{ "error": "Esse usuário já está no círculo." }`,
`{ "error": "Método inválido." }`, `{ "error": "Erro ao adicionar membro." }`.

**POST /api/circles/remove_member.php** — mesmo corpo de
`add_member.php`. O dono remove qualquer membro; um membro comum só pode
remover **a si mesmo** (sair do círculo). O dono não pode ser removido.
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Círculo não encontrado." }`,
`{ "error": "Usuário não encontrado." }`,
`{ "error": "Apenas o dono do círculo pode gerenciar membros." }`
(membro tentando remover outra pessoa),
`{ "error": "O dono não pode ser removido do círculo." }`,
`{ "error": "Membro não encontrado no círculo." }`,
`{ "error": "Método inválido." }`, `{ "error": "Erro ao remover membro." }`.

**POST /api/circles/delete.php** — `{ "circle_id": int }`
**Só o dono apaga.** Membros e conversa vão junto (`ON DELETE CASCADE`
em `circle_members` e `circle_messages`) — é apagar mesmo, não arquivar.
Sucesso: `{ "ok": true }`
Erros: `{ "error": "Círculo não encontrado." }` (inclui círculo de
terceiros), `{ "error": "Apenas o dono do círculo pode apagá-lo." }`
(membro comum — que pode sair via `remove_member.php`, mas não apagar),
`{ "error": "Método inválido." }`, `{ "error": "Erro ao apagar círculo." }`.

### Regras do modelo de círculo

- O dono **não** tem linha em `circle_members`; a posse vive só em
  `circles.owner_id`. Por isso `member_count` e `members` nunca incluem
  o dono.
- Acesso ao círculo = ser dono **ou** ser membro. Quem não participa
  recebe sempre `{ "error": "Círculo não encontrado." }`, nunca uma
  mensagem que confirme a existência do círculo.
- Apagar um círculo ainda não tem endpoint. `circles.id` é referenciado
  por `circle_members` e `circle_messages` com `ON DELETE CASCADE`,
  então dá para acrescentar `delete.php` depois sem migração de banco.

## Formato de resposta — chat de círculo (implementado e testado)

Módulo migrado em 28/08/2026, **fora da ordem da fila, como correção de
segurança** — ver "Falha corrigida" no fim desta seção.

Os dois endpoints usam exatamente a mesma regra de acesso de `circles/`:
só o dono ou um membro do círculo lê e escreve. Quem não participa recebe
`{ "error": "Círculo não encontrado." }`, a mesma resposta de um círculo
inexistente.

**GET /api/circle_messages/list.php?circle_id=int** — ordem `id` ASC.

Mudança de formato: cada mensagem agora traz `id`, `circle_id` e
`user_id`, que antes não vinham.
```json
{
  "ok": true,
  "messages": [
    {
      "id": 1,
      "circle_id": 1,
      "user_id": 1,
      "message": "ola time",
      "created_at": "2026-08-28 16:55:07",
      "name": "Alice Teste",
      "email": "alice.teste@echo.local"
    }
  ]
}
```
`user_id` é o autor da mensagem. O front decide o balão "é meu" com
`m.user_id === me.user.id` — **nunca por `email`**, conforme a regra 1 da
convenção.

Círculo sem conversa devolve `{ "ok": true, "messages": [] }`.
Erros: `{ "error": "Círculo não encontrado." }` (inclui `circle_id`
ausente, zero ou de círculo do qual não participo),
`{ "error": "Erro ao listar mensagens do círculo." }`.

**POST /api/circle_messages/send.php** —
`{ "circle_id": int, "message": string }`. O autor é o usuário da sessão.
Sucesso: devolve a mensagem já montada, para o front pintar na hora sem
esperar o próximo ciclo do poller.
```json
{ "ok": true, "message": { "id": 2, "circle_id": 1, "user_id": 2, "message": "oi alice", "created_at": "2026-08-28 16:55:07", "name": "Bruno Teste", "email": "bruno.teste@echo.local" } }
```
Atenção ao nome: a chave `message` da **requisição** é o texto (string);
a chave `message` da **resposta** é o objeto da mensagem criada.

Erros: `{ "error": "Círculo não encontrado." }`,
`{ "error": "Mensagem é obrigatória." }` (vazia ou só espaços),
`{ "error": "Mensagem é longa demais (máx. 5000 caracteres)." }`,
`{ "error": "Método inválido." }` (só POST),
`{ "error": "Erro ao enviar mensagem." }`.

### Falha corrigida (28/08/2026)

Antes desta migração, os dois endpoints não tinham sessão nem qualquer
checagem de participação: bastava chamar
`list.php?circle_id=N`, sem estar logado, para ler a conversa inteira de
qualquer círculo — era só variar o `N`. `send.php` aceitava `email` no
corpo, então dava para escrever no chat de qualquer círculo se passando
por qualquer usuário. Corrigido com `require_login()` mais a checagem de
dono-ou-membro.

Perder o acesso é imediato: quem sai do círculo, ou é removido por
`circles/remove_member.php`, deixa de ler e de escrever na chamada
seguinte.

## Formato de resposta — mensagens privadas (implementado e testado)

Módulo migrado em 31/08/2026, fechando a última rota que aceitava
identidade vinda do cliente — ver "Falha corrigida" no fim desta seção.

Regra de acesso dos dois endpoints: **só é possível ler e escrever com um
amigo** (amizade `accepted` em qualquer direção, a mesma que
`GET /api/friends/list.php` devolve). Sem amizade aceita, os dois
respondem `{ "error": "Só é possível conversar com amigos." }` — e o
mesmo vale para amizade só `pending`.

O remetente vem sempre da sessão. Da requisição vem apenas o outro lado
da conversa.

**GET /api/messages/list.php?friend=int** — `friend` é o **id** do outro
usuário (`user_id` é aceito como sinônimo). O parâmetro `me` sumiu.
Ordem: `id` ASC.
```json
{
  "ok": true,
  "friend": {
    "user_id": 2,
    "name": "Bruno Teste",
    "email": "bruno.teste@echo.local",
    "avatar": null
  },
  "messages": [
    {
      "id": 1,
      "user_id": 1,
      "receiver_id": 2,
      "body": "oi bruno",
      "created_at": "2026-08-31 14:08:40",
      "name": "Alice Teste",
      "email": "alice.teste@echo.local"
    }
  ]
}
```
- `user_id` é o **autor** da mensagem; `name` e `email` são os dele. O
  front decide o balão "é meu" com `m.user_id === me.user.id` —
  **nunca por `email`**, conforme a regra 1 da convenção.
- `receiver_id` é o destinatário, útil para marcar lida no futuro.
- `friend` é o interlocutor já pronto para o cabeçalho da conversa
  (nome e avatar), para o front não precisar de uma segunda chamada.
- `avatar` vem `null` quando não há foto, nunca `""`.

Mudança de formato: antes a resposta era um **array na raiz**, com um
campo `sender` que trazia o e-mail. Agora é objeto com `ok`/`friend`/
`messages`, e cada item traz `id`, `user_id` e `receiver_id`. O campo
`sender` não existe mais.

Conversa sem mensagens devolve `{ "ok": true, "friend": {...}, "messages": [] }`.

Erros: `{ "error": "Usuário não encontrado." }` (inclui `friend` ausente,
zero ou id inexistente), `{ "error": "Não é possível conversar consigo mesmo." }`,
`{ "error": "Só é possível conversar com amigos." }`,
`{ "error": "Erro ao listar mensagens." }`.

**POST /api/messages/send.php** —
`{ "user_id": int, "body": string }` ou
`{ "friend_email": string, "body": string }`. Envie um dos dois
identificadores do destinatário; `user_id` tem precedência se vierem
juntos. O remetente é o usuário da sessão.

Sucesso: devolve a mensagem já montada, para o front pintar na hora sem
esperar o próximo ciclo do poller.
```json
{ "ok": true, "message": { "id": 2, "user_id": 2, "receiver_id": 1, "body": "oi alice", "created_at": "2026-08-31 14:08:40", "name": "Bruno Teste", "email": "bruno.teste@echo.local" } }
```
Erros: `{ "error": "Usuário não encontrado." }`,
`{ "error": "Não é possível conversar consigo mesmo." }`,
`{ "error": "Só é possível conversar com amigos." }`,
`{ "error": "Mensagem é obrigatória." }` (vazia ou só espaços),
`{ "error": "Mensagem é longa demais (máx. 5000 caracteres)." }`,
`{ "error": "Método inválido." }` (só POST),
`{ "error": "Erro ao enviar mensagem." }`.

**GET /api/messages/conversations.php** — a lista lateral do chat
inteira em uma chamada: um item por amigo, com a última mensagem e
quantas ainda não foram lidas. Parte dos **amigos**, não das mensagens,
então amigo sem conversa também aparece, pronto para receber a primeira.

Ordem: conversa com mensagem mais recente primeiro; amigos sem conversa
no fim, por nome.
```json
{
  "ok": true,
  "unread_total": 2,
  "conversations": [
    {
      "user_id": 2,
      "name": "Bruno Teste",
      "email": "bruno.teste@echo.local",
      "avatar": null,
      "last_body": "Alice, viu o novo chat?",
      "last_at": "2026-08-31 15:10:22",
      "last_sender_id": 2,
      "last_is_mine": false,
      "unread_count": 1
    }
  ]
}
```
- `unread_total` é a soma de todas as conversas — serve para o contador
  no título da aba.
- `last_is_mine` diz se a última mensagem foi minha, para o front
  prefixar "Você: " na prévia.
- Amigo sem conversa vem com `last_body`, `last_at` e `last_sender_id`
  em `null` e `unread_count` 0.

**POST /api/messages/mark_read.php** — `{ "user_id": int }` ou
`{ "friend_email": string }`. Marca como lidas as mensagens que **aquela
pessoa me mandou**; nunca toca em conversa de terceiros.
Sucesso: `{ "ok": true, "marked": int, "unread_total": int }`
Erros: `{ "error": "Usuário não encontrado." }`, `{ "error": "Método inválido." }`.

`GET /api/messages/list.php` **já marca a conversa como lida** ao ser
chamado — abrir a conversa é o gesto de ler. `mark_read.php` existe para
marcar sem abrir (limpar o badge direto da lista). Cada mensagem passou
a trazer `read_at` (`null` = não lida).

### Falha corrigida (31/08/2026)

Antes desta migração os dois endpoints não tinham sessão nenhuma.
`list.php?me=X&friend=Y` devolvia a conversa privada de **qualquer par de
usuários**, sem estar logado, bastando saber os dois e-mails — que a
própria busca de amigos expõe. `send.php` recebia `sender` e `receiver`
no corpo, então dava para mandar mensagem se passando por qualquer
pessoa. É a mesma classe de falha que já havia sido corrigida em
`circle_messages/`.

Corrigido com `require_login()` mais a exigência de amizade aceita.
Perder o acesso é imediato: desfeita a amizade por
`friends/remove.php`, a conversa deixa de ser legível na chamada
seguinte.

## Formato de resposta — perfil (implementado e testado)

Módulo migrado em 31/08/2026. Antes, `get.php` recebia `email` na query e
`update.php` recebia `email` no `$_POST`: dava para **ler e editar o
perfil de qualquer usuário** trocando o e-mail na requisição. Agora a
identidade vem da sessão.

**GET /api/profile/get.php** — sem parâmetro, devolve o perfil do usuário
logado. Com `?user_id=int`, devolve o perfil daquela pessoa (perfil é
informação pública dentro do sistema; quem pergunta continua vindo da
sessão).
```json
{
  "ok": true,
  "user": {
    "user_id": 1,
    "name": "Alice Teste",
    "email": "alice.teste@echo.local",
    "bio": "Backend do Echo",
    "avatar": null,
    "created_at": "2026-08-28 16:55:06",
    "is_me": true
  },
  "stats": {
    "posts": 0,
    "likes_received": 0,
    "friends": 1,
    "circles": 1
  }
}
```
- O campo da descrição chama **`bio`**, não `about`. (O front lia
  `user.about`, que nunca existiu na resposta — a bio aparecia sempre
  vazia. Corrigido.)
- `avatar` — nome do arquivo em `uploads/`, ou `null`.
- `is_me` — booleano; o front usa para decidir se mostra "Editar perfil".
- `stats.likes_received` conta curtidas **recebidas nos posts da
  pessoa**, não curtidas que ela deu.
- `stats.friends` conta amizades aceitas nas duas direções.
  `stats.circles` soma círculos que ela criou mais aqueles de que
  participa.
- Não existem `followers`/`following`: o modelo de amizade é mútuo, não
  tem lado seguidor. A tela de perfil passou a mostrar "amigos" e
  "círculos".

Erros: `{ "error": "Usuário não encontrado." }`,
`{ "error": "Erro ao buscar perfil." }`.

Para listar os posts de um perfil, use
`GET /api/posts/list.php?user_id=N` — não existe endpoint separado. Foi
assim que a tela de perfil deixou de baixar o feed inteiro para
descartar no cliente o que não era do dono do perfil.

**POST /api/profile/update.php** (multipart/form-data: `name`, `bio`,
`avatar`) — edita **sempre** o usuário da sessão. Sucesso devolve o
perfil atualizado, no mesmo formato de `get.php`.

Erros: `{ "error": "Nome é obrigatório." }`,
`{ "error": "Nome é longo demais (máx. 100 caracteres)." }`,
`{ "error": "Bio é longa demais (máx. 500 caracteres)." }`,
`{ "error": "Formato de imagem inválido. Use jpg, png, gif ou webp." }`,
`{ "error": "Imagem é grande demais (máx. 2 MB)." }`,
`{ "error": "Falha ao enviar a imagem." }`,
`{ "error": "Método inválido." }`, `{ "error": "Erro ao atualizar perfil." }`.

O avatar é validado por MIME real, e o arquivo antigo só é apagado
depois que o UPDATE no banco dá certo.

## Notificações

Implementado e testado em 31/08/2026.

**GET /api/notifications/list.php** — ordem `id` DESC.
Query opcional: `only_unread=1` (só as não lidas) e `limit` (1 a 100,
padrão 50).
```json
{
  "ok": true,
  "unread_count": 3,
  "notifications": [
    {
      "id": 4,
      "type": "share",
      "actor_id": 1,
      "actor_name": "Alice Teste",
      "actor_avatar": null,
      "reference_id": 2,
      "is_read": false,
      "created_at": "2026-08-31 14:26:48"
    }
  ]
}
```
- `unread_count` é o total de não lidas **no servidor** — conta todas,
  não apenas as que couberam no `limit`. É o número do badge do sino.
- `reference_id` é o **post** em `like`, `comment` e `share`; é o **outro
  usuário** em `message`; é `null` em `friend_request` e `friend_accept`.
- `actor_avatar` é o arquivo em `uploads/`, ou `null`.

**POST /api/notifications/mark_read.php**
Request: `{ "notification_id": int }` ou `{ "mark_all": true }`
Response 200: `{ "ok": true, "unread_count": int }`
Erros: `{ "error": "Notificação não encontrada." }` (id inexistente **e**
id de outra pessoa devolvem a mesma coisa), `{ "error": "Método inválido." }`.

### Quando cada notificação é gerada

| Evento | Tipo | Quem recebe | `reference_id` |
|---|---|---|---|
| Curtir um post | `like` | autor do post | id do post |
| Comentar | `comment` | autor do post | id do post |
| Compartilhar | `share` | autor do post | id do post |
| Enviar pedido de amizade | `friend_request` | destinatário | `null` |
| Aceitar amizade | `friend_accept` | quem pediu | `null` |
| Enviar mensagem privada | `message` | destinatário | id do remetente |

Regras:

- **Ninguém é notificado da própria ação.** Curtir o próprio post não
  gera nada.
- Falha ao gravar a notificação **nunca** derruba a ação principal —
  curtir funciona mesmo que a notificação não entre. O erro vai para o
  log do PHP.
- Ações desfeitas apagam o aviso correspondente (`notify_undo`):
  descurtir remove o `like`; recusar, cancelar ou aceitar um pedido
  remove o `friend_request` pendente. O sino não acumula aviso de algo
  que não vale mais.

## Recuperação de senha por e-mail

**POST /api/auth/forgot_password.php**
Request: `{ "email": string }`
Response 200 (sempre): `{ "ok": true, "message": "Se o e-mail existir, um link foi enviado." }`

**POST /api/auth/reset_password.php**
Request: `{ "token": string, "new_password": string }`
Response 200: `{ "ok": true }`
Response 400: `{ "error": string }`

E-mail linka para `reset.html?token=...`.

Implementado e testado em 31/08/2026.

Regras do token: gerado com `random_bytes(32)`; o banco guarda **só o
hash SHA-256** — vazamento do banco não devolve um link utilizável.
Validade de 1 hora, uso único, e um pedido novo invalida os anteriores da
mesma conta. Token inválido, expirado, já usado e ausente devolvem todos
a mesma mensagem, para não virar um oráculo. A senha nova precisa ter
entre 8 e 72 caracteres.

**Envio de e-mail.** `api/auth/mailer.php` tem dois drivers:

- `log` (padrão) — grava a mensagem em `logs/mail.log` e não envia nada.
  É o que roda quando `api/auth/mail_config.php` não existe. O fluxo
  inteiro é testável sem credencial de SMTP: o link com o token aparece
  no arquivo.
- `smtp` — envia de verdade via PHPMailer 6.9.1 (`lib/PHPMailer/`,
  incluído no repositório; o projeto não usa Composer).

Para ativar o envio real, copie `api/auth/mail_config.example.php` para
`api/auth/mail_config.php` e preencha host, usuário e senha do SMTP
(Mailtrap serve para demonstração). **`mail_config.php` está no
`.gitignore`: credencial de SMTP não entra no repositório.** Sem host ou
usuário configurado, o mailer cai para o driver `log` em vez de estourar
erro no meio do fluxo do usuário.

### Rota desativada

**POST /api/auth/reset.php** — **DESATIVADA** (28/08/2026).

Trocava a senha de qualquer conta recebendo apenas `email` + `new_pass`,
sem token e sem sessão — permitia sequestro de conta. Agora responde
sempre HTTP 410 com `{ "error": "Rota desativada. Use /api/auth/forgot_password.php e /api/auth/reset_password.php." }`.
O front não deve chamá-la (hoje nenhuma tela chama). O fluxo válido de
recuperação de senha é `forgot_password.php` + `reset_password.php`.
