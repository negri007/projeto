<?php
/**
 * Alerta de aluno em risco numa turma. SÓ o professor (dono). SQL puro,
 * sem chamada de API.
 *
 * GET ?circle_id=<id>
 *
 * Um aluno está em risco se tiver pelo menos um sinal:
 * - nota_baixa:    acertou menos de TURMA_RISCO_PCT% num quiz ativo;
 * - quiz_pendente: quiz ativo disponível há mais de TURMA_RISCO_DIAS dias
 *                  e ele não respondeu;
 * - nao_abriu:     há material da turma postado há mais de TURMA_RISCO_DIAS
 *                  dias que ele não abriu pelo Echo (a mesma carência do
 *                  quiz: material recém-postado não infla o número).
 *
 * Resposta: { ok:true, total_alunos, em_risco, criterios:{pct, dias},
 *             assuntos:[{assunto, alunos_em_risco, responderam}],
 *             alunos:[{user_id, name, motivos:[{tipo, texto, ...}]}] }
 * `alunos` traz só quem está em risco, com mais motivos primeiro.
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/helpers.php";

const TURMA_RISCO_PCT  = 60;
const TURMA_RISCO_DIAS = 3;

$userId = require_login();
liberar_sessao();

try {
    $circleId = (int)($_GET["circle_id"] ?? 0);

    $turma = turma_load_for_user($pdo, $circleId, $userId);

    if ($turma === null) {
        echo json_encode(["error" => "Turma não encontrada."]);
        exit;
    }

    if (!$turma["is_owner"]) {
        echo json_encode(["error" => "Só o professor vê o alerta da turma."]);
        exit;
    }

    // Alunos de hoje.
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name
           FROM circle_members cm
           JOIN users u ON u.id = cm.user_id
          WHERE cm.circle_id = ?
          ORDER BY u.name"
    );
    $stmt->execute([$circleId]);
    $alunos = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $alunos[(int)$a["id"]] = ["user_id" => (int)$a["id"], "name" => $a["name"], "motivos" => []];
    }

    // Materiais e quem abriu cada um.
    $stmt = $pdo->prepare("SELECT id, titulo FROM turma_materiais
          WHERE circle_id = ? AND created_at < NOW() - INTERVAL " . TURMA_RISCO_DIAS . " DAY
          ORDER BY id");
    $stmt->execute([$circleId]);
    $materiais = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT v.material_id, v.user_id
           FROM turma_material_views v
           JOIN turma_materiais m ON m.id = v.material_id
          WHERE m.circle_id = ?"
    );
    $stmt->execute([$circleId]);
    $abriu = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $abriu[(int)$v["material_id"]][(int)$v["user_id"]] = true;
    }

    // Quizzes ativos: total de questões e se já passou do prazo.
    $stmt = $pdo->prepare(
        "SELECT z.id, z.material_id, m.titulo,
                (SELECT COUNT(*) FROM turma_quiz_questoes q WHERE q.quiz_id = z.id) AS total,
                z.created_at < NOW() - INTERVAL " . TURMA_RISCO_DIAS . " DAY AS vencido,
                TIMESTAMPDIFF(DAY, z.created_at, NOW()) AS dias
           FROM turma_quizzes z
           JOIN turma_materiais m ON m.id = z.material_id
          WHERE z.circle_id = ? AND z.ativo = 1"
    );
    $stmt->execute([$circleId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Nota de cada aluno em cada quiz ativo.
    $stmt = $pdo->prepare(
        "SELECT r.quiz_id, r.user_id, SUM(r.acertou) AS acertos
           FROM turma_quiz_respostas r
           JOIN turma_quizzes z ON z.id = r.quiz_id
          WHERE z.circle_id = ? AND z.ativo = 1
          GROUP BY r.quiz_id, r.user_id"
    );
    $stmt->execute([$circleId]);
    $notas = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $notas[(int)$n["quiz_id"]][(int)$n["user_id"]] = (int)$n["acertos"];
    }

    foreach ($alunos as $uid => &$al) {
        foreach ($quizzes as $z) {
            $qid   = (int)$z["id"];
            $total = (int)$z["total"];

            if (isset($notas[$qid][$uid])) {
                $acertos = $notas[$qid][$uid];
                $pct     = $total > 0 ? (int)round(100 * $acertos / $total) : 0;

                if ($pct < TURMA_RISCO_PCT) {
                    $al["motivos"][] = [
                        "tipo" => "nota_baixa", "material_id" => (int)$z["material_id"],
                        "acertos" => $acertos, "total" => $total, "pct" => $pct,
                        "texto" => "Acertou $acertos de $total ($pct%) no quiz de \"{$z["titulo"]}\"",
                    ];
                }
            } elseif ((int)$z["vencido"] === 1) {
                $al["motivos"][] = [
                    "tipo" => "quiz_pendente", "material_id" => (int)$z["material_id"], "dias" => (int)$z["dias"],
                    "texto" => "Não respondeu o quiz de \"{$z["titulo"]}\" (disponível há {$z["dias"]} dias)",
                ];
            }
        }

        foreach ($materiais as $m) {
            if (empty($abriu[(int)$m["id"]][$uid])) {
                $al["motivos"][] = [
                    "tipo" => "nao_abriu", "material_id" => (int)$m["id"],
                    "texto" => "Não abriu o material \"{$m["titulo"]}\"",
                ];
            }
        }
    }
    unset($al);

    // Por assunto: aluno com menos de TURMA_RISCO_PCT% de acerto nas
    // questões daquele assunto (quizzes ativos) conta como em risco nele.
    $stmt = $pdo->prepare(
        "SELECT q.assunto, r.user_id, SUM(r.acertou) AS acertos, COUNT(*) AS respostas
           FROM turma_quiz_respostas r
           JOIN turma_quiz_questoes q ON q.id = r.questao_id
           JOIN turma_quizzes z ON z.id = r.quiz_id
          WHERE z.circle_id = ? AND z.ativo = 1
          GROUP BY q.assunto, r.user_id"
    );
    $stmt->execute([$circleId]);
    $assuntos = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!isset($alunos[(int)$s["user_id"]])) {
            continue; // saiu da turma
        }

        $a = $s["assunto"];
        $assuntos[$a] ??= ["assunto" => $a, "alunos_em_risco" => 0, "responderam" => 0];
        $assuntos[$a]["responderam"]++;

        if (100 * (int)$s["acertos"] < TURMA_RISCO_PCT * (int)$s["respostas"]) {
            $assuntos[$a]["alunos_em_risco"]++;
        }
    }

    $assuntos = array_values(array_filter($assuntos, fn($a) => $a["alunos_em_risco"] > 0));
    usort($assuntos, fn($x, $y) => $y["alunos_em_risco"] <=> $x["alunos_em_risco"] ?: strcmp($x["assunto"], $y["assunto"]));

    $emRisco = array_values(array_filter($alunos, fn($a) => count($a["motivos"]) > 0));
    usort($emRisco, fn($x, $y) => count($y["motivos"]) <=> count($x["motivos"]) ?: strcmp($x["name"], $y["name"]));

    echo json_encode([
        "ok"           => true,
        "total_alunos" => count($alunos),
        "em_risco"     => count($emRisco),
        "criterios"    => ["pct" => TURMA_RISCO_PCT, "dias" => TURMA_RISCO_DIAS],
        "assuntos"     => $assuntos,
        "alunos"       => $emRisco,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("turmas/alunos_risco: " . $e->getMessage());
    echo json_encode(["error" => "Erro ao calcular o alerta."]);
}
