# Ajustes do Upgrade — Sistema Echo

Estado do upgrade em **31/08/2026**. Serve para retomar o trabalho sem
precisar reler todo o histórico.

Documentos relacionados:

- `docs/API_CONTRACT.md` — fonte única da verdade sobre os endpoints.
  Todo formato de resposta citado aqui está detalhado lá.
- `docs/PLANO_UPGRADE_ECHO.md` — plano geral do upgrade.

---

## Situação geral

**O upgrade está completo.** Back-end e front-end migrados, com o fluxo
inteiro testado ponta a ponta por `curl` e no navegador.

| Área | Estado |
|---|---|
| Banco de dados (`banco.sql`) | Completo |
| Sessão PHP e autenticação | Completo |
| Migração de todos os endpoints para sessão | Completo |
| Notificações (geração + endpoints + sino) | Completo |
| Recuperação de senha por e-mail | Completo |
| Front-end migrado para o novo contrato | Completo |

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
   `"YYYY-MM-DD HH:MM:SS"`; campos opcionais vêm `null`, não `""`.
6. `$e->getMessage()` nunca vai para a resposta do cliente — só para
   `error_log()`.
7. Upload de arquivo é validado pelo **MIME real** (`finfo`), nunca pela
   extensão informada pelo cliente, e sempre com limite de tamanho.

---

## Falhas de segurança corrigidas

Todas eram exploráveis sem estar logado, só trocando um parâmetro.

| Rota | O que dava para fazer | Correção |
|---|---|---|
| `api/auth/reset.php` | Trocar a senha de qualquer conta com só o e-mail, sem token | Rota desativada (HTTP 410); substituída por `forgot_password.php` + `reset_password.php` |
| `api/circle_messages/list.php` | Ler a conversa de qualquer círculo variando `circle_id` | `require_login()` + checagem de dono-ou-membro |
| `api/circle_messages/send.php` | Escrever em qualquer círculo se passando por qualquer usuário | idem |
| `api/messages/list.php` | Ler a conversa privada de qualquer par de usuários (`?me=X&friend=Y`) | `require_login()` + exigência de amizade aceita |
| `api/messages/send.php` | Enviar mensagem se passando por outra pessoa (`sender` no corpo) | idem |
| `api/profile/get.php` | Ler o perfil de qualquer usuário pelo e-mail | Identidade da sessão; `user_id` opcional e explícito |
| `api/profile/update.php` | **Editar o perfil de qualquer usuário** | Edita sempre o usuário da sessão |
| Todos os módulos | Agir como qualquer usuário mandando o e-mail dele | Sessão PHP em todos |

---

## Back-end

### Sessão e autenticação

`api/auth/session.php` com `require_login()`, `current_user_id()`,
`current_user_name()`, `start_user_session()` (com `session_regenerate_id`
contra fixação de sessão) e `destroy_user_session()`. Cookie `httponly`
e `samesite=Lax`. `login.php`, `logout.php`, `me.php` e `register.php`
seguem o contrato.

`register.php` foi reescrito: valida formato de e-mail, tamanho de nome,
senha de 8 a 72 caracteres, trata a corrida de e-mail duplicado pela
chave única, e **abre a sessão** — quem se cadastra já entra logado.
`login.php` e `register.php` deixaram de aplicar `trim()` na senha.

### Módulos migrados (todos testados ponta a ponta)

| Módulo | Endpoints | Observação |
|---|---|---|
| `posts/` | 5 | + `helpers.php`; `create` devolve o post criado |
| `comments/` | 2 | `list` deixou de devolver array na raiz |
| `friends/` | 10 | 9 migrados + `remove.php` novo |
| `circles/` | 5 | + `helpers.php` |
| `circle_messages/` | 2 | correção de segurança |
| `messages/` | 2 | + `helpers.php`; correção de segurança |
| `profile/` | 2 | + `helpers.php`; correção de segurança |
| `notifications/` | 2 | módulo novo + `helpers.php` |

**`friends/`** — chave canônica é `user_id`. `search.php` tem o campo
`status` (`none` / `pending_sent` / `pending_received` / `friends`) para
o front escolher o botão.

**`circles/`** — no objeto de círculo, `user_id` é o **dono** (coluna
`owner_id` no banco); não existe campo `owner_id` na resposta. O dono não
tem linha em `circle_members`, então `member_count` e `members` não o
incluem — ele vem na chave `owner`. `add_member.php` e
`remove_member.php` aceitam `user_id` **ou** `friend_email`, com
precedência para `user_id`.

**`messages/`** — `list.php` recebe só `friend` (id); `send.php` recebe
`user_id` ou `friend_email` mais `body`. Os dois exigem amizade aceita.
A resposta deixou de ser array na raiz e o campo `sender` (e-mail) sumiu.

**`profile/`** — o campo da descrição chama `bio`, não `about`. Não
existem `followers`/`following`: o modelo de amizade é mútuo. As
estatísticas são `posts`, `likes_received`, `friends` e `circles`.

### Notificações

`api/notifications/helpers.php` com `notify()` e `notify_undo()`.
Gravação nos seis eventos do contrato. Ninguém é notificado da própria
ação, e falha ao gravar nunca derruba a ação principal. Ações desfeitas
(descurtir, recusar/cancelar/aceitar pedido) apagam o aviso
correspondente.

### Recuperação de senha

`forgot_password.php` e `reset_password.php`. Token de 32 bytes, gravado
só como hash SHA-256, validade de 1 hora, uso único, e um pedido novo
invalida os anteriores. A resposta de `forgot_password.php` é sempre a
mesma, exista o e-mail ou não.

`api/auth/mailer.php` tem driver `log` (padrão — grava em
`logs/mail.log`, permite testar sem SMTP) e driver `smtp` (PHPMailer
6.9.1 em `lib/PHPMailer/`, sem Composer). Para envio real, copiar
`api/auth/mail_config.example.php` para `api/auth/mail_config.php` — que
está no `.gitignore`.

---

## Front-end

- **`chat.html`** — conversa identificada por **id**, não por e-mail.
  Balão "é meu" decidido por `user_id`. Trata `data.error` (antes um
  acesso negado virava "nenhuma mensagem"). O poller só acrescenta as
  mensagens novas, preservando a posição do scroll.
- **`circulos.html`** — novo formato de `circles/`. Badge de Dono/Membro,
  contagem de membros, dono listado à parte e não removível. "Gerenciar"
  só aparece para o dono; membro comum vê "Sair". Membros identificados
  por `user_id`.
- **`circle_chat.html`** — círculo vem da URL (`?circle_id=`), o que faz
  link direto funcionar e permite duas abas sem embaralhar estado. Trata
  `data.error` e desabilita o campo quando o acesso é negado.
- **`perfil.html`** — usa `bio` (a bio aparecia sempre vazia porque o
  front lia `about`). Estatísticas passaram a ser amigos e círculos.
  Ganhou upload de avatar.
- **`index.html`** — "esqueci minha senha" e cadastro ligados na API real
  (estavam simulados com `setTimeout`). Cadastro valida os 8 caracteres
  antes de enviar e já entra logado. Parou de gravar `userEmail` no
  `localStorage`.
- **`reset.html`** — ligado na API real; mínimo de senha alinhado em 8.
- **`js/echo-ui.js`** — sino ligado na API real, sem os dados de exemplo.
  Badge usa o `unread_count` do servidor. Polling a cada 20s que pausa
  com a aba escondida e para em caso de 401. Clicar numa notificação
  marca como lida e leva para a tela correspondente ao tipo. Horários
  viraram tempo relativo ("há 5 min").

---

## Ambiente de teste

XAMPP em `C:\xampp`. O MySQL costuma estar parado; subir com
`C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini`.
Banco `banco`, usuário `root` sem senha.

Servidor de aplicação para teste: `php -S 127.0.0.1:8123` na raiz do
projeto (o PHP do XAMPP fica em `C:\xampp\php\php.exe`).

Quatro contas de teste: `alice`, `bruno`, `carla` e `diego`, todas
`@echo.local` com senha `senha123`. Alice e Bruno são amigos.

Roteiro mínimo para cada módulo: 401 em todos os endpoints sem sessão;
caminho feliz; tentativa de agir sobre dado de outro usuário; método HTTP
errado; e os limites de validação.

Para testar recuperação de senha sem SMTP: chamar `forgot_password.php`
e pegar o link em `logs/mail.log`.

---

## O que ficou de fora (candidatos a próximo passo)

Nada disso bloqueia o uso do sistema.

- **Apagar círculo** — não existe endpoint. `circles.id` já é referenciado
  com `ON DELETE CASCADE`, então dá para acrescentar `delete.php` sem
  migração de banco.
- **Marcar mensagem como lida** — `messages.receiver_id` está na resposta
  justamente para isso, mas não há coluna `read_at` nem endpoint.
- **Invalidar sessões em outros navegadores ao trocar a senha** —
  `reset_password.php` derruba só a sessão atual. Fazer isso direito pede
  uma coluna `session_version` em `users`, conferida no `require_login()`.
- **Apagar `api/auth/reset.php`** — está desativada com HTTP 410 desde
  28/08; pode sair quando ninguém mais chamar a rota antiga.
- **Splash do `index.html` em aba de segundo plano** — a animação GSAP usa
  `requestAnimationFrame`, que o navegador congela em aba escondida; o
  texto fica embaralhado até a aba ganhar foco. Se incomodar, dá para
  pular a animação quando `document.hidden` for verdadeiro.
