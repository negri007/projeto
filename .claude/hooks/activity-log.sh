#!/bin/bash
# PostToolUse (Edit|Write) - registra um log de auditoria das edicoes feitas na sessao
input=$(cat)
file=$(echo "$input" | jq -r '.tool_input.file_path // empty')

if [[ -z "$file" ]]; then
  exit 0
fi

log_dir="${CLAUDE_PROJECT_DIR:-.}/.claude/logs"
mkdir -p "$log_dir"
echo "$(date '+%Y-%m-%d %H:%M:%S') - editado: $file" >> "$log_dir/activity.log"

exit 0
