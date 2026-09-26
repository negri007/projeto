<?php
/**
 * Painel do professor sobre o quiz ativo de um material: % de acerto por
 * questão e por assunto, e a pior questão ("70% errou a questão X").
 * SÓ o professor (dono da turma).
 *
 * GET ?material_id=<id>
 * Resposta: { ok:true, quiz:null | { quiz_id, total_alunos, responderam,
 *             media_pct, questoes:[...], assuntos:[...], pior_questao } }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/quiz_helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $materialId = (int)($_GET["material_id"] ?? 0);

    $material = turma_material_load($pdo, $materialId, $userId);

    if ($material === null) {
        echo json_encode(["error" => "Material não encontrado."]);
        exit;
    }

    if (!$material["is_owner"]) {
        echo json_encode(["error" => "Só o professor vê o painel do quiz."]);
        exit;
    }

    $quiz = turma_quiz_ativo($pdo, $materialId);

    if ($quiz === null) {
        echo json_encode(["ok" => true, "quiz" => null]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM circle_members WHERE circle_id = ?");
    $stmt->execute([$material["circle_id"]]);
    $totalAlunos = (int)$stmt->fetchColumn();

    // Acertos por questão, só de quem é aluno HOJE (quem saiu da turma
    // não conta no painel).
    $stmt = $pdo->prepare(
        "SELECT r.questao_id, COUNT(*) AS respostas, SUM(r.acertou) AS acertos
           FROM turma_quiz_respostas r
           JOIN circle_members cm ON cm.circle_id = ? AND cm.user_id = r.user_id
          WHERE r.quiz_id = ?
          GROUP BY r.questao_id"
    );
    $stmt->execute([$material["circle_id"], $quiz["id"]]);

    $porQuestao = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porQuestao[(int)$r["questao_id"]] = ["respostas" => (int)$r["respostas"], "acertos" => (int)$r["acertos"]];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT r.user_id)
           FROM turma_quiz_respostas r
           JOIN circle_members cm ON cm.circle_id = ? AND cm.user_id = r.user_id
          WHERE r.quiz_id = ?"
    );
    $stmt->execute([$material["circle_id"], $quiz["id"]]);
    $responderam = (int)$stmt->fetchColumn();

    $questoes = [];
    $assuntos = [];
    $pior     = null;
    $somaPct  = 0;

    foreach ($quiz["questoes"] as $q) {
        $st  = $porQuestao[$q["id"]] ?? ["respostas" => 0, "acertos" => 0];
        $pct = $st["respostas"] > 0 ? (int)round(100 * $st["acertos"] / $st["respostas"]) : null;

        $questoes[] = [
            "ordem"      => $q["ordem"],
            "enunciado"  => $q["enunciado"],
            "assunto"    => $q["assunto"],
            "respostas"  => $st["respostas"],
            "acertos"    => $st["acertos"],
            "pct_acerto" => $pct,
        ];

        $a = $q["assunto"];
        $assuntos[$a] ??= ["assunto" => $a, "respostas" => 0, "acertos" => 0];
        $assuntos[$a]["respostas"] += $st["respostas"];
        $assuntos[$a]["acertos"]   += $st["acertos"];

        if ($pct !== null) {
            $somaPct += $pct;

            if ($pior === null || $pct < $pior["pct_acerto"]) {
                $pior = ["ordem" => $q["ordem"], "assunto" => $a, "pct_acerto" => $pct, "pct_erro" => 100 - $pct];
            }
        }
    }

    foreach ($assuntos as &$as) {
        $as["pct_acerto"] = $as["respostas"] > 0 ? (int)round(100 * $as["acertos"] / $as["respostas"]) : null;
    }
    unset($as);

    $comDado = count(array_filter($questoes, fn($q) => $q["pct_acerto"] !== null));

    echo json_encode([
        "ok"   => true,
        "quiz" => [
            "quiz_id"      => $quiz["id"],
            "total_alunos" => $totalAlunos,
            "responderam"  => $responderam,
            "media_pct"    => $comDado > 0 ? (int)round($somaPct / $comDado) : null,
            "questoes"     => $questoes,
            "assuntos"     => array_values($assuntos),
            "pior_questao" => $pior,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/quiz_painel: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar o painel."]);
}
