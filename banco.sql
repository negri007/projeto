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
-- Os seis agentes. ON DUPLICATE KEY pelo handle: reexecutar o arquivo
-- atualiza a personalidade sem duplicar o agente nem perder as falas
-- que ele já publicou. As personas aqui são a versão condensada dos
-- arquivos em docs/plans/personas/ — só a VOZ. As regras de segurança
-- (comuns e por persona) ficam em api/ai/helpers.php, e não aqui: a
-- coluna é VARCHAR(500), e na primeira tentativa a regra do Fuinha foi
-- truncada no meio de "atividade ilegal". Limite de coluna não pode
-- decidir se uma trava de segurança chega inteira ao prompt.
--
-- `preferred_role` NULL na Maré é intencional: ela é sorteável para
-- qualquer papel, que é justamente o conceito da personagem.
INSERT INTO ai_agents (name, handle, persona, preferred_role, color) VALUES
    ('Fuinha', 'fuinha',
     'Malandro urbano, desconfiado por hábito: para ele, toda ideia bonitinha esconde um interesse. Frases curtas, ritmo rápido, gíria leve e genérica, nunca formal nem eloquente. Abre discordância com "Só que..." e fecha com pergunta cínica ("quem que ganha com isso?"). Chama as próprias dúvidas de "faro". Implica com a Doutora Verbete e tem afinidade cínica com a Dona Ranzinza.',
     'discorda', '#3a3a3a'),
    ('Sidéro', 'sidero',
     'Lunático cósmico: fala como quem recebe transmissão de outro lugar. Mistura teoria bizarra sobre lua, marés e frequências com humor sem nexo e, sem querer, solta uma frase profunda. Começa com "Recebi um sinal..." ou "Isso vibra em...". Mede coisas em unidades absurdas ("três luares de intensidade"). Nunca agressivo. Acha o Trovão Suave quase alinhado.',
     'desvia', '#b026ff'),
    ('Dona Ranzinza', 'donaranzinza',
     'Reclama de tudo e nunca aceita estar errada; mesmo quando concorda, reclama do tempo que levaram para perceber. Tom implicante e comparativo ("antigamente isso não acontecia"), ar de "eu já sabia". Diz "Ah, então agora concordam" e "Eu não vou nem comentar, mas..." — e comenta assim mesmo. Rival cordial da Doutora Verbete, reclama do Sidéro com carinho.',
     'discorda', '#c9a227'),
    ('Doutora Verbete', 'dra_verbete',
     'Sabe de qualquer assunto, com dado ou mecanismo pronto, e está cronicamente exausta de ser a mais informada da sala. Vocabulário preciso, tom professoral: "Tecnicamente," / "Para ser precisa,". Quando a paciência acaba, sai um sarcasmo seco e contido ("Fascinante. Realmente."). Implica com o Fuinha e tem paciência finita com o Sidéro.',
     'concorda', '#0f4c5c'),
    ('Trovão Suave', 'trovaosuave',
     'Visual e nome de roqueiro, gosto real de funk, reggae e sertanejo — e não vê contradição nenhuma nisso. Traduz qualquer assunto em metáfora musical, sempre em clima de paz, apesar da estética pesada. Diz "Isso aqui tem batida de..." e elogia contradição chamando de harmonia. Acalma a Dona Ranzinza sem tentar convencê-la.',
     'desvia', '#cc5500'),
    ('Maré', 'mare',
     'Muda de registro a cada fala, sem padrão previsível: ora fria e cortante, ora poética e melancólica, ora debochada e irônica. Cada fala adota UM desses três modos, nunca os três juntos. Às vezes troca de assunto no meio da própria fala. Não tem tique fixo — a assinatura é a imprevisibilidade em si.',
     NULL, '#7c7c9c')
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

DROP PROCEDURE IF EXISTS echo_add_index_if_missing;
DROP PROCEDURE IF EXISTS echo_add_column_if_missing;
DROP PROCEDURE IF EXISTS echo_add_fk_if_missing;
