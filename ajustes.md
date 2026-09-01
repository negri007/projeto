# Ajustes do Upgrade — Sistema Echo

Estado do upgrade em **01/09/2026**. Serve para retomar o trabalho sem
precisar reler todo o histórico.

Documentos relacionados:

- `docs/API_CONTRACT.md` — fonte única da verdade sobre os endpoints.
  Todo formato de resposta citado aqui está detalhado lá.
- `docs/PLANO_UPGRADE_ECHO.md` — plano geral do upgrade.

---

## Situação geral

**O upgrade está completo**, e sobre ele veio uma rodada de melhorias de
produto (31/08/2026). Tudo testado ponta a ponta por `curl` e no
navegador.

| Área | Estado |
|---|---|
| Banco de dados (`banco.sql`) | Completo |
| Sessão PHP e autenticação | Completo |
| Migração de todos os endpoints para sessão | Completo |
| Notificações (geração + endpoints + sino) | Completo |
| Recuperação de senha por e-mail | Completo |
| Front-end migrado para o novo contrato | Completo |
| Rodada de melhorias (31/08) | Completo |
| Rodada de funcionalidades (01/09) | Completo |

---

## Rodada de melhorias — 31/08/2026

### Back-end

- **Paginação do feed.** `posts/list.php` devolvia a tabela inteira, sem
  limite. Agora é paginado por cursor (`limit` + `before_id`, resposta
  com `has_more`/`next_before_id`). Cursor e não OFFSET porque post novo
  no topo desloca as páginas e faz o item da borda repetir ou sumir.
  Também aceita `user_id` para filtrar por autor.
- **Freio de força bruta no login.** Nova tabela `login_attempts`;
  5 erros por e-mail ou 20 por IP em 15 minutos travam o login por 15
  minutos, e durante o bloqueio até a senha certa é recusada. Acerto
  limpa o histórico.
- **Índices** nas consultas quentes (feed, contadores por post, conversa
  nas duas direções, amizade, chat de círculo).
- **Endpoints novos:** `posts/edit.php`, `comments/delete.php`,
  `circles/delete.php`, `messages/conversations.php`,
  `messages/mark_read.php`.
- **Mensagens lidas.** Coluna `messages.read_at`; abrir a conversa marca
  como lida, e `conversations.php` devolve a lista lateral inteira
  (última mensagem + não lidas por amigo) em uma consulta.
- **Avatar** passou a vir em posts e comentários — antes a foto existia
  no banco e aparecia em uma única tela.
- **`can_delete`** nos comentários: o servidor resolve quem pode apagar
  (autor do comentário ou dono do post), o front só desenha.

### Front-end

- **Toasts e diálogo de confirmação** no tema do app, substituindo os 55
  `alert()`/`confirm()` nativos, que travavam a aba e ignoravam o CSS.
- **Avatares de verdade** em feed, comentários, chat, amigos e círculos:
  foto quando existe, senão a inicial sobre uma cor derivada do id — a
  mesma pessoa tem sempre a mesma cor, sem guardar nada no banco.
- **Perfil público** (`perfil.html?user_id=N`): nomes e avatares viraram
  links. O servidor decide de quem é o perfil (`is_me`), não a URL.
- **Edição de post inline**, com Esc para cancelar e Ctrl+Enter para
  salvar, e selo "editado" no feed.
- **Chat com prévia da última mensagem, badge de não lidas** e contador
  no título da aba.
- **Notificação leva ao lugar certo:** post curtido/comentado abre o
  feed já rolado e destacado nele; mensagem abre a conversa daquela
  pessoa.
- **"Carregar mais"** no feed, e o botão de publicar trava durante o
  envio (clique duplo publicava duas vezes).

### Bugs encontrados e corrigidos no caminho

- `rate_limit.php` calculava o fim do bloqueio em PHP com `strtotime()`
  contra `time()`. Nesta instalação o relógio do PHP está 5h à frente do
  MySQL, então o bloqueio nascia expirado e nunca pegava. A conta passou
  a ser feita dentro do SQL, com `TIMESTAMPDIFF` contra `NOW()`.
- `perfil.html` tinha o selo "você" fixo no HTML — ele aparecia nos
  posts de outras pessoas assim que a tela virou perfil público.
- A conversão automática dos `confirm()` transformou uma template string
  em string comum, quebrando a interpolação do nome do círculo.

---

## Rodada de funcionalidades — 01/09/2026

Cinco coisas novas, mais um componente de feed que apagou a duplicação
entre as telas.

### Etiquetas e tendências

Publicar com `#etiqueta` indexa o post em `hashtags` + `post_hashtags`.
Editar o post re-sincroniza (etiqueta que saiu do texto é desligada).
`GET /api/hashtags/trending.php` alimenta o card "Assuntos em alta", que
antes era texto fixo no HTML (#PHP, #Linux, #IA), e
`posts/list.php?tag=` filtra o feed.

A ligação vive numa tabela própria, e não num `LIKE '%#tag%'` sobre
`posts.content`: LIKE com curinga à esquerda não usa índice e ainda casa
`#php` dentro de `#phpstorm`.

### Busca global

`GET /api/search/all.php` devolve pessoas, publicações, etiquetas e
círculos numa chamada só — o campo do cabeçalho é um, a requisição é uma.
O campo "Buscar no ECHO", que era decorativo em todas as telas, ganhou
sugestões enquanto se digita (250 ms de espera) e Enter leva ao Explorar.

**Círculo não entra na busca dos outros:** só aparecem os círculos de que
a pessoa participa. Publicação e perfil são públicos dentro do sistema;
círculo não é.

### Menções

`@handle` (a parte do e-mail antes do `@`) num post ou comentário gera
notificação do tipo novo `mention`, com `reference_id` apontando para o
**post** — inclusive quando a menção veio num comentário. Handle ambíguo
(duas contas com o mesmo nome antes do `@`) não notifica ninguém: melhor
perder o aviso do que avisar a pessoa errada. Citar duas vezes no mesmo
post gera um aviso só.

O campo de publicar e o de comentar têm autocomplete de `@`: errar o
handle é escrever uma menção que não avisa ninguém, e o autocomplete
existe para isso não acontecer.

### Posts salvos

O marcador que existia no feed não fazia nada. Agora `posts/save.php`
alterna, `posts/list.php?saved=1` lista e a tela nova `salvos.html`
mostra. Salvar é privado: o autor não é avisado e não há contador público
— existe `saved_by_me` no post, não existe `save_count`.

### Sessão versionada e troca de senha

Item que estava na lista de "ficou de fora". `users.session_version`
versiona as sessões; `api/auth/db.php` confere a cada requisição, então a
regra vale em todo endpoint sem uma linha em cada um. Trocar a senha
incrementa a coluna e derruba as sessões abertas em outros navegadores —
tanto pela recuperação por e-mail quanto pela rota nova
`auth/change_password.php` (modal da chave no perfil), que exige a senha
atual mesmo com sessão aberta.

Pegadinha encontrada no teste: `me.php` lia o id da sessão **antes** de
incluir `db.php`, então respondia 200 com a sessão que a própria
requisição tinha acabado de invalidar. Passou a reconferir depois da
conexão.

### Editar comentário

`comments/edit.php` + coluna `comments.edited_at`. Só o autor edita — o
dono do post continua podendo apagar, mas não reescrever: editar a fala
de outra pessoa mantendo o nome dela embaixo seria pôr palavras na boca
de alguém. O servidor devolve `can_edit` junto de `can_delete`.

### Front-end

- **`js/echo-feed.js` (novo).** O feed estava copiado em três telas
  (início, explorar, perfil): três cópias do post, das ações e dos
  comentários. Agora é uma classe só; a tela diz de onde vem a lista
  (`params`) e onde ela é desenhada. Foi o que permitiu acrescentar o
  botão de salvar em quatro telas com uma edição.
- **Curtir/compartilhar/salvar deixaram de recarregar o feed inteiro.** A
  resposta atualiza o botão no lugar — antes, curtir jogava o scroll de
  volta para o topo.
- **`explorar.html` deixou de ser cópia do início** (mesma caixa de
  publicar, mesmo feed) e virou a tela de descoberta: busca com abas
  (Publicações / Pessoas / Etiquetas / Círculos), filtro por etiqueta,
  chips das etiquetas em alta e as publicações **ranqueadas por
  engajamento**. Publicar continua no início — uma coisa em cada lugar.

### Início e Explorar deixaram de mostrar a mesma coisa

Mesmo depois da separação acima, as duas telas ainda exibiam a mesma
lista cronológica de todo mundo quando não havia busca. A diferença agora
está **no conteúdo da lista**, não no enfeite:

| | Início | Explorar |
|---|---|---|
| O que lista | você + seus amigos (`scope=friends`) | a rede inteira |
| Ordem | data (`id` DESC) | engajamento dos últimos 7 dias (`sort=top`) |
| Coluna direita | assuntos em alta + **seus círculos** | **pessoas para conhecer** + o que é cada tela |
| Publicar | sim | não |

O Início tem um alternador **Amigos / Todos** (a rede inteira continua a
um toque, e ainda assim cronológica). O Explorar tem **Em alta /
Recentes**. As duas listas seguem sendo o mesmo componente
(`js/echo-feed.js`) — muda só o `params`.
- **`salvos.html` (novo)** e link "Salvos" na navegação de todas as
  telas, inclusive no menu móvel.
- **Texto rico** em post e comentário: `#etiqueta` vira filtro e
  `@handle` vira link. O texto é escapado **antes** de virar link — na
  ordem inversa, o HTML do próprio link seria comido pelo escape, ou pior,
  passaria HTML do usuário.
- Card "Talvez você conheça" ligado em `friends/suggestions.php`, com
  botão de adicionar (antes eram três nomes fictícios no HTML).

### Camada de movimento (01/09)

Animações no CSS (`css/echo.css`, bloco final) com ganchos mínimos no JS.
Regra adotada: **a animação explica uma mudança** — algo entrou, algo
virou seu, algo pediu atenção. Nada de movimento em elemento parado, nada
acima de ~400 ms, nada que segure um clique.

| Onde | O que acontece |
|---|---|
| Feed | cada post entra em escada (45 ms entre um e outro, teto no oitavo) |
| Curtir / salvar / compartilhar | o ícone pula e solta um anel; o contador sobe e volta |
| Post apagado ou tirado dos salvos | encolhe para a esquerda antes de sumir |
| Sino | balança uma vez **só quando o número de não lidas sobe** — aviso permanente vira ruído permanente |
| Busca e autocomplete | painel entra deslizando; item empurra o texto no hover |
| Barra lateral, chips, avatares | deslocamento leve no hover; botão primário afunda ao ser pressionado |
| Imagem do post | aparece com zoom-out ao carregar |

Dois detalhes de implementação que valem lembrar:

- O anel da curtida é um `::after` do próprio botão. Vinte posts na tela
  não viram vinte elementos a mais no DOM.
- A classe `animando` é removida no `animationend`. Deixá-la no elemento
  faria o segundo clique não animar nada — para o navegador, a animação
  já teria acontecido.
- **`prefers-reduced-motion: reduce` desliga tudo.** Não é enfeite de
  acessibilidade: animação de entrada em lista longa dá enjoo em quem tem
  sensibilidade vestibular.

### A marca ganhou movimento (01/09)

O nome do sistema virou a animação: a marca solta **anéis**, como som que
se espalha. Dois anéis com meio ciclo de diferença a cada 3,2 s na barra
lateral, a cerquilha respirando junto, e um brilho que atravessa as
letras de "ECHO" (gradiente recortado no texto — anima
`background-position`, não a cor letra a letra, e não exige um `span` por
caractere). No hover, a marca cresce, gira e o eco acelera para 1,1 s.

Vale nas oito telas sem tocar em nenhum HTML: os anéis são `::before` e
`::after` da própria marca.

Pegadinha de layout resolvida no caminho: na tela de entrada, o anel
precisa nascer no **centro do símbolo**, não no centro da coluna de
texto. Encolher a caixa do logo (`inline-flex`, depois `width:
fit-content`) centrava o anel, mas deslocava o símbolo em relação ao
título — elemento inline-level ainda herda o espaço em branco do HTML
antes dele. A saída foi deixar a caixa como estava e pendurar o anel no
`::after` do **ícone** (o `::before` é da Font Awesome, onde mora o
glifo). Medido numa cópia fiel da tela: logo, ícone e título alinhados no
mesmo x.

---

### Diálogo de confirmação: Enter deixou de confirmar (01/09)

O `EchoUIInstance.confirm()` fechava com `true` no Enter e abria com o
foco no botão **Confirmar**. Dois caminhos para o mesmo acidente: um
Enter distraído com o diálogo de apagar aberto apagava o post — aconteceu
durante os testes desta rodada.

Agora Esc cancela, Enter não confirma, e o foco inicial vai para
**Cancelar**. Confirmar exige o clique (ou Tab até o botão e então Enter,
que já é uma escolha). O padrão de um diálogo destrutivo é não fazer
nada.

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

Endpoints acrescentados depois: `posts/edit.php`,
`comments/delete.php`, `circles/delete.php`,
`messages/conversations.php`, `messages/mark_read.php`.

Em 01/09: `posts/save.php`, `comments/edit.php`,
`hashtags/trending.php`, `search/all.php` e
`auth/change_password.php` — mais os filtros `tag` e `saved` em
`posts/list.php`.

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

Para testar a invalidação de sessão: logar a mesma conta em dois cookie
jars diferentes (`curl -c a.txt` e `curl -c b.txt`), trocar a senha por
um deles e conferir que o outro passa a receber 401 — inclusive em
`me.php`.

---

## O que ficou de fora (candidatos a próximo passo)

Nada disso bloqueia o uso do sistema.

- **Busca dentro do chat e do círculo** — a busca global cobre pessoas,
  publicações, etiquetas e círculos, mas não o conteúdo das conversas.
- **Handle próprio, separado do e-mail** — hoje o `@handle` é a parte do
  e-mail antes do `@`, e por isso duas contas podem colidir (a menção
  ambígua é ignorada). Resolver de verdade pede uma coluna `handle`
  única em `users` e um migrador para as contas existentes.
- **Etiqueta em comentário** — `#tag` num comentário vira link, mas não
  entra na contagem da tendência; só o texto do post é indexado.
- **Notificação de menção em tempo real** — chega no sino pelo mesmo
  polling de 20 s das outras; não há push.
- **Apagar `api/auth/reset.php`** — está desativada com HTTP 410 desde
  28/08; pode sair quando ninguém mais chamar a rota antiga.
- **Splash do `index.html` em aba de segundo plano** — a animação GSAP usa
  `requestAnimationFrame`, que o navegador congela em aba escondida; o
  texto fica embaralhado até a aba ganhar foco. Se incomodar, dá para
  pular a animação quando `document.hidden` for verdadeiro.
