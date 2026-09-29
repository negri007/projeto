#!/usr/bin/env bash
# Verificação completa de uma etapa do refactor de api/ai/helpers.php:
# php -l em helpers.php e api/ai/nucleo/*, retrato comparado com a base, e o
# roteiro de curl comparado com a base.
#
# Uso (antes de mexer, uma vez):
#   php tools/verificacao/base_helpers.php > tools/verificacao/saida/base_funcoes.json
#   bash tools/verificacao/roteiro.sh base
# Depois de cada etapa:
#   bash tools/verificacao/verifica_helpers.sh <rotulo> [funcao_com_corpo_alterado ...]
#
# "funcao_com_corpo_alterado" = função cujo corpo mudou DE PROPÓSITO (ajuste
# de caminho, por exemplo). As listadas em saida/esperadas.txt (uma por
# linha ou separadas por espaço) valem para todas as etapas seguintes.

set -u
R="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$R/../.." && pwd)"
SAIDA="$R/saida"; mkdir -p "$SAIDA"
PHP="${ECHO_PHP:-C:/xampp/php/php.exe}"
ROT="${1:?uso: verifica_helpers.sh <rotulo> [funcoes esperadas...]}"; shift
cd "$RAIZ"

[ -f "$SAIDA/base_funcoes.json" ] || { echo "falta a base: php tools/verificacao/base_helpers.php > $SAIDA/base_funcoes.json"; exit 2; }

ok=1
for f in api/ai/helpers.php api/ai/nucleo/*.php; do
  [ -f "$f" ] || continue
  "$PHP" -l "$f" >/dev/null 2>&1 || { echo "php -l FALHOU: $f"; "$PHP" -l "$f"; ok=0; }
done
echo "php -l: $([ $ok = 1 ] && echo OK || echo FALHOU)"

"$PHP" "$R/base_helpers.php" > "$SAIDA/atual_$ROT.json"
touch "$SAIDA/esperadas.txt"
# shellcheck disable=SC2046
"$PHP" "$R/compara_base.php" "$SAIDA/base_funcoes.json" "$SAIDA/atual_$ROT.json" $(cat "$SAIDA/esperadas.txt") "$@" || ok=0

saida=$(bash "$R/roteiro.sh" "$ROT" 2>&1)
echo "$saida" | grep -v '^gravado:'
echo "$saida" | grep -q "^ROTEIRO IGUAL A BASE$" || ok=0

[ $ok = 1 ] && echo "ETAPA $ROT: TUDO OK" || { echo "ETAPA $ROT: REPROVADA"; exit 1; }
