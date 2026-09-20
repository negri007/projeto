# Plano — Agente Echo (pessoal + comercial)

> Salvar em `docs/plans/plano-agente-echo.md` antes de executar.
> Leia o `ajustes.md` completo antes de começar. Todo contexto do projeto está lá.
> Branch: `feature/agente-echo`

---

## Visão geral

Duas modalidades de agente num mesmo fluxo de criação:

- **Agente Pessoal** — aprende com o comportamento do usuário na rede e age no lugar dele conforme a autonomia configurada.
- **Agente Comercial** — representa uma loja, responde clientes, exibe produtos e conduz o usuário ao WhatsApp para fechar a compra.

A entrada é sempre pela mesma aba — **"Criar seu Echo"** — onde o usuário escolhe qual tipo quer e segue o fluxo correspondente. Se voltar depois, entra no painel de edição do agente que já criou.

---

## Navegação

Adiciona o item **"Criar seu Echo"** no menu lateral e no menu mobile de todas as páginas logadas, logo abaixo de "Explorar". O ícone sugerido é `fa-robot` (Font Awesome já carregado).

---

## Schema — `banco.sql` (idempotente)

### Agente pessoal

```sql
-- Um agente por usuário
CREATE TABLE IF NOT EXISTS user_agents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  nome VARCHAR(100) NOT NULL DEFAULT 'Meu Echo',
  personalidade TEXT,
  autonomia TINYINT NOT NULL DEFAULT 0,
  -- 0 = só aprende
  -- 1 = sugere, usuário aprova
  -- 2 = age sozinho, usuário pode desfazer em 24h
  -- 3 = autonomia total (requer confirmação explícita)
  ativo TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- O que o agente aprendeu sobre o dono
CREATE TABLE IF NOT EXISTS user_agent_memoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tipo ENUM('post','comentario','curtida','mensagem','busca') NOT NULL,
  conteudo TEXT NOT NULL,
  peso TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_uam_user ON user_agent_memoria(user_id, created_at);

-- Sugestões pendentes de aprovação
CREATE TABLE IF NOT EXISTS user_agent_sugestoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tipo ENUM('post','resposta','comentario','curtida') NOT NULL,
  contexto TEXT,
  sugestao TEXT NOT NULL,
  status ENUM('pendente','aprovada','rejeitada','expirada') NOT NULL DEFAULT 'pendente',
  referencia_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Agente comercial / loja

```sql
-- Cadastro da loja (um por usuário)
CREATE TABLE IF NOT EXISTS lojas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  nome VARCHAR(150) NOT NULL,
  descricao TEXT,
  categoria VARCHAR(100),
  cnpj VARCHAR(18) NULL,
  telefone VARCHAR(20) NULL,
  whatsapp VARCHAR(20) NULL,
  site VARCHAR(255) NULL,
  logo VARCHAR(255) NULL,
  banner VARCHAR(255) NULL,
  ativo TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Agente da loja
CREATE TABLE IF NOT EXISTS loja_agente (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL UNIQUE,
  instrucoes TEXT,
  saudacao VARCHAR(500) DEFAULT 'Olá! Como posso ajudar?',
  modelo ENUM('haiku','sonnet') NOT NULL DEFAULT 'haiku',
  ativo TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE
);

-- Produtos da loja
CREATE TABLE IF NOT EXISTS loja_produtos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL,
  nome VARCHAR(200) NOT NULL,
  descricao TEXT NULL,
  preco DECIMAL(10,2) NULL,
  imagem VARCHAR(255) NULL,
  disponivel TINYINT NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE
);

-- Posts do feed de comércio
CREATE TABLE IF NOT EXISTS loja_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL,
  conteudo TEXT NOT NULL,
  imagem VARCHAR(255) NULL,
  tipo ENUM('produto','promocao','novidade','info') NOT NULL DEFAULT 'produto',
  preco DECIMAL(10,2) NULL,
  produto_id INT NULL,
  ativo TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES loja_produtos(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_loja_posts_feed ON loja_posts(created_at DESC, ativo);

-- Curtidas nos posts de comércio
CREATE TABLE IF NOT EXISTS loja_post_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_post_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_lpl (loja_post_id, user_id),
  FOREIGN KEY (loja_post_id) REFERENCES loja_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Comentários nos posts de comércio
CREATE TABLE IF NOT EXISTS loja_post_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_post_id INT NOT NULL,
  user_id INT NOT NULL,
  conteudo TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_post_id) REFERENCES loja_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Reports de conteúdo
CREATE TABLE IF NOT EXISTS loja_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_post_id INT NULL,
  loja_id INT NULL,
  user_id INT NOT NULL,
  motivo ENUM('spam','conteudo_inapropriado','produto_falso','golpe','outro') NOT NULL,
  descricao TEXT NULL,
  status ENUM('pendente','revisado','resolvido') NOT NULL DEFAULT 'pendente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loja_post_id) REFERENCES loja_posts(id) ON DELETE SET NULL,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Chat cliente com agente da loja
CREATE TABLE IF NOT EXISTS loja_chats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_lc (loja_id, user_id),
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS loja_chat_mensagens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chat_id INT NOT NULL,
  role ENUM('user','agent') NOT NULL,
  conteudo TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chat_id) REFERENCES loja_chats(id) ON DELETE CASCADE
);

-- Carrinho (temporário, por usuário e loja)
CREATE TABLE IF NOT EXISTS loja_carrinho (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  loja_id INT NOT NULL,
  produto_id INT NOT NULL,
  quantidade INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_lcar (user_id, loja_id, produto_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES loja_produtos(id) ON DELETE CASCADE
);
```

---

## Back-end

### `api/user_agent/helpers.php`

Funções:
- `user_agent_existe($pdo, $user_id)` → bool
- `user_agent_criar($pdo, $user_id, $nome_usuario)` → cria com autonomia 0
- `user_agent_registrar_acao($pdo, $user_id, $tipo, $conteudo)` → grava memória, limita a 500 por usuário (apaga os mais antigos)
- `user_agent_contexto($pdo, $user_id, $limite=50)` → retorna string formatada das últimas memórias para usar como contexto no prompt
- `user_agent_gerar_sugestao_post($pdo, $user_id)` → chama API com contexto e retorna sugestão de post no estilo do usuário
- `user_agent_gerar_sugestao_resposta($pdo, $user_id, $mensagem)` → retorna sugestão de resposta para uma mensagem recebida
- `user_agent_limpar_expiradas($pdo, $user_id)` → marca como expiradas sugestões com mais de 24h

Usa `ai_chamar_api()` de `api/ai/helpers.php` já existente. Nunca cria nova função de chamada de API.

### Endpoints `api/user_agent/`

| Arquivo | Método | O que faz |
|---|---|---|
| `status.php` | GET | Retorna se agente existe, autonomia, total de memórias, sugestões pendentes |
| `configurar.php` | POST | Cria ou atualiza nome, personalidade, autonomia |
| `sugestoes.php` | GET | Lista sugestões pendentes |
| `sugestao_responder.php` | POST | `{sugestao_id, acao: aprovar/rejeitar}` |
| `gerar_sugestao_post.php` | POST | Dispara geração manual de sugestão de post |

### Ganchos de aprendizado nos endpoints existentes

Adiciona chamada a `user_agent_registrar_acao()` em:
- `api/posts/create.php` — após INSERT do post
- `api/comments/create.php` — após INSERT do comentário
- `api/posts/like.php` — quando like é novo (não descurtida)
- `api/messages/send.php` — após INSERT da mensagem
- `api/auth/me.php` — se agente não existe ainda, chama `user_agent_criar()`

### `api/lojas/helpers.php`

Funções:
- `loja_do_usuario($pdo, $user_id)` → loja ou NULL
- `loja_agente_responder($pdo, $loja_id, $historico, $mensagem)` → chama API com instruções da loja como system prompt + histórico. Fallback: mensagem pedindo para entrar em contato pelo WhatsApp
- `loja_gerar_link_whatsapp($whatsapp, $itens)` → gera `wa.me/` com lista formatada
- `loja_produtos_mencionados($pdo, $loja_id, $texto)` → detecta nomes de produtos no texto da resposta do agente e retorna os ids correspondentes

### Endpoints `api/lojas/`

| Arquivo | Método | O que faz |
|---|---|---|
| `cadastrar.php` | POST | Cria loja (uma por usuário) |
| `atualizar.php` | POST | Edita dados (só dono) |
| `perfil.php` | GET | Dados públicos por `loja_id` |
| `agente_configurar.php` | POST | Salva instruções e saudação |
| `feed.php` | GET | Feed de comércio paginado por cursor |
| `post_criar.php` | POST | Lojista cria post |
| `post_like.php` | POST | Curtir/descurtir |
| `post_comment.php` | POST | Comentar |
| `post_comments.php` | GET | Listar comentários por `loja_post_id` |
| `report.php` | POST | Reportar post ou loja |
| `chat_mensagem.php` | POST | Envia mensagem, retorna resposta do agente + `produtos_mencionados` |
| `chat_historico.php` | GET | Histórico da conversa por `loja_id` |
| `produtos.php` | GET | Catálogo da loja por `loja_id` |
| `produto_criar.php` | POST | Adiciona produto |
| `produto_editar.php` | POST | Edita produto |
| `produto_apagar.php` | POST | Remove produto |
| `carrinho_adicionar.php` | POST | Adiciona item |
| `carrinho_remover.php` | POST | Remove item |
| `carrinho.php` | GET | Itens do carrinho por `loja_id` |
| `carrinho_finalizar.php` | POST | Gera link WhatsApp e limpa carrinho |

---

## Front-end — páginas

### `meu_echo.html` — "Criar seu Echo"

Página única que serve tanto para criação quanto para edição. Se o usuário já tem agente, entra no modo edição automaticamente.

**Fluxo de criação (primeira vez):**

Tela 1 — Escolha do tipo:
- Título: **"Qual Echo você quer criar?"**
- Dois cards grandes clicáveis:
  - **Echo Pessoal** — "Aprende com você e age no Echo no seu lugar" + ícone pessoa
  - **Echo da Loja** — "Representa sua loja, responde clientes e mostra seus produtos" + ícone loja
- Texto pequeno embaixo: *"Você pode ter um de cada."*

Tela 2A — Configuração do Echo Pessoal:
- **Nome do seu Echo** — campo texto, placeholder "Como você quer chamar seu agente?"
- **Como ele fala?** — textarea com texto explicativo curto acima: *"Descreva o jeito que você quer que ele escreva. Exemplo: 'Fala de forma direta, usa gírias, não é formal, usa emoji às vezes.'"*
- **Autonomia** — quatro opções visuais em cards pequenos:
  - 🔍 **Só observa** — "Aprende com você, não faz nada ainda."
  - 💡 **Sugere** — "Propõe respostas e posts. Você aprova tudo."
  - ⚡ **Age sozinho** — "Age no seu lugar. Você pode desfazer em 24h."
  - 🤖 **Autônomo total** — "Faz tudo sozinho. Requer confirmação especial."
- Botão **"Criar meu Echo"**

Tela 2B — Configuração do Echo da Loja:
- **Nome da loja** — campo texto
- **Categoria** — select (Alimentação, Moda, Tecnologia, Beleza, Serviços, Saúde, Outro)
- **Descrição** — textarea curta, placeholder "O que sua loja vende? Em uma frase."
- **WhatsApp da loja** — campo telefone, texto explicativo: *"Seus clientes serão direcionados para cá ao finalizar o pedido."*
- **O que seu agente deve saber?** — textarea grande, placeholder: *"Coloque aqui tudo que seu agente precisa saber para atender bem: produtos, preços, horários, formas de pagamento, política de troca... Quanto mais detalhes, melhor ele atende."*
- **Primeira mensagem ao cliente** — campo texto, placeholder "Olá! Como posso ajudar?"
- Botão **"Criar Echo da Loja"**

Tela 3 — Confirmação:
- Animação do Bit celebrando (se estiver ativo)
- Texto: *"Seu Echo foi criado! Agora ele começa a aprender com você."* (pessoal) ou *"Seu Echo da loja está pronto! Você já pode postar no feed de comércio."* (comercial)
- Botões: **"Ver meu Echo"** (volta para a mesma página em modo edição) e **"Ir para o feed"**

**Modo edição (usuário já tem agente):**

Mostra abas no topo:
- **Echo Pessoal** — formulário com os campos preenchidos, mais:
  - Card de status: "X memórias coletadas", "X sugestões pendentes" com link para ver
  - Lista de sugestões pendentes com botões Aprovar/Rejeitar
  - Botão "Gerar sugestão de post agora"
- **Echo da Loja** — formulário com dados da loja, mais:
  - Aba interna **Agente** — instruções e saudação
  - Aba interna **Produtos** — lista de produtos com adicionar/editar/remover e toggle de disponibilidade
  - Aba interna **Posts** — lista dos próprios posts com opção de apagar
  - Contadores: curtidas recebidas, comentários, chats iniciados

Textos explicativos em todos os campos, curtos e diretos. Nenhum campo sem exemplo de preenchimento.

---

### `comercio.html` — Feed de comércio

Layout de duas colunas igual ao `inicio.html`:
- Coluna principal: feed de posts de lojas
- Coluna direita: categorias para filtrar, lojas em destaque (as com mais interações), botão "Abrir minha loja" (vai para `meu_echo.html` aba loja)

**Card de post de loja:**
- Logo da loja (pequeno, redondo) + nome da loja + categoria + tempo relativo
- Imagem do post (quando houver)
- Tipo do post em badge colorido (Produto / Promoção / Novidade / Info)
- Preço em destaque quando houver
- Conteúdo do post
- Botões: Curtir (com contador), Comentar, Compartilhar, Reportar (ícone discreto)
- Botão principal: **"Falar com a loja"** — vai para `loja_chat.html?loja_id=`
- Clicar no nome ou logo da loja vai para `loja_perfil.html?loja_id=`

Paginação por cursor igual ao feed humano. Filtro por categoria no topo.

---

### `loja_perfil.html?loja_id=` — Perfil público da loja

Seções em ordem:
1. **Banner + logo + nome + descrição + categoria + contatos**
2. **Produtos** — grid com imagem, nome, preço, botão "Adicionar ao carrinho". Sem imagem: card só com nome e preço.
3. **Posts da loja** — feed dos posts daquela loja específica, igual ao card do `comercio.html`
4. **Botão report da loja** — discreto no rodapé

Carrinho flutuante no canto inferior direito quando tiver item: badge com quantidade, abre modal ao clicar.

Modal do carrinho:
- Lista de itens com quantidade editável (+/-)
- Total em tempo real
- Botão **"Finalizar pelo WhatsApp"** — chama `carrinho_finalizar.php` e abre o link gerado

Botão **"Falar com a loja"** fixo no rodapé da página em mobile, flutuante no desktop.

---

### `loja_chat.html?loja_id=` — Chat com agente da loja

Interface igual ao `chat.html` existente, adaptada:
- Avatar da loja no lugar do avatar do usuário
- Nome da loja no cabeçalho
- Primeira mensagem é a saudação configurada pelo lojista, carregada via `chat_historico.php`
- Quando o agente menciona produto, aparece card inline com imagem, nome, preço e botão "Adicionar ao carrinho"
- Rodapé discreto: *"Respondido pelo Echo da [nome da loja]"*
- Link "Falar com humano" quando whatsapp da loja estiver cadastrado

Poll de 1s enquanto aguarda resposta do agente (status de digitando).

---

## Regras que valem para tudo

- Agente pessoal: nível 3 só é liberado se o usuário digitar "confirmo autonomia total" num campo de confirmação dedicado
- Toda ação do agente pessoal em nível 2 ou 3 é marcada com `· via Echo` para quem recebe
- Agente pessoal nunca age em círculos, só mensagens diretas e feed
- Agente da loja nunca inventa produto ou preço — se não souber, passa o WhatsApp
- Imagens de logo, banner e produto passam pela mesma validação de MIME que o upload de avatar
- Lojista não pode comentar nos próprios posts pelo feed
- Report fica registrado no banco sem painel de moderação por enquanto
- Carrinho é finalizado sempre pelo WhatsApp — sem pagamento dentro do app
- Nenhum endpoint aceita user_id ou loja_id do cliente como identidade — sempre da sessão

---

## O que fica de fora nesta fase

- Painel de moderação de reports
- Pagamento dentro do app
- Login por telefone/SMS
- Notificação push
- Múltiplas lojas por usuário
- Agente pessoal agindo em círculos
