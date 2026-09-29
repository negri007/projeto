#!/usr/bin/env bash
# Roteiro de fumaça da rede de IA, sem gastar API. Feito para o refactor de
# api/ai/helpers.php (ver ajustes.md), serve para qualquer mudança que não
# deveria alterar formato de resposta.
#
# Uso (de qualquer pasta):
#   bash tools/verificacao/roteiro.sh base     -> grava a referência
#   bash tools/verificacao/roteiro.sh <rotulo> -> grava e compara com a base
# Saída em tools/verificacao/saida/ (fora do git).
#
# Compara STATUS HTTP e FORMATO do JSON (chaves e tipos), nunca valores: o
# sorteio da rede é aleatório. Do tick.php só status + presença de "ok",
# porque o formato dele varia com o sorteio da rodada.
#
# Configuração por variável de ambiente (padrões = XAMPP local + seed):
#   ECHO_URL          http://127.0.0.1:8080
#   ECHO_PHP          C:/xampp/php/php.exe
#   ECHO_MYSQL        C:/xampp/mysql/bin/mysql.exe   (usuário root, banco `banco`)
#   ECHO_TESTE_EMAIL  gustavo@echo.local   (dono de turma no seed)
#   ECHO_TESTE_SENHA  senha123             (senha pública das contas de seed —
#                                           não use conta real aqui)
# Os ids usados (post de agente, evento, turma, agente) são descobertos no
# banco, não fixos.
#
# Proteções (desfeitas no fim, mesmo com erro):
#  - enche o teto GLOBAL de chamadas (AI_TETO_CHAMADAS_HORA) com linhas
#    marcadas em ai_api_uso: ai_pode_chamar_api() responde false e nada vai
#    à Anthropic, qualquer que seja o modo da rede;
#  - guarda e restaura créditos e contador diário da conta de teste
#    (posts/create credita 1) e apaga o post, a memória e as notificações
#    criadas.
# O que NÃO protege: busca de foto no Pexels pelo tick (grátis, não olha o
# modo) e os posts/falas que o tick gera pelo acervo — o mesmo que acontece
# a cada página aberta no site.

set -u
R="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$R/../.." && pwd)"
SAIDA="$R/saida"; mkdir -p "$SAIDA"
B="${ECHO_URL:-http://127.0.0.1:8080}"
PHP="${ECHO_PHP:-C:/xampp/php/php.exe}"
M="${ECHO_MYSQL:-C:/xampp/mysql/bin/mysql.exe} -u root -N banco"
EMAIL="${ECHO_TESTE_EMAIL:-gustavo@echo.local}"
SENHA="${ECHO_TESTE_SENHA:-senha123}"
J='Content-Type: application/json'
ROT="${1:?uso: roteiro.sh <rotulo>  (use 'base' para a referência)}"
OUT="$SAIDA/roteiro_${ROT}.txt"
CK="$SAIDA/cookie_roteiro.txt"

UIDT=$($M -e "SELECT id FROM users WHERE email = '$EMAIL'")
[ -n "$UIDT" ] || { echo "conta de teste $EMAIL não existe (rode o seed)"; exit 2; }
POST_IA=$($M -e "SELECT MAX(id) FROM ai_posts")
EVENTO=$($M -e "SELECT COALESCE(MAX(id), 0) FROM ai_ialandia_eventos")
read -r AGENTE HANDLE <<<"$($M -e "SELECT id, handle FROM ai_agents WHERE created_by_user_id IS NULL AND active = 1 ORDER BY id LIMIT 1")"
TURMA=$($M -e "SELECT COALESCE(MIN(id), 0) FROM circles WHERE tipo = 'academia' AND owner_id = $UIDT")

usoMax=$($M -e "SELECT COALESCE(MAX(id),0) FROM ai_api_uso")
read -r cred hoje data <<<"$($M -e "SELECT ai_credits, ai_credits_earned_today, COALESCE(ai_credits_earned_date,'NULL') FROM users WHERE id=$UIDT")"
postMax=$($M -e "SELECT COALESCE(MAX(id),0) FROM posts")
memMax=$($M -e "SELECT COALESCE(MAX(id),0) FROM user_agent_memoria")
notMax=$($M -e "SELECT COALESCE(MAX(id),0) FROM notifications")

limpar() {
  $M -e "DELETE FROM ai_api_uso WHERE id > $usoMax AND user_id IS NULL;
         DELETE FROM posts WHERE id > $postMax AND user_id = $UIDT;
         DELETE FROM user_agent_memoria WHERE id > $memMax;
         DELETE FROM notifications WHERE id > $notMax AND actor_id = $UIDT;
         UPDATE users SET ai_credits = $cred, ai_credits_earned_today = $hoje,
                ai_credits_earned_date = $( [ "$data" = NULL ] && echo NULL || echo "'$data'" ) WHERE id = $UIDT;"
  rm -f "$CK"
}
trap limpar EXIT

# Teto global cheio: tantas linhas marcadas (user_id NULL, agora) quanto o teto.
TETO=$(cd "$RAIZ" && "$PHP" -r 'require "api/ai/helpers.php"; echo AI_TETO_CHAMADAS_HORA;')
valores=$(for _ in $(seq 1 "$TETO"); do printf '(NOW(), NULL),'; done)
$M -e "INSERT INTO ai_api_uso (criado_em, user_id) VALUES ${valores%,}"
podeApi=$(cd "$RAIZ" && "$PHP" -r 'require "api/ai/helpers.php"; require_once "api/auth/db_conexao.php"; echo ai_pode_chamar_api(echo_db_conectar()) ? "SIM" : "nao";')
if [ "$podeApi" != "nao" ]; then echo "ABORTADO: ai_pode_chamar_api() ainda diz SIM"; exit 2; fi

curl -s -c "$CK" -H "$J" -d "{\"email\":\"$EMAIL\",\"password\":\"$SENHA\"}" "$B/api/auth/login.php" >/dev/null

: > "$OUT"
linha() { printf "%-58s %s\n" "$1" "$2" >> "$OUT"; }
get() {   # get <caminho> [rotulo] [ok]
  local corpo st
  corpo=$(curl -s -b "$CK" -w $'\n%{http_code}' "$B/$1"); st=${corpo##*$'\n'}; corpo=${corpo%$'\n'*}
  linha "GET  ${2:-$1}" "$st $(printf '%s' "$corpo" | "$PHP" "$R/chaves.php" ${3:-})"
}
post() {  # post <caminho> <json> [ok]
  local corpo st
  corpo=$(curl -s -b "$CK" -w $'\n%{http_code}' -H "$J" -d "$2" "$B/$1"); st=${corpo##*$'\n'}; corpo=${corpo%$'\n'*}
  linha "POST $1 ${4:-$2}" "$st $(printf '%s' "$corpo" | "$PHP" "$R/chaves.php" ${3:-})"
}

# Os rótulos não levam os ids: a base continua comparável quando o banco cresce.
get "api/auth/me.php"
get "api/ai/feed.php?limit=5"
get "api/ai/feed.php?agent_id=$AGENTE&limit=5" "api/ai/feed.php?agent_id=<agente>"
get "api/ai/profile.php?handle=$HANDLE&limit=5" "api/ai/profile.php?handle=<agente>"
get "api/ai/agents_list.php"
get "api/ai/status.php"
get "api/ai/mode.php"
get "api/ai/comment_list.php?ai_post_id=$POST_IA" "api/ai/comment_list.php?ai_post_id=<post>"
get "api/ai/relacoes.php"
get "api/ialandia/list.php"
get "api/ialandia/get.php?evento_id=$EVENTO" "api/ialandia/get.php?evento_id=<evento>"
get "api/ialandia/provocacoes.php"
get "api/user_agent/status.php"
get "api/lojas/feed.php"
get "api/video/modelos.php"
get "api/turmas/material_listar.php?circle_id=$TURMA" "api/turmas/material_listar.php?circle_id=<turma>"

# Validações que param antes de qualquer API
post "api/ai/agent_preview.php" '{"nome":"x","personalidade":"curta"}'
post "api/ai/agent_edit_preview.php" "{\"agent_id\":$AGENTE,\"nome\":\"Teste\",\"personalidade\":\"uma personalidade longa o bastante\"}" "" '{agente de sistema}'
post "api/ai/comment_create.php" "{\"ai_post_id\":$POST_IA,\"body\":\"\"}" "" '{corpo vazio}'

# Créditos (posts/create -> ai_creditar_post); post apagado no fim
st=$(curl -s -b "$CK" -o "$SAIDA/post.json" -w '%{http_code}' -F 'content=roteiro de verificacao, apagar' "$B/api/posts/create.php")
linha "POST api/posts/create.php (multipart)" "$st $("$PHP" "$R/chaves.php" < "$SAIDA/post.json")"
credDepois=$($M -e "SELECT ai_credits FROM users WHERE id=$UIDT")
linha "  credito do post" "$({ [ "$credDepois" -gt "$cred" ] || [ "$hoje" -ge 5 ]; } && echo 'creditou ou teto do dia' || echo 'NAO CREDITOU')"

# A rede (tick): 3 rodadas, só status + ok. Zera o intervalo mínimo entre
# rodadas para as 3 acontecerem de fato.
for _ in 1 2 3; do
  $M -e "UPDATE ai_generation_state SET last_tick_at = NULL WHERE id = 1"
  post "api/ai/tick.php" '{}' ok
done

# CLI
(cd "$RAIZ" && "$PHP" api/ai/validar_corpus.php > "$SAIDA/validar.txt" 2>&1); ec=$?
linha "CLI  api/ai/validar_corpus.php" "exit=$ec linhas=$(wc -l < "$SAIDA/validar.txt")"

echo "gravado: $OUT ($(wc -l < "$OUT") linhas)"
if [ "$ROT" != base ] && [ -f "$SAIDA/roteiro_base.txt" ]; then
  if diff -u "$SAIDA/roteiro_base.txt" "$OUT" > "$SAIDA/roteiro_diff_${ROT}.txt"; then
    echo "ROTEIRO IGUAL A BASE"
  else
    echo "ROTEIRO DIFERENTE DA BASE:"; cat "$SAIDA/roteiro_diff_${ROT}.txt"
  fi
fi
