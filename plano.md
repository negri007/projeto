# Plano de Upgrade — Sistema Echo: Status de Implementação

Documento de acompanhamento do status do upgrade do **Sistema Echo**.
Divisão de trabalho entre **Front-end** (Antigravity) e **Back-end** (Claude Code) com base em `docs/API_CONTRACT.md` e `docs/PLANO_UPGRADE_ECHO.md`.

---

## 📊 Resumo Executivo do Status

| Área / Módulo | Responsável | Status | Progresso |
|---|---|---|---|
| **1. Banco de Dados (Schema SQL)** | Back-end | **Concluído** | 100% |
| **2. Design System & CSS Centralizado** | Front-end | **Concluído** | 100% |
| **3. Componentes de UI (JS & Notificações)** | Front-end | **Concluído** | 100% |
| **4. Tela de Redefinição de Senha (`reset.html`)** | Front-end | **Concluído** (UI / Validação) | 90% (aguarda API backend) |
| **5. Migração da Autenticação (`EchoUI.checkAuth`)** | Front-end | **Concluído** | 100% |
| **6. Migração de Endpoints de Posts (`api/posts/`)** | Front-end | **Concluído** | 100% |
| **7. Migração de Endpoints de Comentários (`api/comments/`)** | Front-end | **Concluído** | 100% |
| **6. Modal "Esqueci Minha Senha" (`index.html`)** | Front-end | **Pendente** | 0% |
| **7. Sistema de Autenticação via Sessão PHP** | Back-end | **Pendente** | 0% |
| **8. Migração de Endpoints Legados para Sessão** | Back-end | **Pendente** | 0% |
| **9. Backend de Recuperação de Senha por E-mail** | Back-end | **Pendente** | 0% |
| **10. Backend de Notificações (Geração & APIs)** | Back-end | **Pendente** | 0% |

---

## ✅ O Que Já Foi Feito

### 1. Banco de Dados (`banco.sql`) — [Concluído]
- **Schema Idempotente e Completo**: O arquivo `banco.sql` foi revisado e consolidado.
- **Tabelas e Colunas Adicionadas**:
  - `friends`: coluna `status ENUM('pending', 'accepted')` com valor default `'pending'`.
  - `circle_members`: tabela de associação entre círculos e usuários.
  - `circle_messages`: tabela de mensagens dentro dos círculos.
  - `notifications`: suporte a notificações de curtida, comentário, compartilhamento, amizade e mensagens.
  - `password_resets`: tabela de tokens com expiração e controle de uso.
- **Procedure de Migração Legada**: `echo_add_column_if_missing` implementada para atualizar tabelas existentes sem erros de execução.

---

### 2. Design System & Estilos (`css/echo.css`) — [Concluído]
- **CSS Unificado**: Extração de estilos inline repetidos em um arquivo CSS central.
- **Tokens de Design**: Cores dark mode elevadas (grafites, neon cyan `#00f2fe`, violeta `#4facfe`), tipografia, gradientes e sombras.
- **Componentes Prontos**:
  - `echo-sidebar` (navegação responsiva).
  - `echo-card` (cards de feed, perfil e comentários).
  - `auth-layout` (telas de login e reset de senha com lado hero e lado formulário).
  - `notification-dropdown` (painel de notificações estilizado).
  - `alert-custom` (alertas de feedback para erro, sucesso e aviso).
  - `skeleton-box` e `spinner-echo` (componentes para feedback visual de carregamento).

---

### 3. Gerenciador de UI & Notificações (`js/echo-ui.js`) — [Concluído]
- **Classe `EchoUI`**: Módulo cliente responsável pela interface global.
- **Painel de Notificações**:
  - Geração dinâmica do HTML do sino com badge de contagem não lida (`getNotificationBellHTML()`).
  - Renderização da lista com ícones personalizados por tipo (curtida, comentário, amizade, mensagem).
  - Ações de leitura individual (`markAsRead`) e coletiva (`markAllAsRead`).
  - Estrutura de polling a cada 15s (`fetchNotifications()`) integrada com fallback de mock para testes locais.
- **Integração nas Telas**: O container do sino (`notificationBellContainer`) e sua inicialização JS foram adicionados ao cabeçalho de todas as páginas: `inicio.html`, `explorar.html`, `perfil.html`, `circulos.html`, `amigos.html`, `chat.html` e `circle_chat.html`.
- **Utilitários**: Funções globais para toasts, banners e validação helpers (`checkAuth()`).

---

### 4. Tela de Redefinição de Senha (`reset.html`) — [Concluído (Interface)]
- **Página Dedicada**: Layout construído seguindo o design system do Echo.
- **Validação de Front-end**:
  - Leitura do parâmetro `token` na URL (`?token=...`).
  - Bloqueio da página com mensagem de erro caso o token esteja ausente.
  - Validação de senha mínima de 6 caracteres e verificação de coincidência dos campos.
  - Animação de spinner no botão durante o envio.
  - Código preparado e documentado para consumo da API `POST /api/auth/reset_password.php`.

---

### 5. Checagem de Autenticação via Sessão PHP (`GET /api/auth/me.php`) — [Concluído]
- **Migração das 8 Telas**: Removida completamente a validação síncrona antiga `localStorage.getItem("userEmail")` em `index.html`, `inicio.html`, `explorar.html`, `perfil.html`, `circulos.html`, `amigos.html`, `chat.html` e `circle_chat.html`.
- **Implementação do `EchoUI.checkAuth()`**:
  - Todas as telas protegidas realizam a verificação assíncrona com `GET /api/auth/me.php` passando `{ credentials: "same-origin" }`.
  - Redirecionamento automático para `index.html` caso o usuário não esteja autenticado.
  - Atualização automática dos elementos de perfil da sidebar/header com os dados devolvidos por `me.php`.
- **Fluxos de Login e Logout**:
  - `POST /api/auth/login.php` ajustado em `index.html` com `credentials: "same-origin"`.
  - `logout()` integrado ao endpoint `POST /api/auth/logout.php` via `EchoUIInstance.logout()`.

---

### 6. Migração das Chamadas de Posts (`api/posts/`) — [Concluído]
- **Telas Migradas**: `inicio.html`, `explorar.html` e `perfil.html`.
- **Alterações de Payload & Segurança**:
  - `POST /api/posts/create.php`: removido `email` do `FormData` (envia apenas `content` e `image`), incluindo `credentials: "same-origin"`.
  - `POST /api/posts/delete.php`: removido `email` do corpo JSON (envia apenas `{ post_id }`), incluindo `credentials: "same-origin"`. Exibe mensagem de erro devolvida pelo servidor (`"Post não encontrado ou não é seu."`).
  - `POST /api/posts/like.php`: removido `email` do corpo JSON (envia apenas `{ post_id }`), incluindo `credentials: "same-origin"`. Suporta curtir/descurtir (toggle).
  - `POST /api/posts/share.php`: inclui `credentials: "same-origin"` e envia apenas `{ post_id }`.
  - `GET /api/posts/list.php`: removido parâmetro `email` da URL (chamada limpa sem query string), com `credentials: "same-origin"`. O status `liked_by_me` e a verificação de autor do post são lidos diretamente dos dados retornados pelo servidor baseados na sessão PHP.

---

### 7. Migração das Chamadas de Comentários (`api/comments/`) — [Concluído]
- **Telas Migradas**: `inicio.html`, `explorar.html` e `perfil.html`.
- **Alterações no `GET /api/comments/list.php`**:
  - Adicionado `credentials: "same-origin"`.
  - Tratamento do novo contrato de resposta que envolve os comentários em `{ "ok": true, "comments": [...] }`.
- **Alterações no `POST /api/comments/create.php`**:
  - Removido `email`/`user_id` do corpo JSON (envia apenas `{ post_id, body }`).
  - Adicionado `credentials: "same-origin"`.
  - Inserção imediata do comentário recém-criado retornado em `{ "ok": true, "comment": {...} }` sem necessidade de recarregar a lista completa.
- **Checagem de Autoria de Post (`isYou`)**:
  - Atualizada para verificar se `post.user_id === currentUser.id` (ou `is_my_post`) para exibir o botão de exclusão.

---

## ⏳ O Que Falta Fazer

### 🔴 Front-end (Escopo: Antigravity)

1. **Migração de Checagem de Sessão (`localStorage` -> `GET /api/auth/me.php`)**:
   - Substituir o bloco de checagem inicial `if (!localStorage.getItem("userEmail"))` em todas as páginas por chamada assíncrona ao `GET /api/auth/me.php` (utilizando `EchoUIInstance.checkAuth()`).

2. **Remoção de Parâmetros de Identidade & Adição de `credentials: "same-origin"`**:
   - Em todas as chamadas `fetch` das páginas (`inicio.html`, `explorar.html`, `perfil.html`, `circulos.html`, `amigos.html`, `chat.html`, `circle_chat.html`):
     - Remover o envio de `email` / `user_id` no corpo (JSON/FormData) ou parâmetros da URL.
     - Garantir a propriedade `{ credentials: "same-origin" }` em todas as requisições `fetch`.

3. **Modal / Formulário de "Esqueci minha senha" (`index.html`)**:
   - Adicionar link "Esqueci minha senha" e formulário/modal na tela de login.
   - Conectar o envio ao endpoint `POST /api/auth/forgot_password.php`.

4. **Conexão Real do Polling de Notificações**:
   - Ativar o polling automático de `GET /api/notifications/list.php` assim que o usuário for autenticado em qualquer tela logada.

5. **Limpeza Visual e Estados de Carregamento**:
   - Remover estilos inline legados remanescentes nas páginas HTML.
   - Adicionar estados de carregamento com `skeleton-box` ou `spinner-echo` nas listagens (feed, amigos, mensagens, membros do círculo) enquanto as chamadas `fetch` aguardam resposta.

---

### 🔵 Back-end (Escopo: Claude Code)

1. **Gestão Real de Sessões PHP**:
   - Criar `api/auth/session.php` com suporte a `session_start()`, função `require_login()` (retorna 401 se não autenticado) e `current_user_id()`.
   - Atualizar `api/auth/login.php` para registrar `$_SESSION['user_id']`.
   - Criar `GET /api/auth/me.php` (retorna dados do usuário autenticado).
   - Criar `POST /api/auth/logout.php` (destrói a sessão).

2. **Refatoração dos Endpoints Existentes**:
   - Atualizar todos os endpoints em `api/posts/`, `api/comments/`, `api/friends/`, `api/messages/`, `api/circles/`, `api/circle_messages/` e `api/profile/`:
     - Incluir `require_login()`.
     - Identificar o usuário exclusivamente através de `current_user_id()`.
     - Remover leitura de `email`/`user_id` vindos da requisição cliente.

3. **Recuperação de Senha por E-mail**:
   - Criar `POST /api/auth/forgot_password.php`: gera token aleatório, grava o **hash** do token no banco com validade de 1h, e dispara e-mail com link `reset.html?token=...`.
   - Criar `POST /api/auth/reset_password.php`: valida o hash do token, verifica expiração/uso, atualiza a senha do usuário em `users` e marca o token como usado.

4. **Sistema de Notificações (Backend)**:
   - Inserção automática de eventos na tabela `notifications` ao disparar ações no sistema (likes, comentários, compartilhamentos, solicitações/aceites de amizade e mensagens).
   - Criar `GET /api/notifications/list.php`: retorna as notificações do usuário logado.
   - Criar `POST /api/notifications/mark_read.php`: marca notificação específica ou todas como lidas.

---

## 🎯 Próximos Passos Recomendados

1. **[Antigravity]** Executar a migração das chamadas `fetch` e checagem de sessão no front-end (`checkAuth()` e `credentials: "same-origin"`).
2. **[Antigravity]** Adicionar o modal/fluxo "Esqueci minha senha" em `index.html`.
3. **[Claude Code]** Implementar `api/auth/session.php`, `me.php`, `logout.php` e refatorar os endpoints existentes para usarem a sessão.
4. **[Claude Code]** Implementar o envio de e-mails (`forgot_password.php`, `reset_password.php`) e as APIs de notificações.
5. **[Antigravity]** Conectar as respostas finais das APIs no front-end e realizar revisão visual completa.
