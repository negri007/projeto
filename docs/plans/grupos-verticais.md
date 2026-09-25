# Grupos verticais — o Echo como plataforma de nichos

Status: **Fase inicial implementada** (branch `feature/grupos-academia`, 25/09/2026).
Depende, para o vertical completo, das Fases 2 (fila de tarefas) e 5 (agente
com tool-use) do `roadmap-v2.md`.

## A ideia central

A pergunta que o Echo responde: **ele devolve tempo para quem vive de se
comunicar com muita gente.** Dono de loja, professor e criador de curso têm
a mesma dor: passam horas respondendo as mesmas perguntas, produzindo
conteúdo e lembrando as pessoas de coisas, sem funcionário para isso.

O Echo resolve com três peças que já existem no código:

1. **Agente** que responde o repetitivo e aprende o que não sabe
   (`api/ai/tick.php`, tool-use da API do Claude).
2. **Motor** de anúncios/conteúdo que cria material a custo zero
   (`motor/`, render local Remotion).
3. **Notificações** que avisam só do que importa.

## Arquitetura em três camadas

O erro a evitar é dar uma **página/menu nova para cada nicho**: isso não
escala e dilui o foco. O certo é separar em camadas, unificando o **motor**,
não o **container**.

```
Container    LOJA (rica: catálogo, vitrine, própria página)   |   GRUPO (leve: coluna `tipo`)
                          \                                    /
Vertical           ferramentas + presets + campos + permissões   (declarados por tipo)
                                        |
Motor comum    agente (tick, tool-use, trava no PHP) + motor de vídeo + notificações + base de conhecimento
```

- **Container**: onde as pessoas ficam. A **loja** é rica (catálogo,
  preço, vitrine) e por isso tem página própria — foi o **primeiro
  vertical**, feito antes desta abstração existir; **não se refatora**. O
  **grupo** (`circles`) é leve e ganha só uma coluna `tipo`; cada valor de
  `tipo` é um vertical.
- **Vertical**: uma declaração — quais ferramentas o agente tem, qual
  preset visual, quais campos o dono cadastra. É o que muda entre nichos.
- **Motor comum**: o código pesado (agente, tool-use com trava no PHP,
  motor de vídeo, notificações). Não muda entre verticais.

Consequência: **adicionar um vertical ou uma função = declarar, não
reconstruir.** É o que "a arquitetura suporta" quer dizer — o encaixe já
existe; construir a função é uma linha na lista de ferramentas + a função
em si, não um novo encanamento.

## Vertical acadêmico (turma) — primeiro grupo tipado

`tipo = 'academia'`: o dono do círculo é o **professor**, os membros
(`circle_members`) são os **alunos**. Objetos próprios do vertical, além do
que o grupo social tem: **material** (e, depois, quiz e notas).

### O que já foi construído (esta fase)

Backend, com as travas de segurança do `CLAUDE.md` (identidade pela sessão,
MIME real no upload, erro `{"error":...}`, PDO preparado):

- `circles.tipo` (`banco.sql`) — coluna que tipa o círculo.
- `tabela turma_materiais` — material de aula (PDF/texto/imagem), com cache
  de resumo (`resumo`, `resumo_em`).
- `POST /api/circles/create.php` aceita `tipo`; `list.php` devolve `tipo`.
- `GET /api/turmas/material_listar.php` — dono ou membro.
- `POST /api/turmas/material_criar.php` — só o professor sobe material.
- `POST /api/turmas/material_resumir.php` — **resumo pela API do Claude**
  (texto por `ai_chamar_api()`, PDF por bloco `document` base64), com
  **cache**: gera uma vez, serve sempre; regerar só o professor.
- `turmas.html` — página de teste do fluxo ponta a ponta.

Ver `docs/API_CONTRACT.md`, seção "Grupos verticais".

### Os 3 "matadores" do vertical (prioridade para o TCC)

1. **Material com ações do agente** — barra de ações sobre o documento:
   resumir (feito), gerar quiz, extrair "o que cai na prova", virar
   resumo em vídeo pelo motor. É a cara do vertical.
2. **Quiz por conteúdo, com painel de dificuldades + citação da fonte** —
   a IA ancorada no material real ("isto está na página 7"), e o painel da
   turma ("70% errou X") que o professor vê antes da prova.
3. **Alerta de aluno em risco** — cruza quem não abriu material + errou no
   quiz + não entregou. O único que dá **número de impacto** para a banca.

Somados ao agente respondendo dúvidas + relatório do fim do dia (reuso da
Fase 5), fecham a história: *o agente aprende, cria conteúdo e antecipa
problemas — automatizando o repetitivo do professor.*

### O que a arquitetura suporta (não construir agora)

Documentado como extensão, para citar sem implementar: flashcards com
revisão espaçada, plano de estudo pré-prova, pré-correção com rubrica
(nota sempre do professor), tutor socrático (nunca faz o trabalho do
aluno — trava ética como regra de sistema). Cada um é mais uma ferramenta
na lista do agente da turma; o motor comum não muda.

## Dependências e sequência

- O **resumo** já funciona isolado (usa a API do Claude que o Echo já tem),
  por isso foi feito primeiro, sem depender das outras fases.
- **Quiz, avisos e alerta de risco** ganham muito com a **Fase 2** (fila de
  tarefas: agendar avisos, processar quiz em background) e a **Fase 5**
  (agente com tool-use e trava no PHP). Encaixam **depois** delas, sem
  conflito, porque reusam o mesmo motor comum.

## Outros verticais (só arquitetura, com ressalva)

`tipo = 'farmacia' | 'servicos' | 'seguranca' | ...` cabem no mesmo molde.
**Ressalva importante:** farmácia e saúde carregam risco legal/ético
(conselho médico, orientação de medicamento) que o TCC **não deve assumir
como função**. Se demonstrados, limitar a funções administrativas (horário,
estoque, "temos esse produto?"), nunca clínicas. Para a demo, o par
**loja + academia** já prova o argumento de plataforma:

> *O mesmo motor que atende o cliente da loja atende o aluno da turma.*
