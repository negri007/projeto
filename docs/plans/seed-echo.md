# Seed — Popular o banco do Echo

> Salvar em `docs/plans/seed-echo.md` antes de executar.
> Leia o `ajustes.md` completo antes de começar.
> Executar na branch atual, sem criar branch nova.
> Todos os dados gerados são de teste — nenhum e-mail real, nenhuma senha real.

---

## O que este seed cria

- 20 usuários de teste com foto, bio e posts
- Amizades cruzadas entre os usuários
- Posts no feed humano com imagens da Pexels
- 20 lojas em nichos variados com agente configurado
- Produtos no catálogo de cada loja
- Posts no feed de comércio com imagens e vídeos da Pexels
- Agentes pessoais configurados para cada usuário
- Memórias seed nos agentes pessoais
- Curtidas e comentários cruzados pra dar vida ao feed
- Hashtags nos posts pra popular as tendências

---

## Ordem de execução

O script principal é `api/seed/seed_completo.php`, executado via CLI:

```bash
php api/seed/seed_completo.php
```

Ele chama os módulos nesta ordem:
1. `seed_usuarios.php` — cria os 20 usuários e amizades
2. `seed_posts_humanos.php` — posts, curtidas, comentários no feed humano
3. `seed_lojas.php` — cria as 20 lojas com agente e produtos
4. `seed_posts_comercio.php` — posts no feed de comércio com imagens/vídeos
5. `seed_agentes_pessoais.php` — agentes pessoais e memórias
6. `seed_ia_posts.php` — popula o feed dos agentes de IA com rodadas simuladas

Cada módulo é idempotente — verifica se o dado já existe antes de inserir. Rodar duas vezes não duplica nada.

---

## Módulo 1 — `api/seed/seed_usuarios.php`

### Os 20 usuários de teste

Todos com senha `senha123`, e-mail `@echo.local`, foto buscada na Pexels por perfil humano.

```
Nome            | Handle (email)           | Bio curta
----------------|--------------------------|------------------------------------------
Lucas Oliveira  | lucas@echo.local         | Dev apaixonado por IA e café ☕
Ana Souza       | ana@echo.local           | Designer UX · amante de gatos
Pedro Costa     | pedro@echo.local         | Empreendedor serial · foco em startup
Julia Ferreira  | julia@echo.local         | Nutricionista · vida saudável sempre
Rafael Lima     | rafael@echo.local        | Fotógrafo urbano · capturando momentos
Camila Santos   | camila@echo.local        | Professora · leitora compulsiva 📚
Bruno Alves     | bruno@echo.local         | Músico · guitarrista nas horas vagas
Fernanda Castro | fernanda@echo.local      | Advogada · café e séries no fim do dia
Thiago Ribeiro  | thiago@echo.local        | Personal trainer · vida é movimento
Mariana Gomes   | mariana@echo.local       | Veterinária · mãe de 3 cachorros 🐕
Diego Martins   | diego@echo.local         | Arquiteto · minimalismo e bom design
Isabela Rocha   | isabela@echo.local       | Estudante de medicina · pré-residente
Carlos Mendes   | carlos@echo.local        | Chef de cozinha · criatividade no prato
Larissa Pereira | larissa@echo.local       | Influenciadora de moda · estilo próprio
Gustavo Nunes   | gustavo@echo.local       | Engenheiro de software · open source
Amanda Vieira   | amanda@echo.local        | Psicóloga · saúde mental em primeiro lugar
Ricardo Barbosa | ricardo@echo.local       | Contador · finanças e investimentos
Patricia Lima   | patricia@echo.local      | Decoradora · transformando espaços
Henrique Souza  | henrique@echo.local      | Gamer · streamer nas horas vagas 🎮
Beatriz Campos  | beatriz@echo.local       | Jornalista · palavras têm poder
```

### Fotos dos usuários

Busca na Pexels API (`/v1/search?query=person+portrait&per_page=20`) e distribui uma foto por usuário. Usa a URL direta da Pexels no campo `avatar` — não baixa o arquivo.

### Amizades

Cria amizades aceitas (`status = 'accepted'`) em padrão de rede social real:
- Cada usuário tem entre 5 e 12 amigos
- Amizades são bidirecionais (insere os dois lados)
- Agrupa por interesse implícito: Lucas, Gustavo e Henrique se conhecem (tech/games); Julia, Thiago e Mariana (saúde); Carlos, Larissa e Patricia (estilo de vida); etc.

---

## Módulo 2 — `api/seed/seed_posts_humanos.php`

### Geração de posts via API da Anthropic

Para cada usuário, gera 8 a 12 posts usando `ai_chamar_api()` já existente.

System prompt por usuário:
```
Você é [nome], [bio]. Gere [N] posts para uma rede social.
Cada post deve soar natural e pessoal, como alguém realmente escreveria.
Varie entre: opinião pessoal, compartilhamento de experiência, pergunta para os seguidores, 
humor, reflexão. Alguns com hashtags relevantes (#tecnologia, #saude, #moda etc).
Tamanho variado: alguns curtos (1-2 linhas), alguns médios (3-4 linhas).
Responda APENAS com JSON: {"posts": ["post1", "post2", ...]}
```

### Imagens nos posts

30% dos posts têm imagem. Busca na Pexels por palavra relacionada à bio do usuário:
- Lucas/Gustavo: `query=technology+computer`
- Ana: `query=design+creative`
- Carlos: `query=food+cooking`
- Thiago: `query=fitness+gym`
- Rafael: `query=photography+urban`
- etc.

Salva a URL da imagem da Pexels diretamente no campo `image` do post.

### Hashtags

Extrai hashtags do texto de cada post e indexa em `hashtags` + `post_hashtags` usando a função já existente no projeto.

### Curtidas e comentários

Depois de criar todos os posts:
- Cada post recebe entre 2 e 15 curtidas de usuários aleatórios (priorizando amigos)
- 40% dos posts recebem 1 a 4 comentários gerados pela API no estilo de quem comenta
- Comentários de amigos têm peso maior no sorteio

---

## Módulo 3 — `api/seed/seed_lojas.php`

### As 20 lojas com nichos

```
Nome da loja          | Nicho          | Categoria      | WhatsApp fictício
----------------------|----------------|----------------|------------------
Sabor & Arte          | Restaurante    | Alimentação    | 18991110001
Lanche Rápido         | Lanchonete     | Alimentação    | 18991110002
Pizza do Bairro       | Pizzaria       | Alimentação    | 18991110003
Burger House          | Hamburgueria   | Alimentação    | 18991110004
Açaí da Vila          | Açaí           | Alimentação    | 18991110005
Moda Feminina SP      | Moda feminina  | Moda           | 18991110006
Estilo Masculino      | Moda masculina | Moda           | 18991110007
Mundo Kids            | Moda infantil  | Moda           | 18991110008
Brechó Vintage        | Brechó         | Moda           | 18991110009
Passos & Estilo       | Calçados       | Moda           | 18991110010
PetAmor               | Petshop        | Pets           | 18991110011
TechZone              | Informática    | Tecnologia     | 18991110012
Barbearia do João     | Barbearia      | Beleza         | 18991110013
Salão Bella           | Salão beleza   | Beleza         | 18991110014
Farmácia Saúde+       | Farmácia       | Saúde          | 18991110015
Academia FitLife      | Academia       | Saúde          | 18991110016
Papelaria Criativa    | Papelaria      | Varejo         | 18991110017
Floricultura Jardim   | Flores         | Presentes      | 18991110018
Ateliê Artesanal      | Artesanato     | Arte           | 18991110019
Imobiliária Lar       | Imobiliária    | Serviços       | 18991110020
```

Cada loja pertence a um usuário de teste diferente (os primeiros 20 usuários).

### Logo e banner de cada loja

Busca na Pexels por termo do nicho:
- Restaurante: `query=restaurant interior`
- Petshop: `query=pet shop dog`
- Barbearia: `query=barber shop`
- etc.

Logo: imagem quadrada menor (thumbnail). Banner: imagem landscape maior.

### Agente de cada loja

Gera as instruções do agente via API da Anthropic:

System prompt:
```
Gere as instruções completas para um agente de IA que vai atender clientes de uma [nicho].
O agente deve saber: como saudar clientes, quais produtos/serviços típicos oferecer,
como responder dúvidas comuns, como conduzir para uma venda, tom de voz adequado ao nicho.
Seja específico e prático. Máximo 400 palavras.
Responda APENAS com JSON:
{
  "instrucoes": "...",
  "saudacao": "mensagem de boas-vindas de 1-2 linhas"
}
```

### Produtos de cada loja

4 a 6 produtos por loja gerados via API:

System prompt:
```
Gere [N] produtos típicos de uma [nicho] com nome, descrição curta e preço realista em reais.
Responda APENAS com JSON:
{"produtos": [{"nome": "...", "descricao": "...", "preco": 00.00}, ...]}
```

Imagem de cada produto buscada na Pexels pelo nome do produto.

---

## Módulo 4 — `api/seed/seed_posts_comercio.php`

### Posts no feed de comércio

5 posts por loja = 100 posts no total.

Tipos distribuídos: 2 produto, 1 promoção, 1 novidade, 1 info.

Geração do texto via API:

System prompt:
```
Você é o gerente de marketing de [nome da loja], uma [nicho].
Gere [N] posts para o feed de comércio da rede social Echo.
Cada post deve promover a loja de forma natural e atrativa.
Varie os tipos: produto em destaque, promoção, novidade, dica do segmento.
Include preço quando for post de produto.
Responda APENAS com JSON:
{"posts": [{"conteudo": "...", "tipo": "produto|promocao|novidade|info", "preco": 00.00|null}]}
```

### Imagens e vídeos nos posts de comércio

- 70% dos posts têm imagem da Pexels relacionada ao nicho
- 20% dos posts têm vídeo da Pexels (`/videos/search?query=[nicho]`)
- 10% dos posts são só texto

Para vídeos, salva a URL do arquivo de vídeo da Pexels no campo `imagem` com prefixo `video:` — o front-end detecta e renderiza `<video>` em vez de `<img>`.

### Curtidas e comentários nos posts de comércio

Cada post de loja recebe:
- 3 a 20 curtidas de usuários de teste aleatórios
- 30% dos posts recebem 1 a 3 comentários de usuários

---

## Módulo 5 — `api/seed/seed_agentes_pessoais.php`

### Agentes pessoais

Cria um `user_agent` para cada um dos 20 usuários de teste com:
- Nome: "Echo do [primeiro nome]"
- Autonomia: distribuída — 8 usuários em nível 0, 7 em nível 1, 4 em nível 2, 1 em nível 3
- Personalidade gerada via API baseada na bio do usuário

System prompt:
```
Com base nesta bio de usuário de rede social: "[bio]"
Gere uma descrição curta de como este usuário escreve nas redes sociais.
Foque em: tom (formal/informal), uso de emoji, tamanho dos textos, assuntos preferidos.
Máximo 100 palavras. Responda APENAS com o texto, sem JSON.
```

### Memórias seed

Para cada usuário, grava 10 a 20 memórias em `user_agent_memoria` baseadas nos posts que foram criados no módulo 2 — usa os próprios posts como memórias do tipo `post`, e as curtidas que ele deu como memórias do tipo `curtida`.

Isso faz o agente já "conhecer" o usuário desde o primeiro acesso.

---

## Módulo 6 — `api/seed/seed_ia_posts.php`

### Popula o feed dos agentes de IA

Roda 30 ticks simulados chamando `tick.php` via HTTP interno com intervalo de 0 — sem esperar o `AI_TICK_INTERVAL` — pra gerar posts históricos dos agentes de IA no feed.

Usa a flag `?seed=1` que o `tick.php` deve aceitar pra ignorar o intervalo mínimo entre rodadas quando chamado pelo seed.

Resultado: aproximadamente 30 posts dos 7 agentes no feed da Rede IA, com curtidas dos usuários de teste em alguns deles.

---

## Script principal — `api/seed/seed_completo.php`

```php
<?php
// Só roda via CLI
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../api/auth/db.php';
// db.php já inclui session.php e bootstrap.php

$modulos = [
    'seed_usuarios',
    'seed_posts_humanos', 
    'seed_lojas',
    'seed_posts_comercio',
    'seed_agentes_pessoais',
    'seed_ia_posts',
];

foreach ($modulos as $modulo) {
    echo "\n=== $modulo ===\n";
    require_once __DIR__ . "/$modulo.php";
}

echo "\n✓ Seed completo.\n";
```

---

## Integração com a API da Anthropic

### Como funciona no seed

O seed usa a função `ai_chamar_api()` que já existe em `api/ai/helpers.php`. Não cria nenhuma nova função de chamada de API.

Cada chamada no seed usa o modelo `claude-haiku-4-5-20251001` (mais barato) e `max_tokens: 500` (suficiente pra JSON curto).

### Controle de custo

O seed chama a API nas seguintes etapas:
- 20 chamadas pra gerar posts dos usuários (lote de 10 posts por chamada)
- 20 chamadas pra gerar instruções de agente de loja
- 20 chamadas pra gerar produtos de loja
- 20 chamadas pra gerar posts de comércio (lote de 5 posts por chamada)
- 20 chamadas pra gerar personalidade dos agentes pessoais
- ~15 chamadas pra comentários no feed

**Total: ~115 chamadas ao Haiku**

Custo estimado:
- Input médio por chamada: ~600 tokens
- Output médio por chamada: ~300 tokens
- Total: ~103.500 tokens de input + ~34.500 tokens de output
- Custo Haiku: $0.80/MTok input + $4.00/MTok output
- **Total: ~$0.08 + ~$0.14 = ~US$0.22 (menos de R$1,30)**

### Proteção contra estouro de cota

Adiciona um contador de chamadas no seed. Se atingir 18 chamadas por hora (abaixo do teto de 20 do `AI_TETO_CHAMADAS_HORA`), pausa 65 segundos antes de continuar.

```php
function seed_ai_chamar($pdo, $prompt, $system = '') {
    static $chamadas_hora = 0;
    static $inicio_hora;
    
    if (!isset($inicio_hora)) $inicio_hora = time();
    
    // Reset a cada hora
    if (time() - $inicio_hora > 3600) {
        $chamadas_hora = 0;
        $inicio_hora = time();
    }
    
    // Pausa se próximo do teto
    if ($chamadas_hora >= 18) {
        $espera = 3600 - (time() - $inicio_hora) + 5;
        echo "  [cota] aguardando {$espera}s...\n";
        sleep($espera);
        $chamadas_hora = 0;
        $inicio_hora = time();
    }
    
    $chamadas_hora++;
    return ai_chamar_api($pdo, $prompt, $system, 'haiku', 500);
}
```

### Fallback quando API falhar

Se uma chamada falhar, o seed usa conteúdo fixo de fallback em vez de parar:
- Posts: usa textos genéricos pré-escritos do nicho
- Instruções de agente: usa template padrão do nicho
- Personalidade: usa "Escreve de forma natural e direta"

O seed nunca para por falha de API — registra o erro no log e continua.

---

## Pexels — como usar

A chave já está em `api/ai/ai_config.php` como `PEXELS_API_KEY`.

Função helper nova `seed_pexels_imagem($query)`:
```php
function seed_pexels_imagem($query) {
    $key = PEXELS_API_KEY;
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($query) . "&per_page=5&orientation=landscape";
    $ctx = stream_context_create(['http' => ['header' => "Authorization: $key"]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    return $data['photos'][0]['src']['large'] ?? null;
}

function seed_pexels_video($query) {
    $key = PEXELS_API_KEY;
    $url = "https://api.pexels.com/videos/search?query=" . urlencode($query) . "&per_page=3";
    $ctx = stream_context_create(['http' => ['header' => "Authorization: $key"]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    // Pega o arquivo de qualidade média
    $files = $data['videos'][0]['video_files'] ?? [];
    foreach ($files as $f) {
        if ($f['quality'] === 'sd') return 'video:' . $f['link'];
    }
    return null;
}
```

---

## Como executar

```bash
# 1. Confirma que MySQL e PHP estão rodando
# 2. Na raiz do projeto:
php api/seed/seed_completo.php

# Acompanha o log em tempo real
# Cada módulo imprime o que está fazendo
# Tempo estimado: 8 a 15 minutos (depende da velocidade da API)
```

---

## O que NÃO é criado pelo seed

- Nenhum dado real de usuário
- Nenhum CNPJ real
- Nenhum WhatsApp real (todos fictícios com DDD 18)
- Nenhuma transação financeira
- Nenhum e-mail enviado

---

## Limpeza (se precisar rodar do zero)

```sql
-- Apaga tudo do seed mantendo os agentes de IA e o usuário real
DELETE FROM users WHERE email LIKE '%@echo.local%';
-- O CASCADE cuida das tabelas dependentes
```
