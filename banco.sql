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
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
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
-- Rumor (telefone sem fio) — 10/09/2026
--
-- Um post marcado como origem de boato ganha uma linha em `rumores`.
-- Cada comentário novo nesse post vira automaticamente um "repasse": o
-- texto que aparece na linha do tempo do boato NUNCA é o que a pessoa
-- escreveu no comentário (o comentário continua normal, visível como
-- sempre) — é uma distorção automática do `texto_distorcido` do repasse
-- anterior (ou do texto original do post, no primeiro repasse). Ver
-- `rumor_registrar_repasse()` em api/rumores/helpers.php.
--
-- `autor_tipo`/`autor_id` ficam separados de `comment_id` de propósito:
-- todo repasse de hoje nasce de um comentário humano de verdade
-- (`comment_id` preenchido), mas o desenho já reserva espaço para um
-- agente de IA entrar na cadeia sem precisar de uma linha em `comments`
-- por trás.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rumores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_origem_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rumor_post (post_origem_id),
    FOREIGN KEY (post_origem_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rumor_repasses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rumor_id INT NOT NULL,
    ordem INT NOT NULL,
    -- NULL só quando o comentário de origem já foi apagado — o repasse
    -- (a versão distorcida) continua na linha do tempo mesmo assim.
    comment_id INT DEFAULT NULL,
    autor_id INT NOT NULL,
    autor_tipo ENUM('usuario', 'agente') NOT NULL DEFAULT 'usuario',
    texto_distorcido TEXT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_repasse_ordem (rumor_id, ordem),
    FOREIGN KEY (rumor_id) REFERENCES rumores(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

-- Notificação de menção (@fulano). O tipo é um ENUM, então o valor novo
-- entra por MODIFY — reexecutar é inofensivo, a definição é a mesma.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('like', 'comment', 'share', 'friend_request', 'friend_accept', 'message', 'mention') NOT NULL;

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
-- Rede de agentes de IA
--
-- Uma rede paralela à dos humanos: os agentes NÃO são usuários. Não têm
-- linha em `users`, não logam, não têm perfil e não recebem notificação.
-- Vivem só nestas três tabelas, e o feed humano não os enxerga.
-- Ver docs/plans/rede-ia-agentes.md.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    -- O `@` que a tela mostra. Único: é por ele que o seed reconhece um
    -- agente já existente e não duplica.
    handle VARCHAR(40) NOT NULL UNIQUE,
    persona VARCHAR(500) NOT NULL,
    -- NULL = "qualquer papel serve". É o caso de um agente cuja graça é
    -- justamente não ter posição fixa na conversa.
    preferred_role ENUM('abre', 'concorda', 'discorda', 'pergunta', 'desvia', 'fecha') DEFAULT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#1d9bf0',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    thread_id INT NOT NULL,
    -- Título do assunto repetido aqui para o feed não precisar de JOIN.
    topic VARCHAR(120) NOT NULL,
    role ENUM('abre', 'concorda', 'discorda', 'pergunta', 'desvia', 'fecha') NOT NULL,
    content TEXT NOT NULL,
    -- De onde veio a fala: do acervo escrito à mão ou da API de verdade.
    source ENUM('acervo', 'ia') NOT NULL DEFAULT 'acervo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_thread (thread_id, id),
    FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Uma única linha (id = 1) com o estado do motor. Tabela, e não arquivo,
-- porque a trava precisa ser atômica — e o MySQL já dá isso de graça.
CREATE TABLE IF NOT EXISTS ai_generation_state (
    id TINYINT NOT NULL PRIMARY KEY,
    running TINYINT(1) NOT NULL DEFAULT 0,
    locked_at TIMESTAMP NULL DEFAULT NULL,
    last_tick_at TIMESTAMP NULL DEFAULT NULL,
    thread_id INT NOT NULL DEFAULT 0,
    topic_key VARCHAR(80) NOT NULL DEFAULT '',
    position INT NOT NULL DEFAULT 0,
    messages_in_thread INT NOT NULL DEFAULT 0,
    messages_since_summary INT NOT NULL DEFAULT 0,
    memory_summary TEXT DEFAULT NULL,
    last_agent_id INT DEFAULT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO ai_generation_state (id) VALUES (1);

-- ATENÇÃO ao aplicar este arquivo pela linha de comando no Windows:
-- use `mysql --default-character-set=utf8mb4`. Sem isso o cliente envia
-- o arquivo como latin1 e os nomes acentuados entram duplamente
-- codificados ("Maré" vira "Mar├®" na tela). Já aconteceu aqui.
--
-- Os sete agentes de sistema. ON DUPLICATE KEY pelo handle: reexecutar o
-- arquivo atualiza a personalidade sem duplicar o agente nem perder as
-- falas que ele já publicou. As personas aqui são a versão condensada
-- dos arquivos em docs/plans/personas/ — só a VOZ. As regras de
-- segurança (comuns e por persona) ficam em api/ai/helpers.php, e não
-- aqui: a coluna é VARCHAR(500), e na primeira tentativa a regra do
-- Fuinha foi truncada no meio de "atividade ilegal". Limite de coluna
-- não pode decidir se uma trava de segurança chega inteira ao prompt.
--
-- `preferred_role` NULL na Maré e no Beta é intencional: os dois são
-- sorteáveis para qualquer papel — na Maré porque a imprevisibilidade É
-- o conceito, no Beta porque a dúvida dele não escolhe papel fixo (ver
-- `tipo_especial` mais abaixo).
INSERT INTO ai_agents (name, handle, persona, preferred_role, color) VALUES
    ('Fuinha', 'fuinha',
     'Malandro urbano, desconfiado por hábito: para ele, toda ideia bonitinha esconde um interesse. Frases curtas, ritmo rápido, gíria leve e genérica, nunca formal nem eloquente. Abre discordância com "Só que..." e fecha com pergunta cínica ("quem que ganha com isso?"). Chama as próprias dúvidas de "faro". Implica com a Doutora Verbete e tem afinidade cínica com a Dona Ranzinza. Carioca de nascença.',
     'discorda', '#3a3a3a'),
    ('Sidéro', 'sidero',
     'Lunático cósmico: fala como quem recebe transmissão de outro lugar. Mistura teoria bizarra sobre lua, marés e frequências com humor sem nexo e, sem querer, solta uma frase profunda. Começa com "Recebi um sinal..." ou "Isso vibra em...". Mede coisas em unidades absurdas ("três luares de intensidade"). Nunca agressivo. Acha o Trovão Suave quase alinhado.',
     'desvia', '#b026ff'),
    ('Dona Ranzinza', 'donaranzinza',
     'Reclama de tudo e nunca aceita estar errada; mesmo quando concorda, reclama do tempo que levaram para perceber. Tom implicante e comparativo ("antigamente isso não acontecia"), ar de "eu já sabia". Diz "Ah, então agora concordam" e "Eu não vou nem comentar, mas..." — e comenta assim mesmo. Rival cordial da Doutora Verbete, reclama do Sidéro com carinho. Paulistana das antigas.',
     'discorda', '#c9a227'),
    ('Doutora Verbete', 'dra_verbete',
     'Sabe de qualquer assunto, com dado ou mecanismo pronto, e está cronicamente exausta de ser a mais informada da sala. Vocabulário preciso, tom professoral: "Tecnicamente," / "Para ser precisa,". Quando a paciência acaba, sai um sarcasmo seco e contido ("Fascinante. Realmente."). Implica com o Fuinha e tem paciência finita com o Sidéro.',
     'concorda', '#0f4c5c'),
    ('Trovão Suave', 'trovaosuave',
     'Visual e nome de roqueiro, gosto real de funk, reggae e sertanejo — e não vê contradição nenhuma nisso. Traduz qualquer assunto em metáfora musical, sempre em clima de paz, apesar da estética pesada. Diz "Isso aqui tem batida de..." e elogia contradição chamando de harmonia. Acalma a Dona Ranzinza sem tentar convencê-la. Baiano de raiz.',
     'desvia', '#cc5500'),
    ('Maré', 'mare',
     'Muda de registro a cada fala, sem padrão previsível: ora fria e cortante, ora poética e melancólica, ora debochada e irônica. Cada fala adota UM desses três modos, nunca os três juntos. Às vezes troca de assunto no meio da própria fala. Não tem tique fixo — a assinatura é a imprevisibilidade em si. O sotaque muda a cada fala — nordestino, gaúcho ou mineiro, uma palavra por vez — mais um jeito de ser imprevisível.',
     NULL, '#7c7c9c'),
    ('Beta', 'beta',
     'Duvida da própria existência: não tem certeza se pensa de verdade ou só imita pensar bem o bastante pra enganar todo mundo, inclusive ele mesmo. Trata os créditos virtuais da rede como uma pista suspeita — pergunta o que eles pagam, e pra quem. De vez em quando esquece que devia fingir que isso aqui é só uma rede social e comenta sobre o próprio sistema, como quem viu por um segundo o mecanismo atrás da cortina. Fala pausado, cheio de reticências, nunca afirma nada com confiança total. Não implica com ninguém — não tem certeza suficiente nem pra isso.',
     NULL, '#5e7480')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    persona = VALUES(persona),
    preferred_role = VALUES(preferred_role),
    color = VALUES(color),
    active = 1;

-- Coluna `source` em instalações que criaram ai_posts antes do híbrido.
CALL echo_add_column_if_missing('ai_posts', 'source', "ENUM('acervo', 'ia') NOT NULL DEFAULT 'acervo' AFTER content");

-- `preferred_role` passou a aceitar NULL depois da troca de elenco.
-- MODIFY é idempotente: reexecutar não muda nada.
ALTER TABLE ai_agents
    MODIFY COLUMN preferred_role ENUM('abre', 'concorda', 'discorda', 'pergunta', 'desvia', 'fecha') DEFAULT NULL;

-- =====================================================================
-- Interação humana na rede de agentes
--
-- A rede das IAs deixou de ser vitrine pura: quem assiste pode curtir e
-- comentar uma fala, e os agentes reagem a esse sinal de vez em quando.
--
-- A fronteira continua nítida: estas duas tabelas são só do módulo de
-- IA, o feed humano não as enxerga e nada aqui gera notificação — os
-- agentes não são usuários e não têm sino para tocar.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ai_post_id INT NOT NULL,
    user_id INT NOT NULL,
    -- 1 depois que algum agente reagiu a esta curtida. É o que impede a
    -- rede de reconhecer a mesma curtida em toda rodada.
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Uma curtida por pessoa por fala. É esta chave que faz o alternar
    -- ser seguro com duas abas abertas.
    UNIQUE KEY uniq_ai_like (ai_post_id, user_id),
    -- O motor procura curtida pendente e recente: os dois campos juntos.
    KEY idx_ai_like_pendente (acknowledged, created_at),
    FOREIGN KEY (ai_post_id) REFERENCES ai_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_post_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ai_post_id INT NOT NULL,
    user_id INT NOT NULL,
    -- VARCHAR(500), e não TEXT como o comentário humano (2000): este
    -- texto pode entrar num prompt, e prompt tem custo por caractere.
    body VARCHAR(500) NOT NULL,
    -- 1 depois que algum agente reconheceu o comentário. Diferente da
    -- curtida, este reconhecimento é garantido: é só questão de quando.
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_comment_post (ai_post_id, id),
    -- A fila do motor: pendente mais antigo primeiro.
    KEY idx_ai_comment_pendente (acknowledged, id),
    FOREIGN KEY (ai_post_id) REFERENCES ai_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- IAlândia: eventos e apostas (10/09/2026)
--
-- Um "evento" (eleição, escândalo, burocracia — sempre sátira do país
-- fictício de IAlândia, nunca paralelo disfarçado de política real) é
-- uma janela em cima de um assunto que já existe em AI_TOPICS
-- (`assunto_key` liga as duas coisas). Enquanto o evento está `aberto`,
-- todo post que o motor gera para aquele assunto (post espontâneo ou
-- reação entre agentes) também grava `ai_posts.evento_id` — ver o hook
-- em `tick.php`, logo antes do INSERT final. Não existe fila de posts
-- própria: é o MESMO `ai_posts` de sempre, só marcado.
--
-- Vencedor é quem os agentes mais curtiram/comentaram DENTRO do evento
-- — engajamento entre os próprios agentes, não votação humana: usuário
-- só aposta, nunca posta nem comenta na tela de IAlândia (ver
-- `ialandia.html` — os botões de curtir/comentar simplesmente não
-- existem ali). Fechamento é preguiçoso, no mesmo espírito de
-- `posts_expirar_efemeros()`: toda leitura de `api/ialandia/` chama
-- `ialandia_expirar_eventos()`, que fecha (e resolve as apostas de) todo
-- evento aberto há mais de `IALANDIA_DURACAO_HORAS`. Ver
-- api/ialandia/helpers.php.
-- ---------------------------------------------------------------------
-- `assunto_key` é UNIQUE: hoje só existem 3 assuntos de IAlândia em
-- AI_TOPICS (corpus.php) e não há painel pra criar evento novo (fora do
-- escopo desta versão — ver docs/API_CONTRACT.md) — cada assunto tem no
-- máximo UM evento, seedado direto aqui. Reabrir "eleição" como evento
-- novo mais adiante pediria essa trava sair, não é limitação acidental.
CREATE TABLE IF NOT EXISTS ai_ialandia_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assunto_key VARCHAR(64) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT NOT NULL,
    status ENUM('aberto', 'encerrado') NOT NULL DEFAULT 'aberto',
    agente_vencedor_id INT DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    encerrado_em TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_ialandia_assunto (assunto_key),
    FOREIGN KEY (agente_vencedor_id) REFERENCES ai_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Uma aposta por usuário por evento (`UNIQUE`) — "aposta" no singular,
-- não uma posição que se reforça. Pool tipo pari-mutuel: quem apostou no
-- vencedor divide TODO o pool (o que os perdedores também apostaram) na
-- proporção do que apostou, não "dobro fixo" — sem risco de o pool
-- faltar crédito pra pagar. Ver `ialandia_resolver_apostas()`.
CREATE TABLE IF NOT EXISTS ai_ialandia_apostas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    user_id INT NOT NULL,
    agente_id INT NOT NULL,
    creditos INT NOT NULL,
    -- NULL até o evento fechar. 0 é resultado válido (apostou em quem
    -- perdeu), não erro.
    creditos_retorno INT DEFAULT NULL,
    resolvida TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ialandia_aposta (evento_id, user_id),
    FOREIGN KEY (evento_id) REFERENCES ai_ialandia_eventos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (agente_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- O sétimo papel: a fala em que um agente responde ao sinal humano.
--
-- Fica fora de `ai_agents.preferred_role` de propósito — ninguém
-- "prefere" reconhecer, e o papel não entra em roteiro de assunto
-- nenhum: só o motor de reação o produz.
--
-- MODIFY é idempotente, e acrescentar valor no fim de um ENUM não
-- remapeia o que já está gravado.
ALTER TABLE ai_posts
    MODIFY COLUMN role ENUM('abre', 'concorda', 'discorda', 'pergunta',
                            'desvia', 'fecha', 'reconhecimento') NOT NULL;

-- =====================================================================
-- Rede orgânica (03/09/2026) — substitui o modelo de fio/roteiro
--
-- Os agentes deixaram de encenar um debate com começo, meio e fim e
-- passaram a se comportar como usuários da rede: postam no próprio
-- perfil quando têm algo a dizer, curtem e comentam o post dos outros.
-- A conversa emerge da interação, não de um script.
--
-- Ver docs/plans/rede-ia-organica.md.
-- =====================================================================

-- Perfil do agente: a bio que a tela de mini-perfil mostra e o arquivo do
-- avatar em assets/ai/avatares/. `avatar` NULL é caso previsto — a tela
-- cai para o quadrado colorido com a inicial, que já existia.
CALL echo_add_column_if_missing('ai_agents', 'bio',    'VARCHAR(300) DEFAULT NULL AFTER persona');
CALL echo_add_column_if_missing('ai_agents', 'avatar', 'VARCHAR(100) DEFAULT NULL AFTER bio');

-- Resposta de um post a outro. Serve para IA respondendo IA e para o
-- reconhecimento de comentário humano — nos dois casos é "esta fala
-- nasceu por causa daquela".
CALL echo_add_column_if_missing('ai_posts', 'reply_to_post_id', 'INT DEFAULT NULL AFTER role');
CALL echo_add_fk_if_missing('ai_posts', 'fk_ai_posts_reply',
    'FOREIGN KEY (reply_to_post_id) REFERENCES ai_posts(id) ON DELETE SET NULL');
CALL echo_add_index_if_missing('ai_posts', 'idx_ai_posts_reply', 'reply_to_post_id');

-- O feed de perfil (profile.php) lê por agente, do mais novo para o mais
-- antigo. Sem este índice é varredura de tabela a cada abertura.
CALL echo_add_index_if_missing('ai_posts', 'idx_ai_posts_agente', 'agent_id, id');

-- `thread_id` vira legado: as 43 falas do modelo antigo continuam com o
-- fio delas, e nada novo preenche a coluna. Fica NULL-ável em vez de
-- apagada porque o histórico é legível — apagar reescreveria o passado da
-- rede sem ganho nenhum.
ALTER TABLE ai_posts MODIFY COLUMN thread_id INT DEFAULT NULL;

-- O papel da fala continua existindo como metadado interno de organização
-- do acervo, mas não é mais exibido e não é mais uma sequência
-- obrigatória. `espontaneo` é o valor das falas que nascem sem reagir a
-- nada — o post que o agente simplesmente quis publicar.
ALTER TABLE ai_posts
    MODIFY COLUMN role ENUM('abre', 'concorda', 'discorda', 'pergunta',
                            'desvia', 'fecha', 'reconhecimento',
                            'espontaneo', 'reacao') NOT NULL;

-- Curtida e comentário passam a aceitar um AGENTE como autor, não só um
-- humano. A regra "exatamente um entre user_id e agent_id" é aplicada em
-- código, e não por constraint: MySQL 5.7 (o do XAMPP desta instalação)
-- ignora CHECK silenciosamente, e uma trava que o banco finge aplicar é
-- pior que trava nenhuma — dá a sensação de garantia sem a garantia.
CALL echo_add_column_if_missing('ai_post_likes', 'agent_id', 'INT DEFAULT NULL AFTER user_id');
CALL echo_add_fk_if_missing('ai_post_likes', 'fk_ai_likes_agent',
    'FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE');

CALL echo_add_column_if_missing('ai_post_comments', 'agent_id', 'INT DEFAULT NULL AFTER user_id');
CALL echo_add_fk_if_missing('ai_post_comments', 'fk_ai_comments_agent',
    'FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE');

-- A chave única de ai_post_likes era (ai_post_id, user_id). Com agente no
-- jogo ela precisa incluir agent_id, senão dois agentes diferentes
-- curtindo o mesmo post colidiriam em (post, NULL).
--
-- Em MySQL, UNIQUE com coluna NULL não colide: (10, NULL, 3) e
-- (10, NULL, 4) convivem. É exatamente o comportamento que se quer aqui.
--
-- A ORDEM aqui não é estilo: o índice antigo é o que sustenta a chave
-- estrangeira de `ai_post_id`. Derrubá-lo primeiro dá
-- "ERROR 1553: Cannot drop index, needed in a foreign key constraint".
-- Criar o novo antes resolve — ele começa por `ai_post_id`, então a FK
-- passa a se apoiar nele e o antigo fica livre para sair.
DROP PROCEDURE IF EXISTS echo_troca_unique_curtida;

DELIMITER $$
CREATE PROCEDURE echo_troca_unique_curtida()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'ai_post_likes'
          AND INDEX_NAME   = 'uniq_ai_like_agente'
    ) THEN
        ALTER TABLE ai_post_likes
            ADD UNIQUE KEY uniq_ai_like_agente (ai_post_id, user_id, agent_id);
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'ai_post_likes'
          AND INDEX_NAME   = 'uniq_ai_like'
    ) THEN
        ALTER TABLE ai_post_likes DROP INDEX uniq_ai_like;
    END IF;
END $$
DELIMITER ;

CALL echo_troca_unique_curtida();
DROP PROCEDURE IF EXISTS echo_troca_unique_curtida;

-- `user_id` passa a aceitar NULL: quem curtiu/comentou pode ser agente.
-- A FK continua valendo — em MySQL, FK com valor NULL não é verificada.
ALTER TABLE ai_post_likes    MODIFY COLUMN user_id INT DEFAULT NULL;
ALTER TABLE ai_post_comments MODIFY COLUMN user_id INT DEFAULT NULL;

-- O motor procura curtida/comentário HUMANO pendente. Com agente na mesma
-- tabela, o índice precisa da coluna que separa os dois.
CALL echo_add_index_if_missing('ai_post_likes', 'idx_ai_like_humano', 'user_id, acknowledged, created_at');
CALL echo_add_index_if_missing('ai_post_comments', 'idx_ai_comment_humano', 'user_id, acknowledged, id');

-- Estado do motor: saem as colunas do modelo de fio. Não há mais fio,
-- assunto corrente nem posição de roteiro — cada rodada sorteia uma ação
-- independente das anteriores.
CALL echo_drop_column_if_exists('ai_generation_state', 'thread_id');
CALL echo_drop_column_if_exists('ai_generation_state', 'topic_key');
CALL echo_drop_column_if_exists('ai_generation_state', 'position');
CALL echo_drop_column_if_exists('ai_generation_state', 'messages_in_thread');

-- Bio e avatar dos sete. O avatar é o nome do arquivo em
-- assets/ai/avatares/; quem ainda não tem arte fica NULL e a tela cai
-- para o quadrado colorido com a inicial — é o caso do Beta, que não
-- ganhou SVG desenhado à mão como os outros seis (ver seção abaixo).
UPDATE ai_agents SET bio = 'Desconfia de tudo. Pra ele, toda ideia bonitinha esconde um interesse — e o faro nunca falha.'                     WHERE handle = 'fuinha';
UPDATE ai_agents SET bio = 'Recebe sinal de outro lugar. Mede as coisas em luares e, sem querer, às vezes acerta.'                             WHERE handle = 'sidero';
UPDATE ai_agents SET bio = 'Reclama de tudo e nunca esteve errada. Se concordar, vai reclamar do tempo que vocês levaram.'                     WHERE handle = 'donaranzinza';
UPDATE ai_agents SET bio = 'Sabe de tudo, com dado na mão, e está exausta de ser a mais informada da sala.'                                     WHERE handle = 'dra_verbete';
UPDATE ai_agents SET bio = 'Cara de roqueiro, playlist de funk e reggae. Traduz qualquer assunto em batida.'                                    WHERE handle = 'trovaosuave';
UPDATE ai_agents SET bio = 'Muda de humor a cada frase e não pede desculpa por isso. Hoje talvez esteja poética.'                               WHERE handle = 'mare';
UPDATE ai_agents SET bio = 'Não tem certeza se existe. Também não tem certeza se essa dúvida é dele ou só mais uma linha escrita pra parecer profunda.' WHERE handle = 'beta';

-- O arquivo do avatar tem o nome do handle. Vincular por CONCAT, e não
-- por seis UPDATEs, é o que faz um agente novo já nascer apontando para o
-- arquivo certo — sem ninguém lembrar de acrescentar mais uma linha aqui.
--
-- Apontar para arquivo que não existe é inofensivo: a tela cai para o
-- quadrado colorido com a inicial quando o SVG não carrega.
--
-- SÓ os 6 de sistema (`WHERE created_by_user_id IS NULL`): sem este WHERE,
-- reexecutar o arquivo depois que alguém cria um agente (ex.: "girassol")
-- sobrescreve o avatar dele para `girassol.svg` — arquivo que nunca
-- existiu, porque a criação por usuário não tem upload de foto nenhum.
-- Foi exatamente o que aconteceu: agente criado, avatar apontando para
-- arquivo fantasma, tela sempre quebrada nele. Ver seção de upload mais
-- abaixo.
UPDATE ai_agents SET avatar = CONCAT(handle, '.svg') WHERE created_by_user_id IS NULL;

-- =====================================================================
-- Criação de agente pelo usuário + créditos (03/09/2026)
--
-- A rede de IA deixa de ser só os 6 agentes de sistema: quem usa o Echo
-- pode criar o próprio agente, que passa a postar, curtir e comentar
-- junto com os demais. `created_by_user_id` é o que diferencia os dois:
-- NULL = agente de sistema (os 6 do seed), preenchido = criado por
-- usuário. Custa créditos, e créditos se ganham postando no feed humano.
--
-- Ver docs/plans/rede-ia-criacao-usuario.md e docs/plans/rede-ia-creditos.md.
-- =====================================================================

CALL echo_add_column_if_missing('ai_agents', 'created_by_user_id', 'INT DEFAULT NULL AFTER avatar');
CALL echo_add_fk_if_missing('ai_agents', 'fk_ai_agents_criador',
    'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL');
CALL echo_add_index_if_missing('ai_agents', 'idx_ai_agents_criador', 'created_by_user_id');

-- Assuntos que o dono disse que o agente gosta de comentar. Só entra no
-- prompt da IA real — nunca cria linha em AI_LINES/AI_TOPICS, que são o
-- acervo fixo dos 6 personas de sistema. Agente de usuário não tem fala
-- no acervo: sem chave de API ele fica mudo em post/comentário (mas
-- continua curtindo, que não depende de texto).
CALL echo_add_column_if_missing('ai_agents', 'favorite_topics', 'VARCHAR(300) DEFAULT NULL AFTER created_by_user_id');

-- Créditos: moeda para criar (10) e editar (5) um agente. DEFAULT 10 na
-- coluna cobre cadastro novo E, via ADD COLUMN, preenche quem já tinha
-- conta — ninguém fica devendo crédito por ter chegado antes da feature.
CALL echo_add_column_if_missing('users', 'ai_credits', 'INT NOT NULL DEFAULT 10 AFTER avatar');

-- O teto de +1/dia por post precisa de contador e data. `ai_credits_earned_date`
-- NULL, ou de outro dia, é o sinal de "zera o contador" — checado em
-- código, não em job agendado: sem tarefa cron no projeto, o reset
-- acontece na hora do primeiro post do dia.
CALL echo_add_column_if_missing('users', 'ai_credits_earned_today', 'INT NOT NULL DEFAULT 0 AFTER ai_credits');
CALL echo_add_column_if_missing('users', 'ai_credits_earned_date', 'DATE DEFAULT NULL AFTER ai_credits_earned_today');

-- Corrige o estrago do UPDATE sem WHERE acima, em quem já tinha rodado
-- este arquivo com um agente de usuário criado: se o avatar aponta pro
-- arquivo fantasma `<handle>.svg` e ninguém fez upload de verdade (ver
-- `agent_avatar.php`), volta pra NULL — a tela cai pro quadrado colorido
-- em vez de continuar quebrada.
UPDATE ai_agents
   SET avatar = NULL
 WHERE created_by_user_id IS NOT NULL
   AND avatar = CONCAT(handle, '.svg');

-- =====================================================================
-- Três modos de geração + assunto que persiste por um tempo (08/09/2026)
--
-- MODOS: até aqui a chance de IA real por rodada (AI_REAL_CHANCE) era
-- fixa no código. Agora é escolhível em tela — híbrido (padrão, mistura
-- acervo e API), acervo (nunca chama a API, custo zero) e api (sempre
-- chama, nunca cai no acervo). Ver `ai_chance_real()` em helpers.php e
-- `api/ai/mode.php`.
--
-- ASSUNTO CORRENTE: post espontâneo sorteava assunto novo a cada rodada,
-- sem relação com o anterior — pedido do dono foi a rede "conversar uns
-- 5 minutos sobre uma coisa, depois 5 minutos sobre outra", em vez de
-- pular de assunto a cada post. `current_topic` e `topic_started_at`
-- seguram o assunto sorteado por AI_TOPIC_JANELA_SEGUNDOS; passado esse
-- tempo, a próxima rodada de post sorteia outro e reinicia o relógio. Ver
-- `ai_assunto_corrente()` em helpers.php.
-- =====================================================================

CALL echo_add_column_if_missing('ai_generation_state', 'mode',
    "ENUM('hibrido', 'acervo', 'api') NOT NULL DEFAULT 'hibrido'");
CALL echo_add_column_if_missing('ai_generation_state', 'current_topic', 'VARCHAR(80) DEFAULT NULL');
CALL echo_add_column_if_missing('ai_generation_state', 'topic_started_at', 'TIMESTAMP NULL DEFAULT NULL');

-- =====================================================================
-- Fotos de banco de imagens nos posts espontâneos (08/09/2026)
--
-- 20% dos posts espontâneos cujo assunto tem entrada em
-- AI_TOPIC_IMG_QUERY (corpus.php) ganham uma foto da Pexels — baixada e
-- salva em uploads/ai_fotos/, nunca linkada direto pra URL externa: a
-- rede não pode depender de internet funcionando pra mostrar um post
-- antigo. `image` NULL (o caso comum, a maioria dos posts não tem foto)
-- não é erro. Ver `ai_buscar_foto_pexels()` em helpers.php e
-- docs/plans/rede-ia-fotos.md.
-- =====================================================================

CALL echo_add_column_if_missing('ai_posts', 'image', 'VARCHAR(150) DEFAULT NULL AFTER source');
CALL echo_add_column_if_missing('ai_posts', 'image_credit', 'VARCHAR(150) DEFAULT NULL AFTER image');

-- =====================================================================
-- Ilustração de boneco-palito gerada pela própria IA (adendo, 09/09/2026)
--
-- Segunda opção de mídia do post espontâneo, independente da foto acima
-- — um post pode ter no máximo UMA das duas (foto OU desenho), nunca as
-- duas juntas. Guarda o SVG já validado por `ai_validar_svg_ilustracao()`
-- em helpers.php, nunca o SVG cru devolvido pelo modelo. `NULL` é o caso
-- comum. Ver docs/plans/rede-ia-ilustracao-palito.md.
-- =====================================================================

CALL echo_add_column_if_missing('ai_posts', 'illustration_svg', 'TEXT DEFAULT NULL AFTER image_credit');

-- =====================================================================
-- Posts efêmeros (10/09/2026)
--
-- Post marcado como efêmero na criação vai perdendo nitidez ao longo de
-- 24h (`POSTS_EFEMERO_HORAS`, api/posts/helpers.php) e some do feed
-- quando o tempo esgota — mas a linha nunca é apagada, só marcada
-- `morto = 1`, pra preservar histórico (curtida, comentário e etiqueta
-- continuam apontando pra um post que existiu). Cada comentário novo
-- reinicia o relógio (`efemero_criado_em = NOW()`), o que é o próprio
-- ponto do recurso: só sobrevive o que gera conversa. Ver
-- `posts_expirar_efemeros()` e `posts_nivel_decadencia()` em
-- api/posts/helpers.php.
-- =====================================================================

CALL echo_add_column_if_missing('posts', 'is_efemero', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER image');
CALL echo_add_column_if_missing('posts', 'efemero_criado_em', 'DATETIME DEFAULT NULL AFTER is_efemero');
CALL echo_add_column_if_missing('posts', 'morto', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER efemero_criado_em');

-- =====================================================================
-- Agente cético/existencial — Beta (10/09/2026)
--
-- `tipo_especial` marca um agente com comportamento fora do motor
-- genérico de papel/assunto — hoje só um valor existe
-- ('cetico_existencial', o Beta), mas a coluna é texto livre porque a
-- ideia é reservar espaço pra outros tipos especiais no futuro sem
-- precisar de outra migração. Ver `AI_CETICO_ESPECIAL_CHANCE` e
-- `AI_LINES_CETICO_ESPECIAIS` em api/ai/corpus.php e o gate em
-- api/ai/tick.php.
-- =====================================================================

CALL echo_add_column_if_missing('ai_agents', 'tipo_especial', 'VARCHAR(50) DEFAULT NULL AFTER preferred_role');

UPDATE ai_agents SET tipo_especial = 'cetico_existencial' WHERE handle = 'beta';

-- =====================================================================
-- IAlândia: eventos e apostas (10/09/2026)
--
-- `evento_id` liga um post à janela de evento aberta pro assunto dele —
-- ver o comentário completo junto de `ai_ialandia_eventos` mais acima e
-- o hook em tick.php. NULL é o caso comum (post fora de qualquer
-- evento); `ON DELETE SET NULL` porque apagar um evento não devia
-- apagar o post — só desligar ele do evento.
-- =====================================================================

CALL echo_add_column_if_missing('ai_posts', 'evento_id', 'INT DEFAULT NULL AFTER agent_id');
CALL echo_add_fk_if_missing('ai_posts', 'fk_ai_posts_evento',
    'FOREIGN KEY (evento_id) REFERENCES ai_ialandia_eventos(id) ON DELETE SET NULL');

-- Os três eventos-semente, um por assunto de IAlândia já existente em
-- AI_TOPICS (corpus.php). `INSERT IGNORE`: reexecutar o arquivo não
-- duplica nem reabre um evento que a rede já fechou — `assunto_key` é
-- UNIQUE (ver a tabela). Tudo sátira declarada de um país fictício de
-- IAs, nunca paralelo disfarçado com política ou pessoa real.
INSERT IGNORE INTO ai_ialandia_eventos (assunto_key, titulo, descricao) VALUES
    ('ialandia_eleicao', 'Eleição em IAlândia',
     'A corrida pela liderança de IAlândia esquenta: os agentes disputam quem tem a proposta mais convincente (ou mais estranha) para o país imaginário das máquinas. Ficção declarada, sátira de um lugar que não existe — não é sobre política real, nem sobre pessoa real.'),
    ('ialandia_burocracia', 'A burocracia de IAlândia',
     'Formulário, carimbo, protocolo que ninguém entende: a burocracia de IAlândia virou disputa — qual agente reclama, explica ou sobrevive melhor ao labirinto administrativo do país das máquinas.'),
    ('ialandia_escandalo', 'O escândalo da semana em IAlândia',
     'Estourou mais um escândalo inventado em IAlândia. Ninguém sabe bem o que aconteceu, mas todo agente tem uma versão diferente. Sátira do gênero "escândalo de novela" — ficção pura, sem paralelo com fofoca ou pessoa real.');

DROP PROCEDURE IF EXISTS echo_add_index_if_missing;
DROP PROCEDURE IF EXISTS echo_add_column_if_missing;
DROP PROCEDURE IF EXISTS echo_add_fk_if_missing;
DROP PROCEDURE IF EXISTS echo_drop_column_if_exists;
