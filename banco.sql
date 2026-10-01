-- =====================================================================
-- Sistema Echo — schema do banco de dados
--
-- Este arquivo é a fonte da verdade do schema. Ele é idempotente:
-- pode ser executado em um banco vazio (cria tudo) ou em um banco já
-- existente (cria só o que falta e adiciona as colunas ausentes na
-- seção de migração no final do arquivo).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS banco
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE banco;

-- ---------------------------------------------------------------------
-- Usuários
-- As colunas `bio` e `avatar` são usadas por api/profile/get.php e
-- api/profile/update.php.
-- ---------------------------------------------------------------------
-- `password_hash` é NULL para conta criada via login do Google (sem
-- senha própria); `google_id` é o `sub` do token OpenID, único quando
-- presente (ver api/auth/google_callback.php).
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    google_id VARCHAR(255) DEFAULT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    bio VARCHAR(500) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Publicações
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_like (user_id, post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- `user_id` é quem compartilhou. Fica NULL apenas em linhas legadas,
-- gravadas antes de api/posts/share.php passar a registrar o autor.
CREATE TABLE IF NOT EXISTS post_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Rumor (telefone sem fio) — REMOVIDO (15/09/2026)
--
-- O recurso saiu do projeto a pedido do dono, junto com os posts
-- efêmeros. As tabelas são derrubadas aqui em vez de simplesmente
-- deixarem de ser criadas, senão um banco que já rodou a versão anterior
-- ficaria com duas tabelas órfãs e uma FK apontando para `posts`.
--
-- A ordem importa: `rumor_repasses` tem FK para `rumores`, então cai
-- primeiro. Cuidado se for reverter — derrubar leva junto toda cadeia de
-- boato que existir.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS rumor_repasses;
DROP TABLE IF EXISTS rumores;

-- ---------------------------------------------------------------------
-- Amizades
-- A coluna `status` é obrigatória: os endpoints de friends/ (send,
-- accept, reject, cancel, list, list_pending, sent_list) filtram por
-- 'pending' / 'accepted'.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS friends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_friend (user_id, friend_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Mensagens privadas (chat)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Círculos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS circles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS circle_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member (circle_id, user_id),
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Os nomes de coluna aqui (`user_id`, `message`) são os que
-- api/circle_messages/send.php e api/circle_messages/list.php já usam.
-- O plano de upgrade sugeria `sender_id` / `body`; manter os nomes
-- atuais evita quebrar esses dois endpoints.
CREATE TABLE IF NOT EXISTS circle_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_circle_id (circle_id, id),
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Hashtags
-- `hashtags` guarda a etiqueta normalizada (minúscula, sem o `#`) e
-- `post_hashtags` liga posts a etiquetas. A ligação vive numa tabela
-- própria, e não num LIKE '%#tag%' sobre `posts.content`, porque o LIKE
-- com curinga à esquerda não usa índice e casa "#php" dentro de
-- "#phpstorm".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hashtags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tag VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tag (tag)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_hashtags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    hashtag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_post_tag (post_id, hashtag_id),
    KEY idx_tag_post (hashtag_id, post_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (hashtag_id) REFERENCES hashtags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Posts salvos (o marcador de página do feed)
-- Só o dono lê a própria lista: não existe contador público de salvos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_saves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_save (user_id, post_id),
    KEY idx_saves_user (user_id, id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Notificações
-- Alimentada pelos endpoints de curtida, comentário, compartilhamento,
-- amizade, mensagem e menção; lida por api/notifications/list.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    actor_id INT NOT NULL,
    type ENUM('like', 'comment', 'share', 'friend_request', 'friend_accept', 'message', 'mention') NOT NULL,
    reference_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_unread (user_id, is_read, id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Recuperação de senha
-- Guarda apenas o hash do token; o token puro só existe no e-mail.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_token_hash (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- Migração de bancos já existentes
--
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_time (email, created_at),
    KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- Os CREATE TABLE acima não alteram tabelas que já existem. Este bloco
-- adiciona as colunas que faltam em instalações antigas, sem dar erro
-- caso elas já tenham sido criadas à mão.
-- =====================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS echo_add_column_if_missing $$

CREATE PROCEDURE echo_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @echo_ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE echo_stmt FROM @echo_ddl;
        EXECUTE echo_stmt;
        DEALLOCATE PREPARE echo_stmt;
    END IF;
END $$

DELIMITER ;

DELIMITER $$

DROP PROCEDURE IF EXISTS echo_add_fk_if_missing $$

CREATE PROCEDURE echo_add_fk_if_missing(
    IN p_table VARCHAR(64),
    IN p_name VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA    = DATABASE()
          AND TABLE_NAME      = p_table
          AND CONSTRAINT_NAME = p_name
    ) THEN
        SET @echo_fk = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` ', p_definition);
        PREPARE echo_fk_stmt FROM @echo_fk;
        EXECUTE echo_fk_stmt;
        DEALLOCATE PREPARE echo_fk_stmt;
    END IF;
END $$

DELIMITER ;

DELIMITER $$

DROP PROCEDURE IF EXISTS echo_add_index_if_missing $$

CREATE PROCEDURE echo_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_name VARCHAR(64),
    IN p_columns TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_name
    ) THEN
        SET @echo_idx = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_name, '` (', p_columns, ')');
        PREPARE echo_idx_stmt FROM @echo_idx;
        EXECUTE echo_idx_stmt;
        DEALLOCATE PREPARE echo_idx_stmt;
    END IF;
END $$

DELIMITER ;

DELIMITER $$

-- O inverso do add: usado pela migração da rede orgânica, que remove do
-- estado do motor as colunas do modelo de fio/roteiro.
DROP PROCEDURE IF EXISTS echo_drop_column_if_exists $$

CREATE PROCEDURE echo_drop_column_if_exists(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @echo_drop = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_column, '`');
        PREPARE echo_drop_stmt FROM @echo_drop;
        EXECUTE echo_drop_stmt;
        DEALLOCATE PREPARE echo_drop_stmt;
    END IF;
END $$

DELIMITER ;

CALL echo_add_column_if_missing('friends', 'status', 'ENUM(''pending'', ''accepted'') NOT NULL DEFAULT ''pending'' AFTER friend_id');
CALL echo_add_column_if_missing('users',   'bio',    'VARCHAR(500) DEFAULT NULL AFTER password_hash');
CALL echo_add_column_if_missing('users',   'avatar', 'VARCHAR(255) DEFAULT NULL AFTER bio');

-- Autor do compartilhamento (api/posts/share.php). DEFAULT NULL porque
-- instalações antigas já podem ter linhas em post_shares sem autor.
CALL echo_add_column_if_missing('post_shares', 'user_id', 'INT DEFAULT NULL AFTER post_id');
CALL echo_add_fk_if_missing('post_shares', 'fk_post_shares_user', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');

-- Marcação de leitura das mensagens privadas (api/messages/mark_read.php).
-- NULL = ainda não lida.
CALL echo_add_column_if_missing('messages', 'read_at', 'TIMESTAMP NULL DEFAULT NULL AFTER created_at');

-- Edição de post (api/posts/edit.php). NULL = nunca editado; o front
-- mostra o selo "editado" quando vier preenchido.
CALL echo_add_column_if_missing('posts', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL AFTER created_at');

-- Edição de comentário (api/comments/edit.php). Mesma semântica do
-- `edited_at` do post.
CALL echo_add_column_if_missing('comments', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL AFTER created_at');

-- Versão da sessão (api/auth/session.php). Toda sessão carrega a versão
-- que valia no login; trocar a senha incrementa a coluna e derruba as
-- sessões antigas, inclusive as abertas em outros navegadores.
CALL echo_add_column_if_missing('users', 'session_version', 'INT NOT NULL DEFAULT 1 AFTER avatar');

-- Menção (@fulano) entra por MODIFY num banco que já existia com o ENUM
-- antigo; reexecutar é inofensivo — a definição é a mesma.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('like', 'comment', 'share', 'friend_request', 'friend_accept', 'message', 'mention') NOT NULL;

-- Login com Google (16/09/2026). `google_id` é o `sub` do token OpenID —
-- estável mesmo que o usuário troque o e-mail da conta Google — usado
-- por api/auth/google_callback.php para achar a conta já vinculada.
CALL echo_add_column_if_missing('users', 'google_id', 'VARCHAR(255) DEFAULT NULL AFTER email');

SET @echo_has_google_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uniq_google_id'
);
SET @echo_google_idx_sql = IF(@echo_has_google_idx = 0,
    'ALTER TABLE users ADD UNIQUE INDEX uniq_google_id (google_id)',
    'SELECT 1'
);
PREPARE echo_google_idx_stmt FROM @echo_google_idx_sql;
EXECUTE echo_google_idx_stmt;
DEALLOCATE PREPARE echo_google_idx_stmt;

-- password_hash vira opcional: conta criada via Google não tem senha
-- própria. login.php recusa explicitamente esse caso (NULL) em vez de
-- deixar password_verify() estourar. MODIFY é idempotente.
ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) DEFAULT NULL;

-- =====================================================================
-- Índices das consultas mais quentes. Sem eles, o feed e o chat fazem
-- varredura de tabela assim que o volume cresce.
-- =====================================================================

-- Feed: ORDER BY id DESC com JOIN em users.
CALL echo_add_index_if_missing('posts', 'idx_posts_user', 'user_id');

-- Contadores por post (comment_count, like_count, share_count) e a
-- listagem de comentários de um post.
CALL echo_add_index_if_missing('comments', 'idx_comments_post', 'post_id, id');
CALL echo_add_index_if_missing('post_likes', 'idx_likes_post', 'post_id');
CALL echo_add_index_if_missing('post_shares', 'idx_shares_post', 'post_id');

-- Conversa entre duas pessoas, nas duas direções.
CALL echo_add_index_if_missing('messages', 'idx_msg_conversa', 'sender_id, receiver_id, id');
CALL echo_add_index_if_missing('messages', 'idx_msg_recebidas', 'receiver_id, sender_id, id');

-- Amizade em qualquer direção.
CALL echo_add_index_if_missing('friends', 'idx_friends_friend', 'friend_id, status');

-- Chat de círculo em ordem cronológica.
CALL echo_add_index_if_missing('circle_messages', 'idx_circle_msg', 'circle_id, id');

-- Tendências: as etiquetas dos últimos dias, contadas por post.
CALL echo_add_index_if_missing('post_hashtags', 'idx_tag_post', 'hashtag_id, post_id');
CALL echo_add_index_if_missing('post_saves', 'idx_saves_user', 'user_id, id');

-- =====================================================================
-- Posts efemeros -- REMOVIDO (15/09/2026)
--
-- O recurso (post que perdia nitidez em 24h e sumia do feed, com cada
-- comentario reiniciando o relogio) saiu do projeto a pedido do dono.
-- As tres colunas sao derrubadas aqui em vez de simplesmente deixarem de
-- ser criadas, senao um banco que ja rodou a versao anterior ficaria com
-- coluna morta para sempre -- e `morto = 1` numa delas ainda esconderia
-- post do feed, sem nenhum codigo explicando por que.
-- =====================================================================

CALL echo_drop_column_if_exists('posts', 'is_efemero');
CALL echo_drop_column_if_exists('posts', 'efemero_criado_em');
CALL echo_drop_column_if_exists('posts', 'morto');

-- =====================================================================
-- Uso da API de IA -- medicao do teto por hora
--
-- Uma linha por CHAMADA real a API da Anthropic (lote ou avulsa), nunca
-- por item gerado: e o que permite `ai_chamadas_api_na_ultima_hora()`
-- medir o teto certo. Ver AI_TETO_CHAMADAS_HORA em api/ai/nucleo/config.php
-- e o registro dentro de `ai_chamar_api()`, por onde toda chamada real
-- passa.
--
-- `user_id` e quem disparou a chamada (o professor ou o aluno numa turma):
-- e o que permite o freio POR PESSOA de api/ai/limite_uso.php, alem do
-- teto global por hora.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ai_api_uso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_api_uso_janela (criado_em)
) ENGINE=InnoDB;

CALL echo_add_column_if_missing('ai_api_uso', 'user_id', 'INT DEFAULT NULL AFTER criado_em');
CALL echo_add_index_if_missing('ai_api_uso', 'idx_ai_api_uso_user', 'user_id, criado_em');


-- ======================================================================
-- GRUPOS VERTICAIS / VERTICAL ACADEMICO (25/09/2026)
-- Ver docs/plans/grupos-verticais.md.
--
-- Um circulo ganha um `tipo`. 'social' e o grupo de sempre; 'academia' e
-- uma turma (professor = owner, alunos = circle_members). O tipo decide
-- quais ferramentas o agente do grupo expoe e quais objetos ele tem — e a
-- mesma ideia dos presets do motor: um mecanismo, N verticais. E VARCHAR e
-- nao ENUM de proposito: adicionar um vertical novo (farmacia, servicos)
-- nao deve exigir migracao de schema, so uma linha no catalogo do app.
-- ======================================================================
CALL echo_add_column_if_missing('circles', 'tipo', "VARCHAR(20) NOT NULL DEFAULT 'social' AFTER name");

-- Materiais de uma turma (PDF, texto, imagem). O professor sobe; o agente
-- age sobre eles (resumir e, depois, quiz). `conteudo_texto` guarda o texto
-- quando ha (colado ou extraido); `arquivo` guarda o caminho relativo em
-- uploads/turmas/<circle_id>/ quando o material e um arquivo. `resumo` e o
-- cache do resumo gerado pela API do Claude — gera uma vez, serve sempre,
-- pra nao repetir a chamada (e o custo) a cada aluno que abre.
CREATE TABLE IF NOT EXISTS turma_materiais (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    circle_id     INT NOT NULL,
    owner_id      INT NOT NULL,
    titulo        VARCHAR(160) NOT NULL,
    tipo_arquivo  VARCHAR(40) DEFAULT NULL,
    arquivo       VARCHAR(255) DEFAULT NULL,
    conteudo_texto MEDIUMTEXT DEFAULT NULL,
    resumo        MEDIUMTEXT DEFAULT NULL,
    resumo_em     TIMESTAMP NULL DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

CALL echo_add_index_if_missing('turma_materiais', 'idx_tm_circle', 'circle_id, id');

-- Quiz por conteudo: gerado pela API do Claude (Sonnet, porque o gabarito
-- precisa estar certo) a partir de UM material, uma vez — os alunos
-- respondem o mesmo quiz guardado, sem nova chamada. Regerar (so o
-- professor) desativa o anterior (`ativo = 0`) e cria outro; as respostas
-- antigas ficam no banco, mas painel e alerta olham so o quiz ativo.
-- `tokens_in`/`tokens_out` guardam o uso da chamada, pra conta de custo.
CREATE TABLE IF NOT EXISTS turma_quizzes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    material_id  INT NOT NULL,
    circle_id    INT NOT NULL,
    criado_por   INT NOT NULL,
    modelo       VARCHAR(60) NOT NULL,
    ativo        TINYINT(1) NOT NULL DEFAULT 1,
    tokens_in    INT DEFAULT NULL,
    tokens_out   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES turma_materiais(id) ON DELETE CASCADE,
    FOREIGN KEY (circle_id)   REFERENCES circles(id)         ON DELETE CASCADE,
    FOREIGN KEY (criado_por)  REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB;

CALL echo_add_index_if_missing('turma_quizzes', 'idx_tq_material', 'material_id, ativo');
CALL echo_add_index_if_missing('turma_quizzes', 'idx_tq_circle', 'circle_id, ativo');

-- Questoes de multipla escolha. `alternativas` e um array JSON de 4
-- strings; `correta` e o indice (0-3) — o GABARITO, que nunca vai para o
-- aluno antes de ele responder. `trecho_fonte` e a frase do material de
-- onde sai a resposta (o PHP confere que ela existe no texto); `pagina`
-- so vale para PDF. `assunto` agrupa o painel ("70% errou Normalizacao").
CREATE TABLE IF NOT EXISTS turma_quiz_questoes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id       INT NOT NULL,
    ordem         TINYINT NOT NULL,
    enunciado     TEXT NOT NULL,
    alternativas  TEXT NOT NULL,
    correta       TINYINT NOT NULL,
    assunto       VARCHAR(80) NOT NULL,
    trecho_fonte  TEXT NOT NULL,
    pagina        INT DEFAULT NULL,
    FOREIGN KEY (quiz_id) REFERENCES turma_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CALL echo_add_index_if_missing('turma_quiz_questoes', 'idx_tqq_quiz', 'quiz_id, ordem');

-- Uma linha por questao respondida. `acertou` e calculado no PHP contra o
-- gabarito; o cliente so manda a alternativa escolhida. UNIQUE por
-- (questao, aluno): responde uma vez, e uma corrida de dois envios
-- esbarra no banco em vez de gravar duas vezes.
CREATE TABLE IF NOT EXISTS turma_quiz_respostas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id     INT NOT NULL,
    questao_id  INT NOT NULL,
    user_id     INT NOT NULL,
    escolhida   TINYINT NOT NULL,
    acertou     TINYINT(1) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tqr_questao_user (questao_id, user_id),
    FOREIGN KEY (quiz_id)    REFERENCES turma_quizzes(id)       ON DELETE CASCADE,
    FOREIGN KEY (questao_id) REFERENCES turma_quiz_questoes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)               ON DELETE CASCADE
) ENGINE=InnoDB;

CALL echo_add_index_if_missing('turma_quiz_respostas', 'idx_tqr_quiz_user', 'quiz_id, user_id');

-- Material aberto pelo aluno (sinal "nao abriu" do alerta de risco). Uma
-- linha por (material, aluno), gravada com INSERT IGNORE quando ele abre o
-- material ou o resumo pelo Echo (material_abrir.php / material_resumir.php).
-- Mede "abriu pelo Echo", nao "leu": quem baixa o arquivo direto de
-- uploads/ nao aparece aqui.
CREATE TABLE IF NOT EXISTS turma_material_views (
    material_id  INT NOT NULL,
    user_id      INT NOT NULL,
    primeiro_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (material_id, user_id),
    FOREIGN KEY (material_id) REFERENCES turma_materiais(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB;

-- Entrada do aluno na turma (Fase 2). Tres formas coexistem: o professor
-- adiciona (A, ja existia via circle_members), convite por codigo (B) e
-- pedido com aprovacao (C). `codigo_convite` guarda o codigo da turma
-- (quem digitar entra); `aceita_pedidos` liga o "pedir para entrar". A
-- tabela guarda os pedidos pendentes/decididos (um por pessoa e turma).
CALL echo_add_column_if_missing('circles', 'codigo_convite', "VARCHAR(12) DEFAULT NULL AFTER tipo");
CALL echo_add_column_if_missing('circles', 'aceita_pedidos', "TINYINT(1) NOT NULL DEFAULT 0 AFTER codigo_convite");
CALL echo_add_index_if_missing('circles', 'idx_circ_codigo', 'codigo_convite');

CREATE TABLE IF NOT EXISTS circle_join_requests (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    circle_id  INT NOT NULL,
    user_id    INT NOT NULL,
    status     VARCHAR(12) NOT NULL DEFAULT 'pendente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_cjr (circle_id, user_id),
    FOREIGN KEY (circle_id) REFERENCES circles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Verificacao de professor (Fase 3). Dois niveis: qualquer um cria grupo
-- de estudo informal (sem selo); "professor verificado" ganha o selo depois
-- de solicitar e ser aprovado por um admin. `users.is_admin` marca quem
-- aprova (o dono do projeto se marca com 1 na mao); `professor_status` e o
-- estado rapido (nenhum/pendente/verificado/recusado). A tabela guarda a
-- solicitacao (area, justificativa), a triagem opcional do agente e a
-- decisao. Uma solicitacao por pessoa; pedir de novo reabre a mesma linha.
CALL echo_add_column_if_missing('users', 'is_admin', "TINYINT(1) NOT NULL DEFAULT 0 AFTER email");
CALL echo_add_column_if_missing('users', 'professor_status', "VARCHAR(12) NOT NULL DEFAULT 'nenhum' AFTER is_admin");

CREATE TABLE IF NOT EXISTS professor_solicitacoes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    area          VARCHAR(80) NOT NULL,
    justificativa TEXT NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'pendente',
    triagem_ia    TEXT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at    TIMESTAMP NULL DEFAULT NULL,
    decided_by    INT DEFAULT NULL,
    UNIQUE KEY uq_prof_sol (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


DROP PROCEDURE IF EXISTS echo_add_index_if_missing;
DROP PROCEDURE IF EXISTS echo_add_column_if_missing;
DROP PROCEDURE IF EXISTS echo_add_fk_if_missing;
DROP PROCEDURE IF EXISTS echo_drop_column_if_exists;
