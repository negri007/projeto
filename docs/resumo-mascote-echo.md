# Resumo — Mascote do Echo (rede de IA)

## Contexto
Parte do TCC "Echo" (rede social, FATEC Presidente Prudente). Dentro da funcionalidade "Rede de IA" (agentes com personalidade própria), surgiu a ideia de um **mascote visual persistente**, sempre ativo na interface, que os agentes de IA também percebem.

## Decisões principais (em ordem)
1. **Conceito inicial: cobra amarela** — rastejava pelas bordas dos elementos da tela, subia em espiral, se enrolava/dormia num canto, mudava de pele, reagia ao cursor, mordia o contador de notificação, e tinha uma sequência de "comer" mensagens apagadas (boca do tamanho da caixa, volume viajando pelo corpo, regurgitava na lixeira).
2. **Abandonada a cobra → trocado para passarinho azul**, pelos motivos: público reage de forma mais dividida a cobra (medo/nojo) que a passarinho; mecânicas de pouso/decolagem/bicar são mais naturais num pássaro.
3. **Passarinho reconstruído do zero**, com:
   - Malha invisível de pontos (grid) usada só como "norte" de navegação pra sortear destinos de voo — não é renderizada.
   - Voo com curva de virada suave, desaceleração natural ao se aproximar do alvo (sem teleporte).
   - **Pousa nas linhas reais do layout** (embaixo de contatos, embaixo/diagonal das bolhas de mensagem, divisor da barra lateral, e ocasionalmente a palavra "echo" do logo), cada linha com posição e leve variação de ângulo.
   - **Sequência de pouso em 3 fases:** freada (asas abertas) → aproximação suave com pernas esticando → trava na posição exata, com pernas e garras (3 dedos) visíveis alcançando a linha.
   - **Pose obrigatória ao ficar parado:** sempre pousa de frente pra tela (peito arredondado, cabeça centralizada que inclina seguindo o cursor, asas dobradas, cauda espiando embaixo) — nunca fica parado de perfil; o perfil de lado só aparece durante voo/pouso/decolagem.
   - Decolagem com pequeno impulso antes de retomar voo livre.
   - Cabeça acompanha o mouse continuamente; qualquer tecla pressionada dá uma reação de alerta rápida.
   - **Apagar mensagem:** clica no "×" de uma mensagem → ele voa até lá, pega com os pés, carrega até a lixeira, larga tudo de uma vez.
   - **Notificação:** ao passar perto do sino com notificações pendentes, dá 3 bicadas e o número "cai como fruta" até a lixeira embaixo.
4. **Protótipo funcional em HTML/SVG** foi construído e iterado várias vezes (arquivo: `passarinho-echo.html`), corrigindo bugs de: travamento por espelhamento instável perto de ângulos verticais, corpo em polígono contínuo com raio variável (sem mais "bolinhas"), e alinhamento das pernas com a linha de pouso.

## Modelo 3D (Tripo AI)
- Usuário gerou um modelo de passarinho azul no Tripo AI (tipo "Aviário"), pretendendo animar por **código via Three.js** (decisão explícita: não usar Unity, por ser web app leve e já ter toda a lógica de posição em JS/HTML).
- Upload do `.glb` exportado (`chicken_3d_model.glb`) foi analisado diretamente:
  - **Sem animações embutidas** (`animations: []`) — bate com suspeita de que o tipo "aviário" na Tripo não tem preset de animação pronto ainda.
  - **Bug real de exportação encontrado:** os dados de bind-pose (inverseBindMatrices) de vários ossos — principalmente das asas — vieram corrompidos/degenerados; tentar recuperar a posição real desses ossos matematicamente resultou em valores absurdos, indicando dado de origem inválido, não um erro de leitura.
  - **Conclusão prática:** o modelo deve renderizar bem parado (pose de bind funciona por construção), mas animar batendo asa via rotação de osso é arriscado com esse arquivo específico.
  - **Próximo passo combinado:** montar um visualizador Three.js carregando o modelo real, com animação do objeto inteiro (sem depender dos ossos quebrados) como primeira entrega; considerar reexportar da Tripo em FBX ou reportar o bug pra eles se quiser animação de esqueleto de verdade depois.

## Arquivos gerados nesta conversa
- `cobrinha-echo.html` (protótipo da cobra, superado)
- `passarinho-echo.html` (protótipo atual do passarinho, funcional)
- `prompt-claude-code-echo-quarta-parede.md` (prompt para o Claude Code implementar 4 funcionalidades de "quarta parede" da rede de IA — posts efêmeros, rumor, agente cético, IAlândia+apostas — ainda válido e não relacionado ao mascote)
