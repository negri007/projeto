# Plano de Upgrade — Sistema Echo

> **Status em 31/08/2026: todos os itens deste plano foram implementados**
> — back-end e front-end. O estado atual do projeto, com o que mudou em
> cada arquivo e o que ficou de fora, está em `ajustes.md`. Os formatos
> de requisição e resposta valem os de `docs/API_CONTRACT.md`, que é a
> versão implementada e testada; onde este plano divergir dele, o
> contrato manda.
>
> Divergências conhecidas entre o plano original e o que foi entregue:
> `GET /api/notifications/list.php` também devolve `unread_count`,
> `actor_id` e `actor_avatar`; `GET /api/profile/get.php` aceita um
> `user_id` opcional e devolve `bio` (não `about`), com estatísticas de
> amigos e círculos no lugar de seguidores/seguindo; `POST
> /api/posts/create.php` devolve o post criado; `POST
> /api/auth/register.php` já abre a sessão.


Documento de referência técnica para guiar a implementação. Fluxo de trabalho:

- **Antigravity** cuida do front-end (HTML/CSS/JS das telas) e também gera os
  planos de implementação técnicos para o Claude Code a partir deste
  documento — ou seja, o Antigravity pode reescrever/detalhar as seções de
  backend abaixo em tarefas ainda mais granulares antes de repassar ao Code.
- **Claude Code** implementa toda a lógica por trás do visual: PHP, banco de
  dados, sessão/autenticação no servidor, envio de e-mail, regras de negócio.

Cada item abaixo está marcado com **[BACKEND — Claude Code]** ou
**[FRONTEND — Antigravity]**. A seção "Contrato de API" é a peça mais
importante do documento: como as duas ferramentas vão trabalhar em paralelo
sem se ver, é o contrato que garante que o front chama a API do jeito exato
que o back vai implementar. Nenhuma das duas pontas deve improvisar um
formato de requisição/resposta diferente do especificado aqui — se algo
precisar mudar, mude neste documento primeiro e propague para as duas
ferramentas.

---

## 0. Contrato de API (seguir à risca nas duas pontas)

### Sessão / autenticação
Depois do login, o servidor mantém sessão via cookie PHP padrão
(`PHPSESSID`). O front **não envia mais `email` ou `user_id` em nenhuma
chamada** — o servidor identifica o usuário pela sessão. Toda chamada
`fetch` do front precisa incluir `credentials: "same-origin"`.

**POST /api/auth/login.php**
Request: `{ "email": string, "password": string }`
Response 200: `{ "success": true, "user": { "id": int, "name": string, "email": string } }`
Response 200 (erro de credencial): `{ "error": string }`
Efeito colateral: seta `$_SESSION['user_id']`.

**POST /api/auth/logout.php**
Request: `{}` (nenhum corpo necessário)
Response 200: `{ "ok": true }`
Efeito colateral: `session_destroy()`.

**GET /api/auth/me.php** (novo endpoint — usar no carregamento de toda página protegida)
Response 200 (logado): `{ "authenticated": true, "user": { "id": int, "name": string, "email": string } }`
Response 401 (não logado): `{ "authenticated": false }`
O front usa este endpoint para decidir se redireciona para a tela de login,
substituindo a checagem antiga de `localStorage.getItem("userEmail")`.

### Endpoints existentes que MUDAM de assinatura
Todos deixam de receber `email`/`user_id` no body ou query string. O
usuário autenticado vem da sessão no back-end. Resposta de erro por falta
de sessão em qualquer um destes: HTTP 401, `{ "error": "Não autenticado." }`.

| Endpoint | Antes | Depois |
|---|---|---|
| POST /api/posts/create.php | `email`, `content`, `image` (form-data) | `content`, `image` (form-data) |
| POST /api/posts/delete.php | `email`, `post_id` | `post_id` |
| POST /api/posts/like.php | `email`, `post_id` | `post_id` |
| POST /api/posts/share.php | `email`, `post_id` | `post_id` |
| GET /api/posts/list.php | `email` (query) | nenhum parâmetro de identidade |
| POST /api/comments/create.php | `email`, `post_id`, `body` | `post_id`, `body` |
| POST /api/friends/send.php | `email`, `friend_email` | `friend_email` |
| GET /api/messages/list.php | `me`, `friend` (query) | `friend` (query) — `me` vem da sessão |
| POST /api/messages/send.php | `email`, `friend_email`, `body` | `friend_email`, `body` |
| POST /api/circles/create.php | `email`, `name`, `description` | `name`, `description` |
| POST /api/circles/add_member.php | `email`, `circle_id`, `friend_email` | `circle_id`, `friend_email` |
| GET /api/profile/get.php | `email` (query) | nenhum — retorna o perfil do usuário logado |

(Aplicar o mesmo padrão aos demais endpoints de `friends/`, `circles/`,
`circle_messages/` não listados individualmente — todos perdem o parâmetro
de identidade e passam a usar a sessão.)

### Notificações (novo)

**GET /api/notifications/list.php**
Response 200: `{ "ok": true, "notifications": [ { "id": int, "type": "like"|"comment"|"share"|"friend_request"|"friend_accept"|"message", "actor_name": string, "reference_id": int|null, "is_read": bool, "created_at": string } ] }`

**POST /api/notifications/mark_read.php**
Request: `{ "notification_id": int }` ou `{ "mark_all": true }`
Response 200: `{ "ok": true }`

O front (Antigravity) pode fazer polling a cada 15–30s neste endpoint para
atualizar o contador do sino de notificações.

### Recuperação de senha por e-mail (novo)

**POST /api/auth/forgot_password.php**
Request: `{ "email": string }`
Response 200 (sempre, mesmo se o e-mail não existir — evita enumeração de usuários): `{ "ok": true, "message": "Se o e-mail existir, um link foi enviado." }`

**POST /api/auth/reset_password.php**
Request: `{ "token": string, "new_password": string }`
Response 200: `{ "ok": true }`
Response 400 (token inválido/expirado): `{ "error": string }`

O e-mail enviado deve linkar para `reset.html?token=...` — página nova que
o Antigravity precisa criar (formulário de nova senha).

---

## 1. [BACKEND — Claude Code] Segurança: autenticação real (sessão)

### Problema atual
Todo o sistema identifica o usuário logado pelo e-mail, que o front lia do
`localStorage` e mandava em cada requisição. Isso permite que qualquer
pessoa se passe por outro usuário sabendo apenas o e-mail dele.

### Implementação
1. **`api/auth/session.php`** (novo, incluído por toda API protegida):
   - `session_start()` no topo.
   - `require_login()`: se `$_SESSION['user_id']` não existir, HTTP 401 +
     `{"error": "Não autenticado."}` + `exit`.
   - `current_user_id()`: retorna `$_SESSION['user_id']`.
2. **`api/auth/login.php`**: após `password_verify`, `session_start()` +
   `$_SESSION['user_id'] = $user['id']` + `$_SESSION['user_name'] = $user['name']`.
3. **`api/auth/logout.php`** (novo) e **`api/auth/me.php`** (novo) — ver
   contrato de API acima.
4. Migrar todos os endpoints listados na tabela do contrato de API para
   `require_login()` + `current_user_id()`, removendo o parâmetro de
   identidade recebido do front.
5. Headers CORS/cookies: garantir `session.cookie_httponly` e
   `session.cookie_samesite = "Lax"` no `php.ini` ou via
   `session_set_cookie_params()`.

---

## 2. [BACKEND — Claude Code] Banco de dados: corrigir schema

### SQL a adicionar/corrigir no `banco.sql`
Antes de rodar, conferir com `SHOW CREATE TABLE` se essas tabelas/colunas
já existem manualmente no banco local — se sim, só sincronizar o `.sql`.

```sql
ALTER TABLE friends
    ADD COLUMN status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending'
    AFTER friend_id;

CREATE TABLE IF NOT EXISTS circle_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member (circle_id, user_id),
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS circle_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT NOT NULL,
    sender_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    actor_id INT NOT NULL,
    type ENUM('like', 'comment', 'share', 'friend_request', 'friend_accept', 'message') NOT NULL,
    reference_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## 3. Novas funcionalidades

### 3.1 [BACKEND — Claude Code] Recuperação de senha por e-mail
- `api/auth/forgot_password.php` e `api/auth/reset_password.php` conforme
  contrato de API. Usar PHPMailer com SMTP (Mailtrap para dev/apresentação,
  ou Gmail App Password). Gravar apenas o **hash** do token, nunca o token
  puro; validade de ~1h.

### 3.2 [FRONTEND — Antigravity] Tela de redefinição de senha
- `reset.html`: formulário com campo de nova senha (+ confirmação), lê o
  `token` da query string, chama `POST /api/auth/reset_password.php`.
- Tela/modal de "esqueci minha senha" na tela de login, chama
  `POST /api/auth/forgot_password.php`.

### 3.3 [BACKEND — Claude Code] Sistema de notificações — geração
- Ao ocorrer curtida, comentário, compartilhamento, pedido/aceite de
  amizade ou mensagem nova, inserir uma linha em `notifications`.
- Implementar `api/notifications/list.php` e `mark_read.php` conforme
  contrato de API.

### 3.4 [FRONTEND — Antigravity] Sistema de notificações — interface
- Ícone de sino no layout principal com contador de não lidas.
- Painel/dropdown listando as notificações (usar `actor_name`, `type`,
  `created_at` do contrato de API para montar o texto e o ícone de cada
  item).
- Polling a cada 15–30s em `GET /api/notifications/list.php`.

---

## 4. [FRONTEND — Antigravity] Visual / UX

- Padronizar espaçamento/tipografia entre as telas (hoje `chat.html`,
  `circulos.html`, `explorar.html`, `perfil.html` têm estilos inline
  divergentes).
- Extrair CSS repetido inline para um `css/echo.css` único.
- Estados de carregamento (skeleton/spinner) nas listagens (feed,
  mensagens, círculos).
- Responsividade: sidebar em telas menores que 768px.
- Feedback visual consistente de erro/sucesso (padronizar como cada tela
  trata o campo `error` do JSON de resposta).
- Trocar toda checagem `localStorage.getItem("userEmail")` por chamada a
  `GET /api/auth/me.php` no carregamento da página (ver contrato de API).
- Adaptar todas as chamadas `fetch` existentes para: (1) não enviar mais
  `email`, (2) incluir `credentials: "same-origin"`.

---

## Ordem de execução recomendada

1. **[Claude Code]** Rodar `SHOW CREATE TABLE` no banco local e corrigir
   `banco.sql` (item 2).
2. **[Claude Code]** Implementar `session.php`, `logout.php`, `me.php` e
   migrar `login.php` (item 1).
3. **[Claude Code]** Migrar endpoint por endpoint da tabela do contrato de
   API para `require_login()`.
4. **[Antigravity]** Adaptar as chamadas `fetch` do front para o novo
   contrato (sem `email`, com `credentials`), usando `me.php` para checagem
   de sessão.
5. **[Claude Code]** Implementar recuperação de senha e notificações
   (back-end) — itens 3.1 e 3.3.
6. **[Antigravity]** Construir as telas correspondentes (reset de senha,
   painel de notificações) — itens 3.2 e 3.4.
7. **[Antigravity]** Passada de UX/CSS geral (item 4).

Essa ordem existe porque o contrato de API (item 0) é a interface estável
entre as duas ferramentas — uma vez que o backend implementa exatamente o
que está descrito ali, o Antigravity pode construir o front em paralelo
sem esperar o Claude Code terminar tudo, desde que ambos sigam o contrato
à risca.
