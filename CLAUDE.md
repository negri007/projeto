# Instruções para o Claude Code — Sistema Echo

## Seu escopo
Você é responsável pelo **back-end** deste projeto: tudo em `api/`,
`banco.sql`, sessão/autenticação no servidor, envio de e-mail, regras de
negócio. O front-end (`.html`, CSS, JS do cliente) é responsabilidade do
Antigravity, que trabalha em paralelo neste mesmo repositório.

Você **não edita** diretamente arquivos de front-end (`.html`, `css/`,
`js/` do cliente) a menos que explicitamente pedido — se uma tarefa exigir
mudança visual, sinalize isso em vez de editar você mesmo.

## Leitura obrigatória antes de qualquer tarefa
1. `docs/API_CONTRACT.md` — fonte única da verdade sobre os endpoints.
   Toda mudança de assinatura, formato de resposta ou novo endpoint que
   você implementar precisa bater exatamente com o que está descrito lá.
   Se precisar mudar algo do contrato durante a implementação, atualize
   `docs/API_CONTRACT.md` primeiro e deixe claro no commit/resumo que o
   contrato mudou — o Antigravity depende dele para o front continuar
   funcionando.
2. `docs/PLANO_UPGRADE_ECHO.md` — plano geral do upgrade, com as tarefas já
   divididas por dono ([BACKEND — Claude Code] / [FRONTEND — Antigravity]).
3. `docs/plans/` — se existir algum plano específico gerado pelo
   Antigravity para uma tarefa, ele estará aqui.

## Tarefas suas neste upgrade
- Corrigir `banco.sql`: coluna `status` em `friends`, tabelas
  `circle_members`, `circle_messages`, `notifications`,
  `password_resets` (ver seção 2 do plano geral).
- Implementar sessão PHP real: `api/auth/session.php`, `logout.php`,
  `me.php`, migrar `login.php`.
- Migrar todos os endpoints que hoje recebem `email`/`user_id` do
  front para usar a sessão (`require_login()` / `current_user_id()`),
  seguindo a tabela de assinaturas em `docs/API_CONTRACT.md`.
- Implementar recuperação de senha por e-mail (PHPMailer + SMTP) e
  geração de notificações no banco a cada evento relevante (curtida,
  comentário, compartilhamento, amizade, mensagem).

## Convenções de código
- PDO com prepared statements em toda query (já é o padrão do projeto —
  manter).
- Respostas de erro sempre em `{"error": "..."}`, nunca expor
  `$e->getMessage()` na resposta ao cliente (só em log, se necessário).
