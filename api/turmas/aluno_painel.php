<?php
/**
 * Painel do ALUNO numa turma: o progresso da própria pessoa (não da turma).
 * SQL puro, sem chamada de API. Identidade sempre da sessão.
 *
 * GET ?circle_id=<id>
 *
 * "Progresso" é do usuário logado, calculado sobre os quizzes ativos e os
 * materiais da turma:
 * - quizzes_total   : quizzes ativos na turma;
 * - respondidos     : quantos DESSES o aluno respondeu;
 * - pendentes       : total - respondidos;
 * - media_pct       : média de acerto (%) nos quizzes que ele respondeu, ou
 *                     null se ainda não respondeu nenhum;
 * - pct_concluido   : respondidos / total (%), 0 se não há quiz;
 * - materiais_total : materiais da turma;
 * - materiais_novos : materiais que ele ainda não abriu pelo Echo.
 *
 * Resposta: { ok:true, progresso:{...},
 *             quizzes:[{material_id, titulo, respondido, acertos, total, pct}] }
 *
 * Owner também pode chamar (vê o próprio progresso, normalmente zerado); o
 * front só usa esta tela quando a pessoa NÃO é dona da turma.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

$userId = require_login();
liberar_sessao();

try {
    $circleId = (int)($_GET["circle_id"] ?? 0);

    $turma = turma_load_for_user($pdo, $circleId, $userId);

    if ($turma === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }

    // Quizzes ativos da turma, com o total de questões e o título do material.
    $stmt = $pdo->prepare(
        "SELECT z.id, z.material_id, m.titulo,
                (SELECT COUNT(*) FROM turma_quiz_questoes q WHERE q.quiz_id = z.id) AS total
           FROM turma_quizzes z
           JOIN turma_materiais m ON m.id = z.material_id
          WHERE z.circle_id = ? AND z.ativo = 1
          ORDER BY z.id"
    );
    $stmt->execute([$circleId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Acertos DO ALUNO em cada quiz ativo (só o que ele respondeu aparece).
    $stmt = $pdo->prepare(
        "SELECT r.quiz_id, SUM(r.acertou) AS acertos, COUNT(*) AS respostas
           FROM turma_quiz_respostas r
           JOIN turma_quizzes z ON z.id = r.quiz_id
          WHERE z.circle_id = ? AND z.ativo = 1 AND r.user_id = ?
          GROUP BY r.quiz_id"
    );
    $stmt->execute([$circleId, $userId]);
    $meus = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $meus[(int)$r["quiz_id"]] = (int)$r["acertos"];
    }

    $lista       = [];
    $respondidos = 0;
    $somaPct     = 0;

    foreach ($quizzes as $z) {
        $qid    = (int)$z["id"];
        $total  = (int)$z["total"];
        $feito  = isset($meus[$qid]);
        $acertos = $feito ? $meus[$qid] : 0;
        $pct     = ($feito && $total > 0) ? (int)round(100 * $acertos / $total) : null;

        if ($feito) {
            $respondidos++;
            $somaPct += $pct;
        }

        $lista[] = [
            "material_id" => (int)$z["material_id"],
            "titulo"      => $z["titulo"],
            "respondido"  => $feito,
            "acertos"     => $acertos,
            "total"       => $total,
            "pct"         => $pct,
        ];
    }

    $quizzesTotal = count($quizzes);

    // Materiais: total e quantos o aluno ainda não abriu.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM turma_materiais WHERE circle_id = ?");
    $stmt->execute([$circleId]);
    $materiaisTotal = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT v.material_id)
           FROM turma_material_views v
           JOIN turma_materiais m ON m.id = v.material_id
          WHERE m.circle_id = ? AND v.user_id = ?"
    );
    $stmt->execute([$circleId, $userId]);
    $vistos = (int)$stmt->fetchColumn();

    echo json_encode([
        "ok" => true,
        "progresso" => [
            "quizzes_total"   => $quizzesTotal,
            "respondidos"     => $respondidos,
            "pendentes"       => $quizzesTotal - $respondidos,
            "media_pct"       => $respondidos > 0 ? (int)round($somaPct / $respondidos) : null,
            "pct_concluido"   => $quizzesTotal > 0 ? (int)round(100 * $respondidos / $quizzesTotal) : 0,
            "materiais_total" => $materiaisTotal,
            "materiais_novos" => max(0, $materiaisTotal - $vistos),
        ],
        "quizzes" => $lista,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/aluno_painel: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao carregar seu progresso."]);
}
