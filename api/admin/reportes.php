<?php
/**
 * Fila de reportes de material de turma. Só admin.
 *
 * GET ?status=pendente|resolvido|descartado (padrão pendente)
 * Resposta: { ok:true, status, reportes:[{ id, motivo, origem, status,
 *   created_at, decidido_em, material:{id,titulo,arquivo_url,texto},
 *   turma:{id,nome}, professor:{id,nome}, aluno:{id,nome},
 *   pendentes_do_material }] }
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../auth/db.php";
require_once __DIR__ . "/../professor/helpers.php";
require_once __DIR__ . "/../turmas/helpers.php";

$userId = require_login();
liberar_sessao();

const REPORTES_STATUS = ["pendente", "resolvido", "descartado"];
const REPORTES_LIMITE = 50;

/** Trecho do texto do material que vai junto na fila (material sem arquivo). */
const REPORTES_TEXTO_MAX = 2000;

try {
    if (!professor_is_admin($pdo, $userId)) {
        http_response_code(403);
        echo json_encode(["error" => "Acesso restrito."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = (string)($_GET["status"] ?? "pendente");

    if (!in_array($status, REPORTES_STATUS, true)) {
        http_response_code(400);
        echo json_encode(["error" => "Status inválido."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT r.id, r.motivo, r.origem, r.status, r.created_at, r.decidido_em,
                m.id AS material_id, m.titulo, m.arquivo, m.conteudo_texto,
                c.id AS turma_id, c.name AS turma_nome,
                p.id AS professor_id, p.name AS professor_nome,
                a.id AS aluno_id, a.name AS aluno_nome,
                (SELECT COUNT(*) FROM turma_reportes r2
                  WHERE r2.material_id = r.material_id AND r2.status = 'pendente') AS pendentes_do_material
           FROM turma_reportes r
           JOIN turma_materiais m ON m.id = r.material_id
           JOIN circles c         ON c.id = r.circle_id
           JOIN users p           ON p.id = r.professor_id
           JOIN users a           ON a.id = r.reporter_id
          WHERE r.status = ?
          ORDER BY r.id DESC
          LIMIT " . REPORTES_LIMITE
    );
    $stmt->execute([$status]);

    $reportes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $temArquivo = trim((string)$r["arquivo"]) !== "";
        $texto      = trim((string)$r["conteudo_texto"]);

        $reportes[] = [
            "id"          => (int)$r["id"],
            "motivo"      => $r["motivo"],
            "origem"      => $r["origem"],
            "status"      => $r["status"],
            "created_at"  => $r["created_at"],
            "decidido_em" => $r["decidido_em"],
            "material"    => [
                "id"          => (int)$r["material_id"],
                "titulo"      => $r["titulo"],
                // A URL protegida (material_arquivo.php deixa o admin abrir
                // material reportado), nunca o caminho em disco.
                "arquivo_url" => $temArquivo ? turma_material_url(["id" => $r["material_id"], "arquivo" => $r["arquivo"]]) : null,
                "texto"       => !$temArquivo && $texto !== "" ? mb_substr($texto, 0, REPORTES_TEXTO_MAX) : null,
            ],
            "turma"       => ["id" => (int)$r["turma_id"], "nome" => $r["turma_nome"]],
            "professor"   => ["id" => (int)$r["professor_id"], "nome" => $r["professor_nome"]],
            "aluno"       => ["id" => (int)$r["aluno_id"], "nome" => $r["aluno_nome"]],
            "pendentes_do_material" => (int)$r["pendentes_do_material"],
        ];
    }

    echo json_encode(["ok" => true, "status" => $status, "reportes" => $reportes], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("admin/reportes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erro ao carregar os reportes."], JSON_UNESCAPED_UNICODE);
}
