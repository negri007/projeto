# tools/verificacao

Scripts usados para verificar a divisão de `api/ai/helpers.php` em
`api/ai/nucleo/` (ver a seção do refactor no `ajustes.md`). Servem para
qualquer mudança que não deveria alterar funções, constantes nem o formato
das respostas da rede de IA.

Só linha de comando. A pasta é negada por HTTP no `.htaccess` da raiz, e
cada `.php` responde 404 fora do CLI. A saída vai para `saida/`, fora do
git.

| Arquivo | O que faz |
|---|---|
| `base_helpers.php` | Retrato do `helpers.php`: assinatura e md5 do corpo de cada função, md5 do valor de cada constante, `realpath` das constantes-caminho e a ordem dos includes |
| `compara_base.php` | Compara dois retratos. Aceita, explicitamente, corpos alterados de propósito e constante-caminho com o mesmo `realpath`; confere que `corpus.php` carrega antes de `api/ai/nucleo/*` |
| `chaves.php` | Reduz um JSON ao formato (chaves e tipos, dois níveis), sem valores |
| `roteiro.sh` | 25 verificações por curl e CLI, comparando status HTTP e formato do JSON com a base. Não gasta API: enche o teto global de chamadas antes e desfaz tudo no fim |
| `verifica_helpers.sh` | `php -l` + retrato + roteiro, com "TUDO OK" ou "REPROVADA" |

## Uso

```bash
# antes de mexer (uma vez)
php tools/verificacao/base_helpers.php > tools/verificacao/saida/base_funcoes.json
bash tools/verificacao/roteiro.sh base

# depois de cada etapa
bash tools/verificacao/verifica_helpers.sh <rotulo> [funcao_alterada_de_proposito ...]
```

Precisa do Apache e do MySQL do XAMPP no ar e do banco com o seed (a conta
padrão é `gustavo@echo.local`, dona de turma no seed). Variáveis de
ambiente: `ECHO_URL`, `ECHO_PHP`, `ECHO_MYSQL`, `ECHO_TESTE_EMAIL`,
`ECHO_TESTE_SENHA` — ver o topo do `roteiro.sh`. Não use conta real.
