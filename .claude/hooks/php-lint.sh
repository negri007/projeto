#!/bin/bash
# PostToolUse (Edit|Write) - checa sintaxe PHP a cada edição
input=$(cat)
file=$(echo "$input" | jq -r '.tool_input.file_path // empty')

if [[ -z "$file" || "$file" != *.php ]]; then
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  exit 0
fi

output=$(php -l "$file" 2>&1)
status=$?

if [[ $status -ne 0 ]]; then
  echo "Erro de sintaxe PHP em $file:" >&2
  echo "$output" >&2
  exit 2
fi

exit 0
