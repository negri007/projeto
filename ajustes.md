# Ajustes do Upgrade — Sistema Echo

Estado do upgrade em **28/08/2026**. Serve para retomar o trabalho sem
precisar reler todo o histórico.

Documentos relacionados:

- `docs/API_CONTRACT.md` — fonte única da verdade sobre os endpoints.
  Todo formato de resposta citado aqui está detalhado lá.
- `docs/PLANO_UPGRADE_ECHO.md` — plano geral do upgrade, dividido por
  dono ([BACKEND — Claude Code] / [FRONTEND — Antigravity]).

Divisão de trabalho: o back-end (`api/`, `banco.sql`) é do Claude Code; o
front-end (`.html`, `css/`, `js/`) é do Antigravity.

---

## Convenções firmadas (valem para todo endpoint novo)

1. Identidade vem **sempre** da sessão (`require_login()` /
   `current_user_id()`). Nenhum endpoint aceita `email` ou `user_id` do
   cliente como identidade.
2. Resposta de sucesso é sempre um **objeto** com `"ok": true` mais uma
   chave nomeada (`posts`, `friends`, `circles`, `messages`, ...), nunca
   um array na raiz.
3. Resposta de erro é sempre `{ "error": string }`. Sem sessão: HTTP 401
   com `{ "error": "Não autenticado." }`.
4. Todo item de lista traz o `user_id` do dono. O front compara com o
   `user.id` de `GET /api/auth/me.php` — nunca por `email` ou `name`.
5. Ids e contadores são inteiros JSON; datas são strings
   `"YYYY-MM-DD HH:MM:SS"`; campos opcionais vêm `null`, nunca `""`.
6. PDO com prepared statements em toda query. A mensagem real da exceção
   nunca vai para a resposta, só para log.
7. Toda chamada `fetch` do front inclui `credentials: "same-origin"`.

---

## O que já foi feito

### Sessão e autenticação — concluído

- `api/auth/session.php` (novo) — `current_user_id()`,
  `current_user_name()`, `require_login()`, `start_user_session()`,
  `destroy_user_session()`. Cookie `httponly` + `samesite=Lax`, e
  `session_regenerate_id()` no login contra fixação de sessão.
- `api/auth/login.php` — passou a abrir sessão de verdade.
- `api/auth/logout.php` e `api/auth/me.php` (novos).

### Correção de segurança: `api/auth/reset.php` — concluído

A rota trocava a senha de qualquer conta recebendo só `email` +
`new_pass`, sem token e sem sessão: sequestro de conta para quem soubesse
um e-mail cadastrado. Agora responde HTTP 410 com
`{ "error": "Rota desativada. ..." }`. Nenhuma tela do front a chamava. O
fluxo correto (`forgot_password.php` + `reset_password.php`) ainda está
pendente.

### Banco de dados — concluído

`banco.sql` corrigido: coluna `status` em `friends`, e as tabelas
`circle_members`, `circle_messages`, `notifications` e
`password_resets`.

### Módulos migrados (todos testados ponta a ponta)

| Módulo | Endpoints | Observação |
|---|---|---|
| `posts/` | 5 | `create`, `delete`, `like`, `share`, `list` |
| `comments/` | 2 | `list` deixou de devolver array na raiz |
| `friends/` | 10 | 9 migrados + `remove.php` novo |
| `circles/` | 5 | + `helpers.php` |
| `circle_messages/` | 2 | correção de segurança, ver abaixo |

**`friends/`** — chave canônica do módulo é `user_id` (o outro usuário).
Renomes na resposta: `sender_id`, `receiver_id` e `id` viraram todos
`user_id`. `search.php` ganhou o campo `status`
(`none` / `pending_sent` / `pending_received` / `friends`) para o front
escolher o botão. `remove.php` foi criado porque o front já chamava a
rota e o arquivo não existia. Corrigido também um `OR ... AND` sem
parênteses em `reject.php` que apagava amizade **aceita** na direção
inversa, e a busca passou a excluir o próprio usuário e a escapar os
curingas `%` e `_`.

**`circles/`** — o dono do círculo vem como `user_id` (coluna `owner_id`
no banco), com `is_owner` e `member_count` prontos. `list.php` antes só
devolvia `WHERE owner_id = eu`, então quem era só membro via a lista
vazia; agora traz os dois casos. `list_members.php` passou a devolver
`circle` + `owner` + `members`, e o dono não aparece em `members`.
Os três endpoints de membro não tinham **nenhuma** checagem: qualquer um
podia adicionar/remover gente em círculo alheio e listar membros só
chutando `circle_id`. Agora acesso = dono ou membro, gestão = só dono, e
membro comum só remove a si mesmo. Regra nova: só amigos do dono podem
ser adicionados.

**`circle_messages/`** — tratado como prioridade por ser falha ativa.
`list.php?circle_id=N` devolvia a conversa inteira de qualquer círculo
**sem estar logado**, bastando variar o `N`; `send.php` aceitava `email`
no corpo, permitindo escrever se passando por qualquer usuário. Agora os
dois usam `require_login()` mais a mesma regra de acesso de `circles/`.
Quem perde o acesso para de ler e escrever na chamada seguinte.

### Front-end já migrado pelo Antigravity

`amigos.html`, `chat.html` e `circulos.html` para o módulo `friends/`.

---

## O que falta fazer

### 1. `messages/` — chat privado (BACKEND, prioridade máxima)

Mesma classe de falha do `circle_messages/`, ainda aberta:
`list.php?me=X&friend=Y` lê a conversa privada de qualquer par de
usuários, sem sessão, só passando os dois e-mails na URL. `send.php`
recebe `sender` e `receiver` no corpo, permitindo enviar mensagem se
passando por outra pessoa.

A fazer: `require_login()` nos dois; `list.php` passa a receber só
`friend` (id do outro usuário), com o remetente vindo da sessão;
`send.php` recebe `friend_email` ou `user_id` mais `body`. Avaliar exigir
amizade aceita para poder conversar. Formato de resposta seguindo a
convenção, com `user_id` em cada mensagem para o front decidir o balão
"é meu".

### 2. `profile/` (BACKEND)

`get.php` recebe `email` na query e `update.php` recebe `email` no
`$_POST` — ou seja, hoje dá para editar o perfil de qualquer usuário.
Migrar os dois para sessão.

### 3. Notificações (BACKEND)

Nada foi implementado ainda. A tabela `notifications` existe em
`banco.sql`, mas **nenhum** módulo grava nela — nem `posts/`, nem
`comments/`, nem `friends/`. Ficou de propósito para um passe único em
todos os módulos, para o contrato não ficar inconsistente.

A fazer:

- Helper de criação de notificação (nunca notificar o próprio autor).
- Gravar em: curtida, comentário, compartilhamento, pedido de amizade,
  amizade aceita, mensagem recebida.
- `api/notifications/list.php` e `api/notifications/mark_read.php`
  (a pasta `api/notifications/` ainda não existe). Formato já descrito em
  `docs/API_CONTRACT.md`.

### 4. Recuperação de senha por e-mail (BACKEND)

- `api/auth/forgot_password.php` — gera token, grava só o **hash** em
  `password_resets`, envia o link por e-mail.
- `api/auth/reset_password.php` — valida token e troca a senha.
- PHPMailer + SMTP.
- A resposta de `forgot_password.php` é sempre a mesma, exista o e-mail
  ou não, para não vazar quais endereços estão cadastrados.
- `reset.html` já existe no front e espera `reset.html?token=...`.
- Depois que o fluxo estiver em produção, `api/auth/reset.php` pode ser
  apagado.

### 5. `api/auth/register.php` (BACKEND)

Ainda não foi revisado. Confirmar validação de e-mail duplicado, hash de
senha e formato de resposta segundo a convenção.

### 6. Front-end pendente (Antigravity)

- `circulos.html` — migrar para o novo formato de `circles/`.
- `circle_chat.html` — migrar para o novo formato de `circle_messages/`.
  Hoje `loadMessages()` só olha `data.messages`; com acesso negado vem
  `{"error": ...}` e a tela mostra "Nenhuma mensagem no círculo ainda",
  que é a mensagem errada. Precisa tratar `data.error`.
- `chat.html` e `perfil.html` — depois que `messages/` e `profile/`
  estiverem migrados.
- Interface do sino de notificações, depois dos endpoints.

---

## Ambiente de teste

XAMPP em `C:\xampp`. O MySQL costuma estar parado; subir com
`C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini`.
Banco `banco`, usuário `root` sem senha.

Os testes ponta a ponta foram feitos com o servidor embutido do PHP
(`php -S 127.0.0.1:8123` na raiz do projeto) e `curl` com cookie jar por
usuário, com quatro contas de teste (`alice`, `bruno`, `carla` e `diego`
em `@echo.local`, senha `senha123`).

Roteiro mínimo de teste para cada módulo migrado: 401 em todos os
endpoints sem sessão; caminho feliz; tentativa de agir sobre dado de
outro usuário; método HTTP errado; e os limites de validação.
