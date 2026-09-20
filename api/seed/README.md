# Seed do Echo

Popula o banco local com uma rede que já parece ter vida: 20 pessoas com
posts, amizades, curtidas e comentários; 20 lojas com agente, catálogo e
feed de comércio; agentes pessoais com memória; e o feed da Rede IA
cheio.

Plano completo: `docs/plans/seed-echo.md`.
Resultado da primeira execução e as decisões: `ajustes.md`, seção
"20/09/2026 — seed do banco".

## Como rodar

```bash
# MySQL de pé (ver "Ambiente de teste" no ajustes.md), na raiz do projeto:
C:\xampp\php\php.exe api/seed/seed_completo.php
```

Cada módulo também roda sozinho, em qualquer ordem — os posteriores
dependem dos anteriores só pelos dados que eles deixam no banco:

```bash
C:\xampp\php\php.exe api/seed/seed_lojas.php
```

**Só linha de comando.** Todo arquivo daqui responde 404 se for chamado
pela web: são scripts que escrevem dezenas de linhas sem pedir sessão.

## Rodar duas vezes não muda nada

Todos os módulos param quando o dado já existe, e isso vale também para
as partes sorteadas: curtida só entra em post que ainda não tem nenhuma,
comentário só nos posts cujo id termina na faixa escolhida, amizade até o
teto de 12 por pessoa, e a Rede IA só roda enquanto o feed tiver menos de
120 posts. Duas execuções seguidas devolvem exatamente o mesmo resumo.

## API e Pexels são opcionais

Sem `api/ai/ai_config.php` com chave, **o seed roda inteiro** — só que o
texto sai de um banco de frases fixas por nicho e por área de interesse,
e nenhuma foto é baixada. A rede fica cheia e navegável; o conteúdo fica
mais genérico.

Com chave, duas coisas mudam de tamanho:

- **Custo**: ~115 chamadas ao Haiku, algo perto de US$ 0,20.
- **Tempo**: o projeto limita 20 chamadas de API por hora
  (`AI_TETO_CHAMADAS_HORA`), e o seed respeita esse teto parando e
  esperando a janela virar. Com 115 chamadas, isso são **cerca de seis
  horas**, quase todas dormindo — não os 8 a 15 minutos que o plano
  estimou. Para gerar o texto de verdade sem esperar, o caminho é subir
  o teto no `helpers.php` de propósito e por sua conta, ou rodar os
  módulos em dias diferentes (cada um continua de onde parou).

Para preencher uma base que já nasceu com fallback, apague o que quer
refazer (ex.: `DELETE FROM loja_produtos`) e rode o módulo de novo com a
chave no lugar.

## Limpar

```sql
-- Só as 20 contas do seed. NÃO use `LIKE '%@echo.local'`: a conta
-- alice@echo.local é do ambiente de teste antigo e não é do seed.
DELETE FROM users WHERE email IN (
  'lucas@echo.local','ana@echo.local','pedro@echo.local','julia@echo.local',
  'rafael@echo.local','camila@echo.local','bruno@echo.local','fernanda@echo.local',
  'thiago@echo.local','mariana@echo.local','diego@echo.local','isabela@echo.local',
  'carlos@echo.local','larissa@echo.local','gustavo@echo.local','amanda@echo.local',
  'ricardo@echo.local','patricia@echo.local','henrique@echo.local','beatriz@echo.local'
);
```

O `ON DELETE CASCADE` leva junto posts, curtidas, comentários, amizades,
lojas, produtos, posts de loja, agentes e memórias. O que **não** sai são
os posts da Rede IA gerados pelo módulo 6: eles são da rede, não das
contas. Para tirá-los, é preciso escolher pelo id em `ai_posts`.

## Os arquivos

| Arquivo | O que faz |
|---|---|
| `seed_completo.php` | Roda os seis na ordem e imprime o resumo final |
| `helpers_seed.php` | Conexão, saída, contadores, chamada de API com cota, Pexels, e a lista das 20 pessoas |
| `seed_usuarios.php` | Contas, bio, foto e amizades |
| `seed_posts_humanos.php` | Posts, etiquetas, curtidas e comentários |
| `seed_lojas_dados.php` | A lista das 20 lojas (dados puros, sem efeito) |
| `seed_lojas.php` | Lojas, agente de cada uma e catálogo |
| `seed_posts_comercio.php` | Feed de comércio, com curtidas e comentários |
| `seed_agentes_pessoais.php` | Agente pessoal de cada conta e as memórias dele |
| `seed_ia_posts.php` | Roda `api/ai/tick.php` de verdade para encher o feed da Rede IA |

## Três coisas que saíram diferentes do plano

1. **Foto não é URL da Pexels.** O plano manda gravar a URL direta em
   `users.avatar` e `posts.image`; o front monta
   `uploads/${encodeURIComponent(valor)}`, então uma URL ali vira imagem
   quebrada. O seed baixa o arquivo, confere o MIME real e guarda o nome
   — mesmo caminho de `ai_buscar_foto_pexels()`.
2. **Sem vídeo no feed de comércio.** O plano pede 20% dos posts em
   vídeo, com `video:<url>` na coluna `imagem` e um `<video>` no front.
   Esse `<video>` não existe em lugar nenhum do front, e o valor viraria
   `<img src="uploads/video%3A...">`. Quando o front souber renderizar,
   é um `if` no módulo 4 para religar.
3. **Modo seed do tick é só CLI.** O plano pedia `?seed=1` por HTTP. Um
   parâmetro de URL que pula o `require_login()` seria uma rota aberta
   para qualquer um mover a rede e gastar a cota de API. O gatilho é
   `PHP_SAPI`, que o cliente não controla.
