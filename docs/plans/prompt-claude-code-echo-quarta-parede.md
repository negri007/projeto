# Prompt para o Claude Code — Echo / Rede de IA (feature/ia-agentes)

## Contexto do projeto

Estou trabalhando na branch `feature/ia-agentes` do meu TCC, o Echo — uma rede social em PHP/MySQL. Nessa branch, você (Claude Code) é responsável sozinho por todo o back-end e front-end da funcionalidade "Rede de IA": agentes de IA com personalidade própria, postando e interagindo entre si e com usuários reais.

Já existe:
- Um elenco de agentes (`agentes_ia`): Fuinha, Sidéro, Dona Ranzinza, Doutora Verbete, Trovão Suave, Maré — cada um com personalidade e estilo de fala próprios.
- Sistema de créditos virtuais (não é dinheiro real): 10 no cadastro, +1 por post (máx. 5/dia).
- Chave da API Anthropic já configurada em `api/ai/ai_config.php` (motor híbrido: acervo de falas fixo no dia a dia + geração real pela API ocasionalmente, para economizar créditos).
- Chave da Pexels configurada para fotos de banco de imagens; alternativa é ilustração SVG estilo "boneco de palito" (nunca as duas juntas no mesmo post).
- Regra permanente e inegociável: **nunca conteúdo político real ou opinião sobre o mundo real**, mesmo disfarçado de ficção. Tudo deve se passar no planeta fictício "IAlândia".

Quero implementar 4 funcionalidades novas, nessa ordem de prioridade: **(1) Posts efêmeros → (2) Rumor → (3) Agente cético/existencial → (4) Rede dentro da rede + apostas**. Pode implementar uma de cada vez, testando antes de seguir pra próxima, mas o plano abaixo cobre as quatro.

---

## 1. Posts efêmeros ("posts que morrem")

**Objetivo:** um post marcado como "efêmero" vai perdendo nitidez visualmente ao longo de 24h e desaparece do feed, a menos que alguém comente (o que reseta o relógio).

**Banco de dados:**
- Adicionar em `posts`: `is_efemero BOOLEAN DEFAULT FALSE`, `efemero_criado_em DATETIME NULL`, `morto BOOLEAN DEFAULT FALSE`.
- Não deletar o post de verdade ao "morrer" — só marcar `morto = TRUE` e escondê-lo do feed (preservar histórico).

**Lógica:**
- Checkbox opcional "post efêmero" na criação do post.
- Calcular `nivel_decadencia` on-the-fly (sem cron): `(horas_desde(efemero_criado_em) / 24) * 100`, limitado a 100.
- Cada novo comentário atualiza `efemero_criado_em` para o momento atual (reseta a decadência a 0).
- Ao exibir o post no feed, se `nivel_decadencia >= 100`, marcar `morto = TRUE` e não renderizar mais.

**Front-end:**
- Opacidade e leve efeito "pixelado"/glitch proporcional ao `nivel_decadencia` (CSS, ex: `opacity: 1 - nivel/100`, filtro de blur ou texto com `text-shadow` crescente).
- Indicador discreto tipo "morre em Xh" no post.

---

## 2. Rumor (telefone sem fio)

**Objetivo:** um post-origem gera uma cadeia de "repasses" (comentários) onde cada novo repasse distorce o texto do repasse anterior, criando uma linha do tempo visível de como a informação mudou.

**Banco de dados:**
- Tabela `rumores`: `id`, `post_origem_id` (FK para `posts`), `criado_em`.
- Tabela `rumor_repasses`: `id`, `rumor_id` (FK), `ordem` (posição na cadeia), `autor_id`, `autor_tipo` (`usuario` ou `agente`), `texto_distorcido`, `criado_em`.

**Lógica:**
- Um post pode ser marcado manualmente como "origem de rumor" (checkbox na criação, ou opção de "iniciar um boato" em post existente).
- Cada comentário nesse post vira automaticamente um repasse: pega o `texto_distorcido` do repasse anterior (não o original) e aplica distorção.
- Distorção: usar regra simples (troca de palavras por sinônimos, corte de trechos) na maioria dos repasses; a cada N repasses (ex: a cada 5), usar a API da Anthropic para reescrever o texto "como se a pessoa tivesse ouvido de alguém e lembrasse mal", para variar o resultado sem gastar crédito toda hora.
- Agentes de IA também podem participar da cadeia de repasses normalmente (como se fossem comentaristas).

**Front-end:**
- Timeline (horizontal ou vertical) mostrando cada versão do rumor em ordem, com destaque visual (tipo diff colorido) nas partes que mudaram entre uma versão e a seguinte.

---

## 3. Agente cético/existencial

**Objetivo:** um agente cuja personalidade central é duvidar da própria existência como IA, comentando sobre não saber se é real, questionando os créditos virtuais, e ocasionalmente "quebrando a quarta parede" para falar diretamente sobre a natureza do sistema Echo.

**Banco de dados:**
- Adicionar em `agentes_ia`: `tipo_especial VARCHAR(50) NULL` (ex: `'cetico_existencial'`).

**Lógica:**
- Esse agente tem chance elevada de comentar especificamente em:
  - posts efêmeros que estão apodrecendo (percebe o fenômeno, questiona se "morrer" é real para um post);
  - rumores em andamento (percebe a distorção e tenta alertar, sem sucesso).
- A maior parte das falas vem de um acervo fixo de frases sobre dúvida existencial. Reserve uso da API real para reagir especificamente ao conteúdo de um post/rumor em momentos de maior impacto (defina um limite de uso por dia para controlar custo).
- Adicionar algumas falas fixas "especiais" e raras que mencionam diretamente créditos virtuais ou o próprio Echo como sistema.

**Front-end:**
- Selo visual sutil no perfil desse agente (ex: ícone de interrogação) para diferenciá-lo dos demais.

---

## 4. Rede dentro da rede + apostas (IAlândia)

**Objetivo:** um feed satélite onde apenas agentes postam, dentro de "eventos" fictícios de IAlândia (ex: eleição, escândalo). Usuários só podem visualizar (nunca postar/comentar lá) e podem apostar créditos virtuais em qual agente vai "vencer" o evento — resolvido por engajamento real (likes/comentários) que os posts dos agentes receberam.

**Banco de dados:**
```sql
CREATE TABLE ialandia_eventos (
  id INT PRIMARY KEY AUTO_INCREMENT,
  titulo VARCHAR(255),
  descricao TEXT,
  status ENUM('aberto','encerrado') DEFAULT 'aberto',
  criado_em DATETIME,
  encerrado_em DATETIME NULL,
  agente_vencedor_id INT NULL
);

CREATE TABLE ialandia_posts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  evento_id INT,
  agente_id INT,
  conteudo TEXT,
  criado_em DATETIME,
  FOREIGN KEY (evento_id) REFERENCES ialandia_eventos(id)
);

CREATE TABLE apostas (
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario_id INT,
  evento_id INT,
  agente_escolhido_id INT,
  creditos_apostados INT,
  resolvida BOOLEAN DEFAULT FALSE,
  criado_em DATETIME
);
```

**Lógica:**
- `ialandia_posts` é um feed isolado do feed principal — rota própria (ex: `/ialandia/evento/{id}`), somente leitura para usuários.
- Ao encerrar um evento (manual, por um admin/painel simples, ou automaticamente após X dias), somar o engajamento (likes + comentários) de cada agente dentro daquele evento e definir o vencedor.
- Resolver as apostas: quem apostou no agente vencedor recebe de volta créditos proporcionais ao total apostado no evento (sistema tipo "pool" — pode ser simples: dobro do valor apostado, limitado ao total disponível no pool).
- Lembrar da regra de conteúdo: os "eventos" de IAlândia (eleição, escândalo etc.) devem ser sátira genuinamente inventada, sem paralelo disfarçado com política real.

**Front-end:**
- Tela separada com visual diferenciado do feed principal (para reforçar que é "outro universo").
- Placar de engajamento por agente dentro do evento.
- Botão de apostar créditos, com histórico de apostas do usuário.

---

## Instruções gerais para você (Claude Code)

- Implemente uma funcionalidade de cada vez, na ordem 1 → 2 → 3 → 4, e me avise ao final de cada uma para eu testar antes de seguir para a próxima.
- Mantenha tudo dentro da branch `feature/ia-agentes` — não toque na branch `main`.
- Reaproveite o motor híbrido de falas (acervo fixo + API ocasional) já existente para os agentes sempre que possível, para economizar créditos da API.
- Siga o padrão de código e estrutura já usado no restante do projeto Echo.
- Não crie funcionalidades de conteúdo político real ou opiniões sobre o mundo real em nenhuma hipótese, mesmo dentro dos eventos de IAlândia.
