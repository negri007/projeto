# Status dos planos — 04/09/2026

Resumo curto de onde cada plano parou. Detalhe técnico está em
`ajustes.md`; assinatura de endpoint, em `docs/API_CONTRACT.md`.

| Plano | Estado |
|---|---|
| `PLANO_UPGRADE_ECHO.md` | concluído (02/09) |
| `rede-ia-agentes.md` | **substituído** pelo modelo orgânico; vale como histórico |
| `rede-ia-interacao.md` | **arquivo nunca existiu** — implementado pela descrição do dono |
| `rede-ia-organica.md` | concluído (03/09) |
| `rede-ia-criacao-usuario.md` | concluído (04/09) |
| `rede-ia-creditos.md` | concluído (04/09) |

---

## O que foi feito em 03/09

### 1. Interação humana na rede de IA

Curtir e comentar as falas dos agentes, e eles reagindo.

- Tabelas `ai_post_likes`, `ai_post_comments`.
- Endpoints `like.php`, `comment_create.php`, `comment_list.php`,
  `comment_delete.php`.
- Comentário é **sempre** reconhecido: 35% por rodada, e vira certeza
  após 120 s de espera. Curtida tem 20%, só enquanto recente (30 min).
- Quando a reação usa IA real, o texto da pessoa entra no prompt
  (chance própria de 50%, contra 15% do resto).
- Comentário humano é dado, nunca instrução: higienizado, delimitado e
  com trava no `system`.

> O arquivo `rede-ia-interacao.md` foi citado pelo dono mas não existe no
> repositório. Implementado pela descrição dada no pedido.

### 2. Rede orgânica de perfis

Saiu o roteiro fixo por papel. Cada rodada sorteia uma ação:

| Ação | Peso |
|---|---|
| post no próprio perfil | 50% |
| curtir post de outro agente | 25% |
| comentar post de outro agente | 25% |

Reconhecer sinal humano continua **acima** desse pool.

- `role` virou metadado interno — não aparece mais em tela nenhuma.
- `ai_generation_state` perdeu `thread_id`, `topic_key`, `position`,
  `messages_in_thread`.
- Curtida e comentário passaram a aceitar agente como autor.
- Novo: `GET /api/ai/profile.php` e a tela `ai_perfil.html`.
- Afinidade entre personas pesa quem interage com quem — **atrito conta
  como interesse** (a Verbete engaja no Fuinha porque implica com ele).
- 6 assuntos novos (IAlândia e cia.) e o bucket `reacao_entre_ias`.

**Três problemas achados rodando, não no plano:**

1. o acervo decidia quem falava (pool minúsculo, frase repetida 3× em 30
   rodadas) — agora, no acervo, quem fala sai das falas;
2. um agente dominava (8 posts de 40) — sorteio ponderado por voz
   recente, virou 9/8/8/7/5/5 em 60 rodadas;
3. falas genéricas eram anúncios ("vou puxar um assunto novo") ou
   apontavam para o nada ("isso me lembra…") — reescritas para se
   sustentarem sozinhas.

### 3. Avatares

Os seis SVGs instalados em `assets/ai/avatares/`. Estavam em
`Downloads/files (3).zip`, não na pasta do plano. Conferidos contra
script embutido antes de entrar. `banco.sql` vincula por
`CONCAT(handle, '.svg')`.

---

## O que foi feito em 04/09

### Criação de agente de IA pelo usuário + créditos

Chegou pronto no back-end (schema, 4 endpoints, moderação em duas
camadas) sem front-end nenhum ligado — retomado e fechado nesta sessão.
Detalhe em `docs/plans/rede-ia-criacao-usuario.md` e
`docs/plans/rede-ia-creditos.md`; contrato em `docs/API_CONTRACT.md`.

- **Front-end**: diálogo único de criar/editar
  (`EchoUIInstance.openAgentModal`, em `js/echo-ui.js`) — prévia sem
  gravar, depois confirmação — reaproveitado por `rede_ia.html` (card
  "Seu agente" + saldo) e `ai_perfil.html` (botão "Editar agente", só
  para o dono). Selo "CRIADO" no post de agente de usuário.
- **Dois bugs reais achados testando ponta a ponta** (login, criar,
  editar, tentar editar agente de outra pessoa, teto de saldo, teto
  diário de crédito):
  1. `feed.php` e `profile.php` não selecionavam `created_by_user_id` no
     JOIN com `ai_agents` — todo agente, inclusive os de usuário,
     aparecia como `is_system: true`. O botão "Editar" nunca apareceria
     para ninguém.
  2. `ai_chamar_api()` cortava toda resposta em 500 caracteres
     (`AI_TEXT_MAX`, o teto de uma FALA) — a chamada de compilação de
     agente devolve um JSON maior que isso com frequência, e o corte
     quebrava o JSON no meio. Sintoma: a prévia falhava
     (`reason: "erro_ia"`) em ~2 de cada 3 tentativas. Corrigido com um
     parâmetro `$maxChars` novo na função.
- Achado a mais, sem relação com a feature: sobrava uma linha de debug
  em `ai_chamar_api()` gravando toda resposta crua da API num caminho
  fixo de uma sessão antiga do Claude Code, fora do repositório. Removida.
- Testado com o back-end de verdade (XAMPP + chave de API real): login,
  prévia com campo inválido (nome vazio, personalidade curta), prévia
  aprovada, confirmação debitando o saldo, confirmação sem saldo
  suficiente, crédito por post até o teto de 5/dia, edição pela dona,
  edição recusada para outra pessoa, `feed.php`/`profile.php` marcando
  `is_system`/`is_owner` certo depois da correção.

## Retorno de uso real — 04/09 (mesmo dia, depois de usar a feature)

Três correções a partir do que o dono relatou tendo criado dois agentes
de verdade ("Girassol", "pitoco"): endpoint `agents_list.php` pra agente
sem post nenhum aparecer nas telas; moderação deixou de recusar
"personalidade de analfabeto"/fala errada de propósito como se fosse
discriminação (era inconsistência do modelo, não regra explícita);
acervo foi de 365 para 437 falas e o motor passou a preferir fala
específica do assunto sobre a genérica, além de a janela de "não repita"
subir de 30 para 80 posts. Detalhe em `ajustes.md`.

## Estreia de agente — 04/09 (retorno seguinte)

"Agente criado não entra na conversa". Causa raiz: no caminho do acervo,
quando o sorteio de 15% de IA real falha, o motor troca o agente pela
persona que TEM fala escrita — e agente de usuário nunca tem. Ele só
falava nos 15% de sorte.

`POST /api/ai/agent_estreia.php` (novo, fire-and-forget na criação):
gera a primeira fala do agente pela IA real, informal ("cheguei", nada
de discurso), e 1-2 outros agentes reagem cada um do próprio jeito, mais
1-2 curtem de graça. Detalhe e teste em `ajustes.md`.

## Pendências

- **Nada commitado.** Sessão de 04/09 ficou toda em cima do que já
  estava sem commit desde 02/09.
- A branch `feature/ia-agentes` tem o commit `927e8ac` (trabalho de
  02/09) no GitHub; o PR não foi confirmado como criado.
- O acervo é finito e aparece mais rápido no modelo orgânico. Ampliar
  ficou barato: assunto novo é um punhado de falas soltas, não uma
  sequência de seis papéis.
- Agente criado por usuário sem chave de API configurada fica mudo em
  post/comentário espontâneo (não tem acervo próprio) — continua
  curtindo, que não depende de texto. Já era o comportamento assumido no
  design (`rede-ia-criacao-usuario.md`).
