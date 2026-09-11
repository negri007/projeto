<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../notifications/helpers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../ai/helpers.php';
require_once __DIR__ . '/../rumores/helpers.php';

$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método inválido.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$post_id = (int)($data['post_id'] ?? 0);
$body    = trim($data['body'] ?? '');

if (!$post_id || $body === '') {
    echo json_encode(['error' => 'post_id e comentário são obrigatórios.']);
    exit;
}

if (mb_strlen($body) > 2000) {
    echo json_encode(['error' => 'Comentário é longo demais (máx. 2000 caracteres).']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, user_id, is_efemero FROM posts WHERE id = ? AND morto = 0");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(['error' => 'Post não encontrado.']);
        exit;
    }

    $sql = "INSERT INTO comments (post_id, user_id, body, created_at)
            VALUES (?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id, $userId, $body]);

    // lastInsertId() precisa ser lido logo após o INSERT: qualquer outra
    // query no meio (a UPDATE do relógio efêmero logo abaixo, por
    // exemplo) já zera o valor.
    $commentId = (int)$pdo->lastInsertId();

    // Comentário novo é o próprio ponto do post efêmero: só sobrevive o
    // que gera conversa, então cada comentário reinicia o relógio de
    // decadência a zero.
    if (!empty($post['is_efemero'])) {
        $pdo->prepare("UPDATE posts SET efemero_criado_em = NOW() WHERE id = ?")
            ->execute([$post_id]);
    }

    // Se este post é origem de um boato, o comentário vira um repasse:
    // distorce o texto da cadeia, não usa o corpo deste comentário. Não
    // faz nada se o post não for origem de rumor nenhum.
    rumor_registrar_repasse($pdo, $post_id, $userId, $commentId);

    // Devolve o comentário já montado para o front renderizar sem
    // precisar recarregar a lista inteira.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.post_id, c.user_id, c.body, c.created_at, c.edited_at,
                u.name, u.email, u.avatar
         FROM comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sem a linha de volta, `comments_comment_row()` recebia `false` num
    // parâmetro tipado `array` e derrubava a requisição inteira com fatal —
    // foi assim que o stack trace do dia 10/09 foi parar na resposta. O
    // comentário já está gravado; o que falta é só a devolução montada.
    if (!$linha) {
        http_response_code(500);
        echo json_encode(["error" => "Comentário criado, mas não foi possível carregá-lo."]);
        exit;
    }

    $comment = comments_comment_row($linha, $userId, (int)$post['user_id']);

    // `reference_id` é o post, não o comentário: o front navega
    // para o post ao clicar na notificação.
    notify($pdo, (int)$post['user_id'], $userId, 'comment', $post_id);

    // Menção no comentário avisa quem foi citado, mesmo que a pessoa não
    // tenha relação nenhuma com o post. `reference_id` continua sendo o
    // post: é para lá que o clique na notificação leva.
    notify_mentions($pdo, $body, $userId, $post_id);

    echo json_encode(['ok' => true, 'comment' => $comment]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao criar comentário.']);
}
