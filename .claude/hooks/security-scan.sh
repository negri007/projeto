#!/bin/bash
# PostToolUse (Edit|Write) - varredura heuristica de padroes de risco em arquivos PHP
# Baseado nas classes de vulnerabilidade ja auditadas no Echo: SQLi, XSS, upload malicioso, injecao de comando
input=$(cat)
file=$(echo "$input" | jq -r '.tool_input.file_path // empty')

if [[ -z "$file" || "$file" != *.php || ! -f "$file" ]]; then
  exit 0
fi

findings=""

sqli_hit=$(grep -nE '(->|::)?query\s*\(\s*["'"'"'].*\$_(GET|POST|REQUEST|COOKIE)' "$file")
if [[ -n "$sqli_hit" ]]; then
  findings+=$'\n[SQLi?] possivel concatenacao direta de input do usuario em query SQL:\n'"$sqli_hit"
fi

exec_hit=$(grep -nE '\b(eval|exec|shell_exec|system|passthru|proc_open)\s*\(' "$file")
if [[ -n "$exec_hit" ]]; then
  findings+=$'\n[EXEC] uso de funcao de execucao de comando/codigo:\n'"$exec_hit"
fi

xss_hit=$(grep -nE 'echo\s+\$_(GET|POST|REQUEST)\[' "$file")
if [[ -n "$xss_hit" ]]; then
  findings+=$'\n[XSS?] output direto de input do usuario sem escape aparente (falta htmlspecialchars?):\n'"$xss_hit"
fi

upload_hit=$(grep -nE 'move_uploaded_file\s*\(' "$file")
if [[ -n "$upload_hit" ]]; then
  findings+=$'\n[UPLOAD] move_uploaded_file encontrado - confirme whitelist de extensao/mimetype por perto:\n'"$upload_hit"
fi

if [[ -n "$findings" ]]; then
  echo "Aviso de seguranca (heuristico) em $file:$findings" >&2
  echo "" >&2
  echo "Revise conforme os padroes ja validados na auditoria do Echo (SQLi/XSS/CSRF/upload/injecao de prompt) antes de prosseguir." >&2
  exit 2
fi

exit 0
