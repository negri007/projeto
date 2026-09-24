<?php
/**
 * Gera uma PEÇA DE MARKETING pelo motor de anúncios (render local Remotion,
 * $0 de API). O lojista escolhe modelo + formato, edita os campos e envia a
 * foto do produto; aqui a gente valida, salva a foto, grava o job e dispara
 * o `processar.php` em background (mesmo trilho de gerar.php).
 *
 * POST multipart/form-data:
 *   modelo   (obrigatório)  id do modelo — ver GET modelos.php
 *   formato  (obrigatório)  story | feed | quadrado | paisagem
 *   nicho    (opcional)     comida | moda | ... (default: da categoria da loja)
 *   campos   (JSON string)  { chave: valor } dos campos editáveis do modelo
 *   foto0, foto1, ...       arquivos de imagem, conforme o modelo pede
 *
 * Resposta: { ok:true, video_id, status:"gerando" }. O front faz poll em
 * status.php até 'pronto' (aí `arquivo` tem o caminho do MP4).
 *
 * Não passa pelo seletor de modo "acervo": o motor é local e grátis, então
 * vale mesmo com a geração por IA desligada.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/motor_catalogo.php";
require_once __DIR__ . "/../lojas/helpers.php";
require_once __DIR__ . "/../ai/helpers.php";

$userId = require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Método inválido."]);
    exit;
}

function motor_erro(string $msg): void
{
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $loja = loja_do_usuario($pdo, $userId);
    if ($loja === null) {
        motor_erro("Você ainda não tem loja.");
    }
    $lojaId = (int)$loja["id"];

    // ---- modelo + formato ----
    $modelo  = trim((string)($_POST["modelo"] ?? ""));
    $formato = trim((string)($_POST["formato"] ?? "story"));

    $catalogo = motor_modelos();
    if (!isset($catalogo[$modelo])) {
        motor_erro("Modelo inválido.");
    }
    $formatosOk = array_column(motor_formatos(), "id");
    if (!in_array($formato, $formatosOk, true)) {
        motor_erro("Formato inválido.");
    }
    $def = $catalogo[$modelo];

    // ---- nicho ----
    $nichosOk = array_column(motor_nichos(), "id");
    $nicho = trim((string)($_POST["nicho"] ?? ""));
    if (!in_array($nicho, $nichosOk, true)) {
        $nicho = motor_nicho_da_categoria((string)($loja["categoria"] ?? "Outro"));
    }

    // ---- campos ----
    $campos = json_decode((string)($_POST["campos"] ?? "{}"), true);
    $campos = is_array($campos) ? $campos : [];

    $props = [];
    foreach ($def["campos"] as $campo) {
        $key = $campo["key"];
        $tipo = $campo["tipo"];
        $max = (int)($campo["max"] ?? 80);
        $req = !empty($campo["req"]);
        $raw = $campos[$key] ?? "";

        if ($tipo === "lista") {
            // aceita array OU string separada por vírgula/quebra de linha.
            $itens = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string)$raw);
            $itens = array_values(array_filter(array_map(fn($s) => mb_substr(trim((string)$s), 0, 40), $itens), fn($s) => $s !== ""));
            $itens = array_slice($itens, 0, 6);
            foreach ($itens as $it) {
                if (ai_moderate($it) !== null) {
                    motor_erro("Um dos itens de \"{$campo["label"]}\" não pode ser usado.");
                }
            }
            if ($req && $itens === []) {
                motor_erro("Preencha \"{$campo["label"]}\".");
            }
            if ($itens !== []) {
                $props[$key] = $itens;
            }
            continue;
        }

        if ($tipo === "numero") {
            $n = (int)$raw;
            if ($key === "estrelas") {
                $n = max(1, min(5, $n ?: 5));
            }
            $props[$key] = $n;
            continue;
        }

        // texto | textarea | preco
        $val = mb_substr(trim((string)$raw), 0, $max);
        if ($val === "") {
            if ($req) {
                motor_erro("Preencha \"{$campo["label"]}\".");
            }
            continue;
        }
        if (ai_moderate($val) !== null) {
            motor_erro("O texto de \"{$campo["label"]}\" não pode ser usado. Escreva de outra forma.");
        }
        $props[$key] = $val;
    }

    // ---- transformações especiais ----

    // Ficha: "24H Aberto, 80+ Aparelhos" -> [{n,unit,label}]
    if ($modelo === "Ficha" && isset($props["stats_txt"])) {
        $stats = [];
        foreach ($props["stats_txt"] as $item) {
            $partes = explode(" ", $item, 2);
            $big = $partes[0] ?? "";
            $label = trim($partes[1] ?? "");
            if (preg_match('/^(\d+)(.*)$/', $big, $m)) {
                $stats[] = ["n" => (int)$m[1], "unit" => mb_substr(trim($m[2]), 0, 4), "label" => mb_strtoupper($label)];
            }
        }
        $stats = array_slice($stats, 0, 3);
        if ($stats !== []) {
            $props["stats"] = $stats;
        }
        unset($props["stats_txt"]);
    }

    // ---- encaixe da foto (Preencher x Foto inteira) + foco/zoom ----
    // Só faz sentido em modelo com foto ajustável (full-bleed). O componente
    // lê props.ajuste e props.foco (ver motor/src/Foto.jsx).
    if (motor_foto_ajustavel($modelo)) {
        $ajuste = ($_POST["ajuste"] ?? "preencher") === "inteira" ? "inteira" : "preencher";
        $props["ajuste"] = $ajuste;

        if ($ajuste === "preencher") {
            $foco = json_decode((string)($_POST["foco"] ?? ""), true);
            if (is_array($foco)) {
                $props["foco"] = [
                    "x"    => min(1, max(0, (float)($foco["x"] ?? 0.5))),
                    "y"    => min(1, max(0, (float)($foco["y"] ?? 0.5))),
                    "zoom" => min(3, max(1, (float)($foco["zoom"] ?? 1))),
                ];
            }
        }
    }

    // ---- fotos ----
    $alvos = $def["foto_alvos"] ?? [];

    if (($def["auto"] ?? "") === "produtos") {
        // Combo: monta os itens a partir dos produtos da loja (com foto).
        $stmt = $pdo->prepare(
            "SELECT nome, preco, imagem FROM loja_produtos
              WHERE loja_id = ? AND disponivel = 1 AND imagem IS NOT NULL AND imagem <> ''
              ORDER BY ordem, id LIMIT 4"
        );
        $stmt->execute([$lojaId]);
        $itens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $src = __DIR__ . "/../../uploads/" . $p["imagem"];
            $rel = motor_copiar_imagem($src, $lojaId, "combo");
            if ($rel === null) {
                continue;
            }
            $preco = $p["preco"] !== null ? "R$ " . number_format((float)$p["preco"], 2, ",", ".") : "";
            $itens[] = ["foto" => $rel, "nome" => mb_substr((string)$p["nome"], 0, 40), "preco" => $preco];
        }
        if (count($itens) < 2) {
            motor_erro("Cadastre pelo menos 2 produtos com foto para montar o cardápio.");
        }
        $props["itens"] = $itens;
    } elseif ($alvos !== []) {
        // Salva foto0, foto1... na ordem que o modelo pede.
        $salvas = [];
        for ($i = 0; $i < count($alvos); $i++) {
            $file = $_FILES["foto{$i}"] ?? null;
            if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"])) {
                $salvas[$i] = null;
                continue;
            }
            $rel = motor_copiar_imagem($file["tmp_name"], $lojaId, "foto{$i}");
            if ($rel === null) {
                motor_erro("Não consegui ler essa imagem. Tente outra foto (JPG, PNG, WEBP, HEIC do iPhone, etc., até 25MB).");
            }
            $salvas[$i] = $rel;
        }

        if (($salvas[0] ?? null) === null) {
            motor_erro("Envie a foto do produto.");
        }
        // fotos que faltam caem na primeira (ex.: 2ª cena reaproveita a 1ª).
        for ($i = 1; $i < count($alvos); $i++) {
            if (($salvas[$i] ?? null) === null) {
                $salvas[$i] = $salvas[0];
            }
        }

        // mapeia pros props conforme os alvos.
        $repetido = count(array_unique($alvos)) === 1 && count($alvos) > 1; // ex.: ['fotos','fotos']
        if ($repetido) {
            $props[$alvos[0]] = array_values($salvas);
        } else {
            foreach ($alvos as $i => $alvo) {
                $props[$alvo] = $salvas[$i];
            }
        }
    }

    // ---- freio: um render por loja por vez (evita fila empilhada) ----
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM videos_gerados
          WHERE loja_id = ? AND status = 'gerando' AND created_at > NOW() - INTERVAL 10 MINUTE"
    );
    $stmt->execute([$lojaId]);
    if ((int)$stmt->fetchColumn() > 0) {
        http_response_code(429);
        motor_erro("Já tem um vídeo sendo gerado. Espere ele terminar.");
    }

    // ---- grava e dispara ----
    $params = json_encode(["nicho" => $nicho, "props" => $props], JSON_UNESCAPED_UNICODE);
    $resumo = "[motor] {$modelo}/{$nicho}/{$formato}";

    $stmt = $pdo->prepare(
        "INSERT INTO videos_gerados (loja_id, prompt, modelo, formato, params, status)
         VALUES (?, ?, ?, ?, ?, 'gerando')"
    );
    $stmt->execute([$lojaId, $resumo, $modelo, $formato, $params]);
    $videoId = (int)$pdo->lastInsertId();

    video_disparar_processamento($videoId);

    echo json_encode(["ok" => true, "video_id" => $videoId, "status" => "gerando"], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("video/marketing: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao iniciar a geração da peça."], JSON_UNESCAPED_UNICODE);
}
