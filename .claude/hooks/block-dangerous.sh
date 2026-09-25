#!/bin/bash
# PreToolUse (Bash) - bloqueia comandos destrutivos comuns no projeto Echo
input=$(cat)
command=$(echo "$input" | jq -r '.tool_input.command // empty')

if echo "$command" | grep -qE 'rm[[:space:]]+-rf[[:space:]]+(/|\.\.|~)?([[:space:]]|$)'; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "Comando rm -rf potencialmente destrutivo bloqueado (hook do projeto Echo)."
    }
  }'
  exit 0
fi

if echo "$command" | grep -qE 'git[[:space:]]+push.*--force'; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "Force push bloqueado - branch main do Echo é protegido pelo hook do projeto."
    }
  }'
  exit 0
fi

exit 0
