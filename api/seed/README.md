# Seed do Echo

Popula o banco local com uma rede humana que já parece ter vida: 20
pessoas com bio, foto, amizades, posts, curtidas e comentários.

> Depois do corte de escopo (01/10/2026) o seed cobre só a rede humana.
> As verticais removidas (rede de IAs, agente pessoal, comércio, vídeo)
> saíram do projeto, e com elas os módulos de seed que as enchiam.

Plano original: `docs/plans/seed-echo.md`.
Resultado da primeira execução e as decisões: `ajustes.md`, seção
"20/09/2026 — seed do banco".

## Como rodar

```bash
# MySQL de pé (ver "Ambiente de teste" no ajustes.md), na raiz do projeto:
C:\xampp\php\php.exe api/seed/seed_completo.php
```

Cada módulo também roda sozinho — o de posts depende do de usuários só
pelos dados que ele deixa no banco:

```bash
C:\xampp\php\php.exe api/seed/seed_posts_humanos.php
```

**Só linha de comando.** Todo arquivo daqui responde 404 se for chamado
pela web: são scripts que escrevem dezenas de linhas sem pedir sessão.

## Rodar duas vezes não muda nada

Os dois módulos param quando o dado já existe, e isso vale também para as
partes sorteadas: curtida só entra em post que ainda não tem nenhuma,
comentário só nos posts cujo id termina na faixa escolhida, e amizade até
o teto de 12 por pessoa. Duas execuções seguidas devolvem exatamente o
mesmo resumo.

## API e Pexels são opcionais

Sem `api/ai/ai_config.php` com chave, **o seed roda inteiro** — só que o
texto dos posts sai de um banco de frases fixas por área de interesse, e
nenhuma foto é baixada. A rede fica cheia e navegável; o conteúdo fica
mais genérico.

Com chave, o texto dos posts vem da API (Haiku), e as fotos de avatar e
de post vêm da Pexels (precisa também de `pexels_api_key` no
`ai_config.php`). O projeto limita 20 chamadas de API por hora
(`AI_TETO_CHAMADAS_HORA`, em `api/ai/nucleo/config.php`), e o seed
respeita esse teto parando e esperando a janela virar se encostar nele.

Para preencher uma base que já nasceu com fallback, apague o que quer
refazer e rode o módulo de novo com a chave no lugar.

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

O `ON DELETE CASCADE` leva junto posts, curtidas, comentários e amizades
dessas contas.

## Os arquivos

| Arquivo | O que faz |
|---|---|
| `seed_completo.php` | Roda os dois módulos na ordem e imprime o resumo final |
| `helpers_seed.php` | Conexão, saída, contadores, chamada de API com cota, Pexels, e a lista das 20 pessoas |
| `seed_usuarios.php` | Contas, bio, foto e amizades |
| `seed_posts_humanos.php` | Posts, etiquetas, curtidas e comentários |
| `ambiente_local.php` | Trava que só deixa os seeds rodarem em ambiente local |

## Duas coisas que saíram diferentes do plano

1. **Foto não é URL da Pexels.** O plano manda gravar a URL direta em
   `users.avatar` e `posts.image`; o front monta
   `uploads/${encodeURIComponent(valor)}`, então uma URL ali vira imagem
   quebrada. O seed baixa o arquivo, confere o MIME real e guarda o nome.
2. **Modo seed é só CLI.** O plano pedia um gatilho por HTTP. Um
   parâmetro de URL que pula o `require_login()` seria uma rota aberta
   para qualquer um gastar a cota de API. O gatilho é `PHP_SAPI`, que o
   cliente não controla.
