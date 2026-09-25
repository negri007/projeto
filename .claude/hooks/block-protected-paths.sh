#!/bin/bash
# PreToolUse (Edit|Write) - impede edição manual em pastas geradas/dependências
input=$(cat)
file=$(echo "$input" | jq -r '.tool_input.file_path // empty')

if [[ -z "$file" ]]; then
  exit 0
fi

if [[ "$file" == *"/vendor/"* || "$file" == *"/node_modules/"* || "$file" == *"/.git/"* ]]; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "Edição bloqueada: vendor/, node_modules/ e .git/ são gerados ou de dependências - não devem ser editados manualmente."
    }
  }'
  exit 0
fi

exit 0
