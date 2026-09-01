# Sistema ECHO

Rede social completa em **PHP + MySQL + JavaScript sem framework**: feed,
amizades, mensagens privadas, círculos (grupos fechados), notificações,
busca, etiquetas e recuperação de senha por e-mail.

Este arquivo é o retrato geral do sistema — o que ele faz, como está
montado e como rodar. Os dois documentos irmãos entram no detalhe:

| Arquivo | Para que serve |
|---|---|
| `docs/API_CONTRACT.md` | **fonte única da verdade** dos endpoints: parâmetros, formato de resposta e mensagens de erro, um por um |
| `ajustes.md` | histórico do que foi feito, por quê, e o que ficou de fora |
| `docs/PLANO_UPGRADE_ECHO.md` | plano original do upgrade (concluído); serve como registro da intenção |

---

## 1. O que o sistema faz

| Área | O que existe |
|---|---|
| **Conta** | cadastro, login com freio de força bruta, logout, troca de senha, recuperação por e-mail |
| **Publicações** | texto e imagem, editar, apagar, curtir, compartilhar, salvar, comentar (comentário também edita e apaga) |
| **Feed** | Início cronológico da sua roda (você + amigos) ou da rede inteira; paginação por cursor |
| **Descoberta** | Explorar com busca global, etiquetas em alta e publicações ranqueadas por engajamento |
| **Amizades** | pedir, aceitar, recusar, cancelar, desfazer, buscar pessoas, sugestões |
| **Mensagens** | conversa privada entre amigos, lista lateral com prévia e não lidas |
| **Círculos** | grupos fechados com dono e membros, cada um com seu chat |
| **Notificações** | curtida, comentário, compartilhamento, pedido e aceite de amizade, mensagem e menção |
| **Etiquetas e menções** | `#etiqueta` indexada e clicável, `@pessoa` que notifica quem foi citado |

### As duas telas de feed não mostram a mesma coisa

Essa é a decisão de produto mais importante do sistema:

| | **Início** | **Explorar** |
|---|---|---|
| O que lista | você + seus amigos | a rede inteira |
| Ordem | data (mais novo primeiro) | engajamento dos últimos 7 dias |
| Coluna direita | assuntos em alta + seus círculos | pessoas para conhecer |
| Publicar | sim | não |
| Alternador | Amigos / Todos | Em alta / Recentes |

---

## 2. Como rodar

Ambiente de desenvolvimento com **XAMPP** (`C:\xampp`), MySQL sem senha
no usuário `root`.

```bash
# 1. Banco
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini
C:\xampp\mysql\bin\mysql.exe -u root < banco.sql

# 2. Servidor de aplicação, na raiz do projeto
C:\xampp\php\php.exe -S 127.0.0.1:8123
```

Abrir `http://127.0.0.1:8123/index.html`.

`banco.sql` é **idempotente**: roda num banco vazio (cria tudo) ou num
banco já existente (cria só o que falta e acrescenta as colunas
ausentes). Pode ser reexecutado sem medo.

### Contas de teste

Quatro contas, todas com a senha `senha123`:

| E-mail | Nome | Relações |
|---|---|---|
| `alice.teste@echo.local` | Alice Teste | amiga da Carla; pedido pendente para o Bruno |
| `bruno.teste@echo.local` | Bruno Teste | pedido da Alice esperando resposta |
| `carla.teste@echo.local` | Carla Teste | amiga da Alice |
| `diego.teste@echo.local` | Diego 100% | sem relações |

### E-mail

`api/auth/mailer.php` tem dois drivers:

- **`log`** (padrão) — grava a mensagem em `logs/mail.log` e não envia
  nada. O fluxo inteiro de recuperação de senha é testável sem SMTP: o
  link com o token aparece no arquivo.
- **`smtp`** — envia de verdade via PHPMailer 6.9.1 (`lib/PHPMailer/`, o
  projeto não usa Composer). Para ativar, copiar
  `api/auth/mail_config.example.php` para `api/auth/mail_config.php` e
  preencher host, usuário e senha.

`mail_config.php` está no `.gitignore`: credencial de SMTP não entra no
repositório.

---

## 3. Como o sistema está montado

```
projeto/
├── api/                 back-end, um diretório por módulo
│   ├── auth/            sessão, login, cadastro, senha, e-mail
│   ├── posts/           publicações, curtidas, salvos, etiquetas
│   ├── comments/        comentários
│   ├── friends/         amizades e busca de pessoas
│   ├── messages/        mensagens privadas
│   ├── circles/         círculos e membros
│   ├── circle_messages/ chat de círculo
│   ├── notifications/   sino
│   ├── hashtags/        tendências
│   └── search/          busca global
├── css/echo.css         design system inteiro, inclusive as animações
├── js/
│   ├── echo-ui.js       sessão, sino, toasts, diálogo, avatares, busca
│   └── echo-feed.js     o componente de feed (lista, ações, comentários)
├── lib/PHPMailer/       envio de e-mail, sem Composer
├── uploads/             imagens de post e avatares
├── docs/                contrato da API e plano do upgrade
└── *.html               as telas
```

### Telas

| Arquivo | Tela |
|---|---|
| `index.html` | entrada: login, cadastro e "esqueci minha senha" |
| `reset.html` | redefinição de senha pelo link do e-mail |
| `inicio.html` | feed da sua roda + caixa de publicar |
| `explorar.html` | busca, etiquetas em alta e publicações ranqueadas |
| `salvos.html` | publicações que você guardou |
| `perfil.html` | perfil próprio ou de outra pessoa (`?user_id=N`) |
| `amigos.html` | amigos, pedidos recebidos, enviados e sugestões |
| `chat.html` | mensagens privadas |
| `circulos.html` | lista e gestão de círculos |
| `circle_chat.html` | conversa de um círculo (`?circle_id=N`) |

### O componente de feed

O feed aparece em quatro telas (início, explorar, perfil e salvos). Ele
não é copiado em cada uma: `js/echo-feed.js` centraliza lista, paginação,
ações do post e caixa de comentários. A tela só diz **de onde vem a
lista** e **onde ela é desenhada**:

```js
const feed = EchoFeed.create({
    container: "feedContainer",
    params: { limit: 10, scope: "friends" },   // filtros de posts/list.php
    emptyText: "Sua roda está quieta."
});
feed.load();
```

Foi o que permitiu acrescentar o botão de salvar nas quatro telas com uma
edição só — antes, um botão novo significava três edições e, na prática,
três bugs diferentes.

---

## 4. Banco de dados

Quinze tabelas, todas InnoDB, `utf8mb4`:

| Tabela | Guarda |
|---|---|
| `users` | conta, bio, avatar e `session_version` |
| `posts` | publicações (`edited_at` marca edição) |
| `post_likes`, `post_shares`, `post_saves` | curtidas, compartilhamentos e salvos |
| `comments` | comentários (também com `edited_at`) |
| `hashtags`, `post_hashtags` | etiquetas e a ligação com os posts |
| `friends` | amizade em **uma única linha**, com `status` `pending`/`accepted` |
| `messages` | mensagens privadas (`read_at` marca leitura) |
| `circles`, `circle_members`, `circle_messages` | círculos, membros e conversa |
| `notifications` | sino, com `type` em ENUM |
| `password_resets` | token de recuperação, **só o hash** |
| `login_attempts` | tentativas de login, para o freio de força bruta |

Três decisões de modelagem que explicam o resto:

- **A amizade é uma linha só**, gravada em qualquer uma das duas
  direções. Por isso toda consulta de amizade olha os dois sentidos.
- **O dono do círculo não tem linha em `circle_members`**; a posse vive
  em `circles.owner_id`. Logo `member_count` nunca inclui o dono — ele
  vem à parte, na chave `owner`.
- **Etiqueta vive em tabela própria**, não num `LIKE '%#tag%'` sobre o
  texto: LIKE com curinga à esquerda não usa índice e ainda casa `#php`
  dentro de `#phpstorm`.

---

## 5. API — 47 endpoints

Todos respondem JSON. O detalhe de cada um está em
`docs/API_CONTRACT.md`; aqui vai o mapa.

| Módulo | Endpoints |
|---|---|
| `auth/` | `login`, `logout`, `me`, `register`, `change_password`, `forgot_password`, `reset_password` (+ `reset` desativada) |
| `posts/` | `list`, `create`, `edit`, `delete`, `like`, `share`, `save` |
| `comments/` | `list`, `create`, `edit`, `delete` |
| `friends/` | `list`, `list_pending`, `sent_list`, `suggestions`, `search`, `send`, `accept`, `reject`, `cancel`, `remove` |
| `messages/` | `list`, `send`, `conversations`, `mark_read` |
| `circles/` | `list`, `list_members`, `create`, `delete`, `add_member`, `remove_member` |
| `circle_messages/` | `list`, `send` |
| `notifications/` | `list`, `mark_read` |
| `hashtags/` | `trending` |
| `search/` | `all` |

`GET /api/posts/list.php` é o endpoint mais versátil — todas as listas de
publicação do sistema saem dele:

| Parâmetro | Efeito |
|---|---|
| `limit`, `before_id` | paginação por cursor |
| `user_id` | só os posts daquele autor (perfil) |
| `tag` | só posts com aquela etiqueta |
| `saved=1` | só os salvos de quem está na sessão |
| `scope=friends` | você + seus amigos (Início) |
| `sort=top` + `days` | ordem por engajamento (Explorar) |

### Convenções que valem para todo endpoint

1. **Identidade vem sempre da sessão** (`require_login()` /
   `current_user_id()`). Nenhum endpoint aceita `email` ou `user_id` do
   cliente como identidade.
2. Sucesso é sempre um **objeto** com `"ok": true` mais uma chave nomeada
   (`posts`, `friends`, `circles`, ...), nunca um array na raiz.
3. Erro é sempre `{ "error": string }`. Sem sessão: HTTP 401 com
   `{ "error": "Não autenticado." }`.
4. Todo item de lista traz o `user_id` do dono. O front decide "é meu?"
   comparando com o `user.id` de `GET /api/auth/me.php` — nunca por
   e-mail ou nome.
5. Ids e contadores são inteiros JSON; datas são strings
   `"YYYY-MM-DD HH:MM:SS"`; campos opcionais vêm `null`, não `""`.
6. `$e->getMessage()` **nunca** vai para a resposta do cliente — só para
   `error_log()`.
7. PDO com prepared statement em toda query.
8. Upload é validado pelo **MIME real** (`finfo`), nunca pela extensão
   informada pelo cliente, e sempre com limite de tamanho.

---

## 6. Segurança

### Falhas corrigidas

Todas eram exploráveis sem estar logado, só trocando um parâmetro:

| Rota | O que dava para fazer | Correção |
|---|---|---|
| `auth/reset.php` | trocar a senha de qualquer conta só com o e-mail, sem token | rota desativada (HTTP 410); substituída por `forgot_password` + `reset_password` |
| `messages/list.php` | ler a conversa privada de qualquer par de usuários | sessão + exigência de amizade aceita |
| `messages/send.php` | mandar mensagem se passando por outra pessoa | idem |
| `circle_messages/*` | ler e escrever no chat de qualquer círculo | sessão + checagem de dono-ou-membro |
| `profile/get.php` | ler o perfil de qualquer usuário pelo e-mail | identidade da sessão |
| `profile/update.php` | **editar o perfil de qualquer usuário** | edita sempre o usuário da sessão |
| todos os módulos | agir como qualquer usuário mandando o e-mail dele | sessão PHP em todos |

### Defesas em pé

- **Sessão PHP** com `session_regenerate_id` no login (contra fixação),
  cookie `httponly` e `samesite=Lax`.
- **Sessão versionada.** `users.session_version` é conferido a cada
  requisição em `api/auth/db.php`. Trocar a senha incrementa a coluna e
  **derruba as sessões abertas em outros navegadores** — quem trocou
  continua logado só onde trocou.
- **Freio de força bruta.** 5 erros por e-mail ou 20 por IP em 15 minutos
  travam o login por 15 minutos; durante o bloqueio, até a senha certa é
  recusada — senão o freio não freia nada.
- **Token de recuperação** de 32 bytes, gravado só como hash SHA-256,
  validade de 1 hora, uso único; um pedido novo invalida os anteriores.
  Token inválido, expirado, já usado e ausente devolvem a mesma
  mensagem, para não virar um oráculo.
- **Senhas nunca passam por `trim()`**: espaço no começo ou no fim faz
  parte da senha.
- **Círculo é privado.** Quem não participa recebe sempre "Círculo não
  encontrado", nunca uma resposta que confirme a existência do círculo —
  e a busca global não indexa círculo alheio.
- **Escape antes de ligar.** O texto do post é escapado e só então
  `#etiqueta` e `@pessoa` viram link. Na ordem inversa, o HTML do próprio
  link seria comido pelo escape — ou, pior, passaria HTML do usuário.
- **Diálogo destrutivo não confirma no Enter**, e abre com o foco em
  *Cancelar*: o padrão de uma pergunta perigosa é não fazer nada.

---

## 7. Detalhes que costumam gerar dúvida

- **Handle** é a parte do e-mail antes do `@` (`alice.teste@echo.local` →
  `@alice.teste`). É o que a menção usa. Handle ambíguo — duas contas com
  o mesmo nome antes do `@` — **não notifica ninguém**: melhor perder a
  menção do que avisar a pessoa errada.
- **Menção sempre aponta para o post**, mesmo quando veio num
  comentário: é para lá que o clique na notificação leva.
- **Salvar é privado.** O autor não é avisado e não existe contador
  público — por isso o post tem `saved_by_me`, mas não tem `save_count`.
- **Comentário: quem edita é só o autor.** O dono do post pode apagar um
  comentário do seu post, mas não reescrevê-lo — editar a fala de outra
  pessoa mantendo o nome dela embaixo seria pôr palavras na boca de
  alguém.
- **`sort=top` devolve página única.** O cursor é o id do último post, o
  que só descreve posição quando a ordem é por id.
- **Paginação é por cursor, não por OFFSET.** Com OFFSET, um post novo no
  topo desloca as páginas e o item da borda aparece repetido ou some.
- **As animações morrem em `prefers-reduced-motion`.** Não é enfeite de
  acessibilidade: animação de entrada em lista longa dá enjoo em quem tem
  sensibilidade vestibular.

---

## 8. Como testar

Roteiro mínimo por módulo, o mesmo usado no desenvolvimento:

1. **401 sem sessão** em todos os endpoints;
2. caminho feliz;
3. tentativa de agir sobre dado de outro usuário (deve falhar com a
   mensagem genérica);
4. método HTTP errado;
5. os limites de validação (texto vazio, longo demais, id inexistente).

```bash
# sessão em arquivo, como o navegador faria
curl -s -c alice.txt -X POST http://127.0.0.1:8123/api/auth/login.php \
     -H "Content-Type: application/json" \
     -d '{"email":"alice.teste@echo.local","password":"senha123"}'

curl -s -b alice.txt "http://127.0.0.1:8123/api/posts/list.php?scope=friends&limit=5"
```

Para conferir a invalidação de sessão: logar a mesma conta em **dois**
arquivos de cookie, trocar a senha por um deles e ver o outro passar a
receber 401 — inclusive em `me.php`.

Para recuperação de senha sem SMTP: chamar `forgot_password.php` e pegar
o link em `logs/mail.log`.

---

## 9. O que ficou de fora

Nada disso bloqueia o uso do sistema:

- **Busca dentro do chat e do círculo** — a busca global cobre pessoas,
  publicações, etiquetas e círculos, mas não o conteúdo das conversas.
- **Handle próprio, separado do e-mail** — resolveria a menção ambígua;
  pede uma coluna `handle` única em `users` e um migrador para as contas
  existentes.
- **Etiqueta em comentário** — `#tag` num comentário vira link, mas não
  conta para a tendência; só o texto do post é indexado.
- **Tempo real** — notificações e chat usam polling (20 s no sino), não
  push.
- **Apagar `api/auth/reset.php`** — está desativada com HTTP 410; pode
  sair quando ninguém mais chamar a rota antiga.
