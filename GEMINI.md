# Instruções para o Antigravity — Sistema Echo

## Seu escopo
Você é responsável pelo **front-end** deste projeto: arquivos `.html`,
CSS e JavaScript do cliente, e pelos **planos de implementação** que
alimentam o Claude Code (que trabalha o back-end em paralelo, no
terminal, neste mesmo repositório).

Você **não edita** diretamente: nada dentro de `api/`, `banco.sql`, ou
qualquer arquivo `.php`. Se uma tarefa exigir mudança de back-end, escreva
um plano de implementação para o Claude Code em vez de tentar editar o
PHP você mesmo.

## Leitura obrigatória antes de qualquer tarefa
1. `docs/API_CONTRACT.md` — é a fonte única da verdade sobre os endpoints:
   o que cada um espera receber e o que devolve. Toda chamada `fetch` que
   você escrever precisa bater exatamente com o que está lá. Se a tarefa
   exigir um endpoint ou formato que não existe no contrato, atualize o
   contrato primeiro (e sinalize isso claramente no plano que você gerar
   para o Claude Code) — nunca invente um formato à parte.
2. `docs/PLANO_UPGRADE_ECHO.md` — plano geral do upgrade, com as tarefas já
   divididas por dono ([FRONTEND — Antigravity] / [BACKEND — Claude Code]).

## Tarefas suas neste upgrade
- Migrar toda checagem de sessão que hoje usa
  `localStorage.getItem("userEmail")` para chamar `GET /api/auth/me.php`
  (ver contrato de API).
- Remover o envio de `email`/`user_id` em todas as chamadas `fetch`
  existentes; adicionar `credentials: "same-origin"` em todas elas.
- Construir a tela de "esqueci minha senha" e `reset.html`.
- Construir o painel/sino de notificações (polling em
  `GET /api/notifications/list.php`).
- Padronizar CSS (extrair estilos inline repetidos para `css/echo.css`),
  responsividade da sidebar, estados de carregamento nas listagens.

## Ao gerar um plano de implementação para o Claude Code
Escreva o plano como um arquivo markdown novo dentro de `docs/plans/`
(crie a pasta se não existir), nomeado por tarefa (ex:
`docs/plans/notificacoes-backend.md`). Referencie sempre
`docs/API_CONTRACT.md` em vez de redigitar os formatos de request/response
— isso evita que o plano fique desatualizado se o contrato mudar depois.
