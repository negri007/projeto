# Instruções para o Claude Code — Sistema Echo

## Seu escopo
Desde 31/08/2026 você é responsável pelo projeto **inteiro** — back-end
(`api/`, `banco.sql`, sessão, e-mail, regras de negócio) e front-end
(`.html`, `css/`, `js/`). O repasse foi feito pelo dono do projeto; o
Antigravity não está mais trabalhando em paralelo aqui.

Mesmo assim, mantenha a disciplina do contrato: mudança de assinatura ou
de formato de resposta entra em `docs/API_CONTRACT.md` antes de entrar no
código, porque é o contrato que mantém as duas pontas coerentes.

## Leitura obrigatória antes de qualquer tarefa
1. `docs/API_CONTRACT.md` — fonte única da verdade sobre os endpoints.
   Toda mudança de assinatura, formato de resposta ou novo endpoint que
   você implementar precisa bater exatamente com o que está descrito lá.
   Se precisar mudar algo do contrato durante a implementação, atualize
   `docs/API_CONTRACT.md` primeiro e deixe claro no commit/resumo que o
   contrato mudou.
2. `ajustes.md` — estado atual do projeto: o que foi feito em cada
   módulo, as falhas de segurança já corrigidas, o ambiente de teste e o
   que ficou de fora.
3. `docs/PLANO_UPGRADE_ECHO.md` — plano original do upgrade (concluído);
   serve como histórico da intenção, não como fonte de formato.

## Estado do upgrade
O upgrade descrito em `docs/PLANO_UPGRADE_ECHO.md` está **concluído**:
schema, sessão PHP, migração de todos os endpoints, notificações,
recuperação de senha e front-end. `ajustes.md` tem o estado detalhado e a
lista do que ficou de fora.

**Corte de escopo (01/10/2026).** Para a entrega do TCC o projeto foi
enxugado para duas verticais: a rede social humana e o **Aulas** (turmas).
Saíram inteiras a rede de IAs, a criação de agente pelo usuário, o agente
pessoal ("Criar seu Echo"), o comércio/lojas e o vídeo pelo motor. O que
sobrou de `api/ai/` é só o núcleo que o Aulas e a triagem de professor
usam (`helpers.php`, `nucleo/`, `limite_uso.php`, tabela `ai_api_uso`). Não
reintroduza esses módulos sem pedido explícito. Detalhes e a lista completa
na seção "Corte de escopo (01/10/2026)" do `ajustes.md`; o projeto completo
fica em `backup/echo-completo-2026-10-01`.

## Convenções de código
- PDO com prepared statements em toda query (já é o padrão do projeto —
  manter).
- Respostas de erro sempre em `{"error": "..."}`, nunca expor
  `$e->getMessage()` na resposta ao cliente (só em log, se necessário).
- Identidade vem sempre da sessão (`require_login()` /
  `current_user_id()`); nenhum endpoint aceita `email` ou `user_id` do
  cliente como identidade.
- Upload de arquivo é validado pelo MIME real (`finfo`), nunca pela
  extensão informada pelo cliente, e sempre com limite de tamanho.
- No front, "é meu?" se decide comparando `user_id` com o `user.id` de
  `GET /api/auth/me.php` — nunca por e-mail ou nome.

## Higiene de commit
- Nunca usar `git add -A` nem `git add .`. Adicionar só os caminhos da
  tarefa, um a um. (`git add -A` já subiu por engano arquivos de scratch e
  capas demo para o repositório público.)
- Antes de cada commit, rodar `git status` e `git diff --cached --stat` e
  conferir a lista de arquivos que vai entrar — se aparecer algo fora da
  tarefa, tirar do índice antes de commitar.
