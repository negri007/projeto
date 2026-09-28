<?php
/**
 * Migração, só por linha de comando: renomeia os arquivos de material de
 * turma gravados com `uniqid()` para o nome aleatório de 128 bits que
 * material_criar.php usa desde 28/09/2026.
 *
 *     php api/turmas/migrar_nomes_arquivos.php
 *
 * `uniqid` é o relógio em microssegundos: com o circle_id sequencial, dá
 * para varrer. No Apache isso já não importa (`uploads/turmas/` é negada
 * no .htaccess e o arquivo só sai por material_arquivo.php), mas sob
 * `php -S` o .htaccess não vale e o nome volta a ser a única trava.
 *
 * Idempotente: arquivo já no formato novo (`mat_<32 hex>.<ext>`) é
 * pulado, então rodar de novo não faz nada. Para cada material, renomeia
 * o arquivo e depois atualiza a coluna; se o UPDATE falhar, desfaz o
 * rename — banco e disco nunca ficam apontando para lugares diferentes.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../auth/db_conexao.php";

$pdo  = echo_db_conectar();
$base = __DIR__ . "/../../uploads/";

$materiais = $pdo->query(
    "SELECT id, arquivo FROM turma_materiais WHERE arquivo IS NOT NULL AND arquivo <> '' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("UPDATE turma_materiais SET arquivo = ? WHERE id = ? AND arquivo = ?");

$migrados = $pulados = $falhas = 0;

foreach ($materiais as $m) {
    $antigo = (string)$m["arquivo"];

    if (preg_match('#/mat_[0-9a-f]{32}\.[a-z0-9]+$#', $antigo)) {
        $pulados++;
        continue;
    }

    // Só o que material_criar.php grava: turmas/<circle_id>/<nome>.<ext>.
    if (!preg_match('#^turmas/(\d+)/[^/]+\.([a-z0-9]+)$#', $antigo, $p)) {
        echo "material {$m["id"]}: caminho fora do padrão, pulado ({$antigo})\n";
        $falhas++;
        continue;
    }

    $origem = $base . $antigo;

    if (!is_file($origem)) {
        echo "material {$m["id"]}: arquivo não existe em disco, pulado ({$antigo})\n";
        $falhas++;
        continue;
    }

    $novo    = "turmas/" . $p[1] . "/mat_" . bin2hex(random_bytes(16)) . "." . $p[2];
    $destino = $base . $novo;

    if (!rename($origem, $destino)) {
        echo "material {$m["id"]}: não consegui renomear, pulado\n";
        $falhas++;
        continue;
    }

    try {
        $update->execute([$novo, $m["id"], $antigo]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException("a linha mudou durante a migração");
        }
    } catch (Throwable $e) {
        rename($destino, $origem);
        echo "material {$m["id"]}: banco não atualizou ({$e->getMessage()}), rename desfeito\n";
        $falhas++;
        continue;
    }

    echo "material {$m["id"]}: {$antigo} -> {$novo}\n";
    $migrados++;
}

echo "migrados: {$migrados}, já no formato novo: {$pulados}, com problema: {$falhas}\n";
