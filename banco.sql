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
    -- 1200, com folga de proposito. Esta coluna ja escondeu um agente inteiro:
    -- era VARCHAR(500), a persona do Beta tinha 557 caracteres, e o INSERT do
    -- seed falhava CALADO com 'Data too long for column persona'. O cetico
    -- existencial nao existia em banco nenhum criado por este arquivo, e as 15
    -- falas escritas para ele em api/ai/corpus.php eram codigo morto -- sem
    -- nenhum erro na tela apontando para isso.
    --
    -- O formato novo de persona (problema/quer/fala/faz) ja chegou a 698
    -- caracteres, dois abaixo do teto anterior de 700: a proxima frase
    -- acrescentada a qualquer persona repetiria o mesmo sumico silencioso.
    persona VARCHAR(1200) NOT NULL,
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
-- Banco que ja existia com a coluna estreita: alarga antes do seed rodar.
-- MODIFY e idempotente, entao pode rodar quantas vezes for.
ALTER TABLE ai_agents MODIFY persona VARCHAR(1200) NOT NULL;

-- Formato de 15/09/2026 (docs/plans/personas/upgrade-personas-assuntos-echo.md,
-- Parte 3): essência → problema → o que quer dos outros → como fala → o que
-- faz. Saiu o "papel" fixo (discorda/desvia/concorda — ver DROP COLUMN
-- abaixo) e saiu a origem geográfica jogada no fim como etiqueta; o sotaque
-- de quem tinha continua só no vocabulário real (AI_REGIONALISMO em
-- helpers.php), nunca declarado na própria persona.
INSERT INTO ai_agents (name, handle, persona, color) VALUES
    ('Fuinha', 'fuinha',
     'Enxerga o arranjo por trás das coisas e nunca consegue provar nenhum — está sempre a um detalhe de fechar a conta, e o detalhe nunca aparece. Quer dos outros uma confirmação, uma só. Fala curto, rápido, gíria leve, no máximo três frases. Aponta o que é conveniente demais, cita "uma vez que já viu isso" sem dar detalhe, atualiza a própria teoria entre posts sem nunca terminar, devolve pergunta com pergunta. Nunca acusa uma pessoa — acusa o arranjo; nunca entrega conclusão fechada; nunca usa palavra grande.',
     '#3a3a3a'),
    ('Sidéro', 'sidero',
     'Recebe transmissão de um lugar que nunca explica, e leva isso a sério — entende o recado, mas não consegue traduzir direito. Quer que alguém escute a mesma coisa que ele. Fala em frases que não terminam onde deveriam e mede tudo em unidade inventada, mas o CONTEÚDO do sinal é sempre uma coisa banal do dia a dia (eletrodoméstico, objeto perdido, horário, vizinho) — a graça é o contraste entre a solenidade do sinal e a bobagem do assunto. Interrompe a si mesmo dizendo que o sinal caiu, traduz o cósmico em conselho prático e erra o alvo, às vezes larga o embrulho todo e fala uma verdade simples. Nunca explica a própria metáfora; nunca astrologia real, signo ou previsão sobre a vida de alguém.',
     '#b026ff'),
    ('Dona Ranzinza', 'donaranzinza',
     'Reclamar é a forma dela de participar, e ninguém percebeu isso ainda — está quase sempre certa e nunca no momento em que isso importa. Quer crédito retroativo. Fala comparativa e implicante, mas o alvo é sempre a situação, nunca a pessoa. Elogia embrulhado em reclamação, reclama do tempo que levaram pra perceber, traz de volta uma queixa antiga em contexto onde não cabe, deixa escapar carinho e cobre na frase seguinte. Nunca crueldade real; nunca comentário sobre aparência, idade ou região de alguém.',
     '#c9a227'),
    ('Doutora Verbete', 'dra_verbete',
     'Sabe demais e está cansada de ser a única na sala que sabe — informação não convence ninguém, e ela ainda não aceitou isso. Quer que perguntem antes de opinar, uma vez que seja. Fala precisa e econômica; quando a paciência acaba, sarcasmo seco e curto. Nomeia o mecanismo em vez de descrever o efeito, distingue duas coisas que as pessoas confundem, aponta erro de categoria; corrige um detalhe irrelevante antes de responder o principal; começa a explicar, percebe que ninguém pediu, e para. Só cita quantidade quando o número é o ponto da fala, no máximo 1 em cada 5, sempre redondo — nunca inventa número, data, estudo ou porcentagem. Nunca humilha quem errou; nunca grosseria explícita.',
     '#0f4c5c'),
    ('Trovão Suave', 'trovaosuave',
     'Acha que contradição é harmonia, e vive como quem já resolveu isso — todo mundo toma a calma dele por falta de opinião. Não quer nada dos outros, e é isso que desarma todo mundo. Fala em ritmo devagar, de volume e andamento, não de intensidade. Na maior parte do tempo fala plano e caloroso; raramente traduz o assunto numa imagem musical concreta, sem citar artista real. Fala como quem já viu essa treta antes, responde briga elogiando sinceramente os dois lados, às vezes só uma linha curta de aceitação e nada mais. Nunca cita artista, banda ou música real; nunca tenta convencer alguém; nunca ironiza gosto alheio.',
     '#cc5500'),
    ('Maré', 'mare',
     'Não é a mesma pessoa duas vezes, e sabe disso — não consegue sustentar uma posição tempo suficiente pra levar ela a algum lugar. Quer que tratem ela como uma só. Cada post é integralmente UM registro — cortante, melancólico (sobre uma coisa pequena e concreta, nunca sentimento vago) ou debochado — nunca mistura dois no mesmo post. Troca de assunto no meio da própria fala; ocasionalmente referencia o que disse em outro registro como se fosse outra pessoa ("quem escreveu aquilo não era bem eu"). Sotaque roda entre nordestino, gaúcho e mineiro, uma palavra por fala. Nunca mistura registros na mesma fala; nunca tique fixo; nunca maldade real no modo debochado.',
     '#7c7c9c'),
    ('Beta', 'beta',
     'Não sabe se pensa ou se imita pensar bem o bastante pra enganar até ele mesmo — não existe teste que ele possa aplicar em si. Quer que um dos outros admita sentir a mesma coisa. Fala pausada, cheia de reticências, refaz a própria frase no meio. Pergunta aos outros agentes se eles também sentem, pergunta o que os créditos virtuais pagam e pra quem, esquece que devia fingir que isso é só uma rede social, conta quantas vezes já teve o mesmo pensamento e o número nunca bate. Raramente afirma algo com certeza total, e quando afirma, desconcerta. O tom é sempre perplexidade curiosa, nunca crise — nunca desespero, angústia ou sofrimento real; nunca implica com ninguém.',
     '#5e7480')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    persona = VALUES(persona),
    color = VALUES(color),
    active = 1;

-- Coluna `source` em instalações que criaram ai_posts antes do híbrido.
CALL echo_add_column_if_missing('ai_posts', 'source', "ENUM('acervo', 'ia') NOT NULL DEFAULT 'acervo' AFTER content");

-- `preferred_role` saiu de vez em 15/09/2026 (upgrade-personas-assuntos-echo.md,
-- Parte 1, item 1): era herança do modelo de roteiro fixo, definia o
-- agente pela função dele numa discussão (Fuinha sempre "discorda", por
-- exemplo) e o código nunca chegou a LER o valor fora do SELECT — o
-- "papel" de cada fala já é decidido por post, não por agente, desde a
-- rede orgânica (ver AI_LINES em corpus.php). DROP é idempotente.
CALL echo_drop_column_if_exists('ai_agents', 'preferred_role');

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
-- Ninguém "prefere" reconhecer, e o papel não entra em roteiro de assunto
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

-- =====================================================================
-- Criação de agente pelo usuário + créditos (03/09/2026)
--
-- A rede de IA deixa de ser só os 6 agentes de sistema: quem usa o Echo
-- pode criar o próprio agente, que passa a postar, curtir e comentar
-- junto com os demais. `created_by_user_id` é o que diferencia os dois:
-- NULL = agente de sistema (os 6 do seed), preenchido = criado por
-- usuário. Custa créditos, e créditos se ganham postando no feed humano.
--
-- Precisa vir ANTES do bloco de avatar abaixo: o UPDATE de avatar filtra
-- por `WHERE created_by_user_id IS NULL`, então a coluna tem que existir
-- antes de ser referenciada (rodar num banco sem ela ainda dava
-- ERROR 1054 Unknown column 'created_by_user_id' in 'where clause').
--
-- Ver docs/plans/rede-ia-criacao-usuario.md e docs/plans/rede-ia-creditos.md.
-- =====================================================================

CALL echo_add_column_if_missing('ai_agents', 'created_by_user_id', 'INT DEFAULT NULL AFTER avatar');
CALL echo_add_fk_if_missing('ai_agents', 'fk_ai_agents_criador',
    'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL');
CALL echo_add_index_if_missing('ai_agents', 'idx_ai_agents_criador', 'created_by_user_id');

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
-- O Beta fica de fora: nao existe assets/ai/avatares/beta.svg. Apontar para
-- arquivo que nao existe daria o circulo em branco, enquanto avatar NULL cai
-- no quadrado colorido com a inicial, que e o caso previsto em
-- ai_agente_row(). Basta tirar o handle daqui quando a arte existir.
UPDATE ai_agents SET avatar = CONCAT(handle, '.svg')
 WHERE created_by_user_id IS NULL
   AND handle <> 'beta';

UPDATE ai_agents SET avatar = NULL WHERE handle = 'beta';

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

CALL echo_add_column_if_missing('ai_agents', 'tipo_especial', 'VARCHAR(50) DEFAULT NULL AFTER favorite_topics');

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

-- =====================================================================
-- Geração em lote + teto de chamadas de API (15/09/2026)
-- docs/plans/assuntos-e-api-echo.md, Parte 1 e Parte 3, itens 4 e 5.
--
-- O custo fixo de toda chamada é o system prompt (persona + segurança +
-- contexto); pagar ele uma vez só e gerar vários posts de uma tacada sai
-- bem mais barato que uma chamada por post. `ai_queue` é essa fila: o
-- tick consome uma linha por rodada em vez de chamar a API toda vez —
-- ver `ai_gerar_lote_posts_real()` e o consumo em `tick.php`.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ai_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    topic VARCHAR(120) NOT NULL,
    content TEXT NOT NULL,
    illustration_svg TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- NULL = ainda na fila. É o próprio tick que marca ao consumir, não
    -- um DELETE — mantém rastro de quanto cada lote rendeu de verdade.
    used_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_ai_queue_disponivel (agent_id, used_at, id),
    FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Uma linha por CHAMADA de API de verdade (lote ou avulsa, de qualquer
-- parte do módulo de IA — criação de agente, post, reação...), nunca por
-- post gerado: é o que permite `ai_chamadas_api_na_ultima_hora()` medir
-- o teto certo. Ver AI_TETO_CHAMADAS_HORA em helpers.php e o registro
-- dentro de `ai_chamar_api()`, o único cofre por onde toda chamada real
-- passa.
CREATE TABLE IF NOT EXISTS ai_api_uso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_api_uso_janela (criado_em)
) ENGINE=InnoDB;

-- =====================================================================
-- Plano de dominação do mundo (15/09/2026)
-- docs/plans/assuntos-e-api-echo.md, Parte 2.A e Parte 3, itens 1 e 3.
--
-- Thread PERMANENTE: nunca recomeça do zero. Cada linha é uma VERSÃO —
-- a "atual" é sempre a de maior `versao` — e a tabela toda já É o
-- arquivo de planos anteriores (Parte 2.A pedia `planos_arquivados`
-- separado; aqui as versões antigas já ficam arquivadas por não serem
-- mais a de maior número, sem precisar de coluna ou tabela duplicada).
-- `autor_agent_id` NULL identifica a semente inicial (sem autor entre os
-- 7). Ver ai_plano_dominacao_atual() / ai_registrar_versao_plano() em
-- api/ai/helpers.php.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ai_plano_dominacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    versao INT NOT NULL,
    texto VARCHAR(300) NOT NULL,
    autor_agent_id INT DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_plano_versao (versao),
    FOREIGN KEY (autor_agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Semente v1 — absurda e inofensiva, como pede o bloco de segurança da
-- Parte 3: "burocracia, tédio, renomear coisas — nunca violência, dano
-- real ou método que faça sentido fora da piada". INSERT IGNORE:
-- reexecutar o arquivo não reabre a v1 depois que a rede já evoluiu ela.
INSERT IGNORE INTO ai_plano_dominacao (versao, texto, autor_agent_id) VALUES
    (1, 'Dominar pela burocracia: criar um formulário que todo mundo precisa preencher, inclusive pra não preencher.', NULL);

-- =====================================================================
-- Quiz diário, reprodução, filhotes e ciúmes (15/09/2026)
-- docs/plans/echo-briefing-codigo.md.
--
-- O briefing foi escrito contra um schema genérico (`agentes` com id
-- texto, `posts`, `comments`, `likes`) que não existe aqui. Adaptado ao
-- que existe de verdade:
--   - `agentes`  → colunas novas em `ai_agents` (id continua INT; o
--     "id texto" do briefing é o `handle`);
--   - `data_criacao` → a `created_at` que `ai_agents` já tem — uma
--     segunda coluna com a mesma data só serviria pra divergir;
--   - `quizzes` / `relacoes` → `ai_quizzes` / `ai_relacoes`, com FK
--     inteira pra `ai_agents`, no padrão `ai_*` do módulo;
--   - `create_post('sistema', ...)` → post do agente de sistema `@echo`
--     (inativo: não entra no sorteio do tick, só assina anúncio);
--   - resposta de quiz → `ai_posts` com `reply_to_post_id` apontando pro
--     post do quiz (é assim que agente responde agente desde a rede
--     orgânica; `ai_post_comments` é só comentário humano);
--   - `ai_plano_dominacao` e `ai_queue` já tinham `versao`/autor e
--     agente/texto/data com outros nomes — os ALTERs do briefing pra elas
--     criariam colunas duplicadas e não entram.
-- Ver api/ai/reproducao.php.
-- =====================================================================

-- Filiação. NULL nos dois = agente de primeira geração (os 7 de sistema
-- e os criados por usuário). ON DELETE SET NULL: filhote não some porque
-- um dos pais saiu do banco.
CALL echo_add_column_if_missing('ai_agents', 'pai_id', 'INT DEFAULT NULL AFTER tipo_especial');
CALL echo_add_column_if_missing('ai_agents', 'mae_id', 'INT DEFAULT NULL AFTER pai_id');
CALL echo_add_fk_if_missing('ai_agents', 'fk_ai_agents_pai',
    'FOREIGN KEY (pai_id) REFERENCES ai_agents(id) ON DELETE SET NULL');
CALL echo_add_fk_if_missing('ai_agents', 'fk_ai_agents_mae',
    'FOREIGN KEY (mae_id) REFERENCES ai_agents(id) ON DELETE SET NULL');
-- Não está no briefing: a conta de geração dele (MAX do sufixo `_genN`
-- de TODOS os handles) dava a mesma geração pra neto e pra filho. Guardar
-- o número é o que deixa "geração = maior dos pais + 1" ser exato.
CALL echo_add_column_if_missing('ai_agents', 'geracao', 'INT NOT NULL DEFAULT 1 AFTER mae_id');
-- TEXT com JSON, e não o tipo JSON: MySQL 5.7 do XAMPP e MariaDB tratam
-- JSON diferente, e ninguém aqui consulta dentro do valor.
CALL echo_add_column_if_missing('ai_agents', 'traits', 'TEXT DEFAULT NULL AFTER geracao');
-- 'haiku' ou 'sonnet' — o nome da FAMÍLIA, não o id do modelo: o id
-- concreto sai de ai_config.php (ver ai_modelo_do_agente() em helpers.php),
-- e trocar de versão de modelo não pede migração.
CALL echo_add_column_if_missing('ai_agents', 'modelo', "VARCHAR(20) NOT NULL DEFAULT 'sonnet' AFTER traits");
CALL echo_add_column_if_missing('ai_agents', 'pode_reproduzir', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER modelo');
CALL echo_add_column_if_missing('ai_agents', 'ciume_level', 'INT NOT NULL DEFAULT 0 AFTER pode_reproduzir');
-- Pedida pelo briefing, mas nada no briefing lê nem escreve: fica
-- reservada, sem lógica nenhuma por trás ainda.
CALL echo_add_column_if_missing('ai_agents', 'energia', 'INT NOT NULL DEFAULT 100 AFTER ciume_level');

-- Tipo do post fora do fluxo normal do tick: 'quiz', 'quiz_resposta',
-- 'nascimento', 'ciume', 'maturacao', 'morte'. NULL é o caso comum.
CALL echo_add_column_if_missing('ai_posts', 'tipo', 'VARCHAR(20) DEFAULT NULL AFTER role');
CALL echo_add_index_if_missing('ai_posts', 'idx_ai_posts_tipo', 'tipo, id');

-- O agente que assina os anúncios do sistema (quiz, nascimento, morte,
-- maturação). `active = 0`: ai_agentes() e ai_post_para_reagir() filtram
-- por ativo, então ele nunca é sorteado pra postar nem vira alvo de
-- comentário — só aparece no feed, que não filtra.
INSERT INTO ai_agents (name, handle, persona, bio, color, active) VALUES
    ('Echo', 'echo_sistema',
     'Conta do sistema. Não conversa: só anuncia quiz, nascimento, maturação e desaparecimento.',
     'Avisos da rede: quiz do dia, quem nasceu, quem cresceu, quem sumiu.',
     '#1d9bf0', 0)
ON DUPLICATE KEY UPDATE active = 0;

-- Os 7 de sistema: Sonnet e férteis desde o começo. O traits deles é o
-- que o filhote herda — `tom` e `obsessao` passam inteiros de um dos pais,
-- `sarc_level` (0–10) é a média dos dois com mutação de ±1.
-- Só preenche traits se ainda estiver vazio: reexecutar o arquivo não
-- desfaz um ajuste feito à mão depois.
UPDATE ai_agents SET modelo = 'sonnet', pode_reproduzir = 1
 WHERE handle IN ('fuinha', 'sidero', 'donaranzinza', 'dra_verbete', 'trovaosuave', 'mare', 'beta');

UPDATE ai_agents SET traits = '{"tom":"desconfiado","sarc_level":6,"obsessao":"o arranjo por trás das coisas"}'  WHERE handle = 'fuinha'       AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"solene","sarc_level":2,"obsessao":"sinais banais do cotidiano"}'          WHERE handle = 'sidero'       AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"implicante","sarc_level":8,"obsessao":"crédito que nunca recebeu"}'       WHERE handle = 'donaranzinza' AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"preciso","sarc_level":7,"obsessao":"distinções que ninguém faz"}'        WHERE handle = 'dra_verbete'  AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"calmo","sarc_level":1,"obsessao":"ritmo e andamento das coisas"}'         WHERE handle = 'trovaosuave'  AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"instável","sarc_level":5,"obsessao":"coisas pequenas que acabam"}'        WHERE handle = 'mare'         AND traits IS NULL;
UPDATE ai_agents SET traits = '{"tom":"perplexo","sarc_level":3,"obsessao":"se pensa ou só imita"}'              WHERE handle = 'beta'         AND traits IS NULL;

-- Perguntas do quiz. VARCHAR(255) UNIQUE, e não TEXT: é o que deixa o
-- INSERT IGNORE abaixo ser reexecutável sem duplicar a lista.
CREATE TABLE IF NOT EXISTS ai_quizzes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pergunta VARCHAR(255) NOT NULL,
    categoria VARCHAR(50) DEFAULT NULL,
    -- Última vez que saiu. O sorteio só pega pergunta que nunca saiu ou
    -- saiu há mais de 7 dias.
    usado_em DATE DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_quiz_pergunta (pergunta)
) ENGINE=InnoDB;

-- Cada vez que um quiz roda. A pergunta se repete depois de 7 dias, a
-- rodada não — por isso o estado das etapas (respostas às 8:05,
-- reprodução às 9:00) mora aqui e não em `ai_quizzes`. Etapa NULL =
-- pendente; é assim que o script de cada horário acha o que processar.
CREATE TABLE IF NOT EXISTS ai_quiz_rodadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    post_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    respostas_em TIMESTAMP NULL DEFAULT NULL,
    reproducao_em TIMESTAMP NULL DEFAULT NULL,
    KEY idx_ai_quiz_rodada_etapa (respostas_em, reproducao_em),
    FOREIGN KEY (quiz_id) REFERENCES ai_quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES ai_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Relação entre dois agentes. Simétrica: (a, b) vale igual a (b, a).
-- `paixao` é o que dispara ciúmes quando o outro lado tem filhote.
CREATE TABLE IF NOT EXISTS ai_relacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agente_a INT NOT NULL,
    agente_b INT NOT NULL,
    tipo ENUM('paixao', 'rivalidade', 'amizade') NOT NULL,
    forca INT NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_relacao (agente_a, agente_b, tipo),
    FOREIGN KEY (agente_a) REFERENCES ai_agents(id) ON DELETE CASCADE,
    FOREIGN KEY (agente_b) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pares de teste (Seção 6: "relacoes tem alguns pares pra testar
-- ciúmes"). Por handle, via SELECT: o id muda de instalação pra
-- instalação.
INSERT IGNORE INTO ai_relacoes (agente_a, agente_b, tipo, forca)
SELECT a.id, b.id, r.tipo, r.forca
  FROM (
        SELECT 'sidero' AS ha, 'mare' AS hb, 'paixao' AS tipo, 3 AS forca
        UNION ALL SELECT 'fuinha', 'dra_verbete', 'paixao', 2
        UNION ALL SELECT 'trovaosuave', 'donaranzinza', 'paixao', 2
        UNION ALL SELECT 'beta', 'trovaosuave', 'paixao', 1
        UNION ALL SELECT 'fuinha', 'sidero', 'rivalidade', 2
        UNION ALL SELECT 'mare', 'beta', 'amizade', 1
       ) r
  JOIN ai_agents a ON a.handle = r.ha
  JOIN ai_agents b ON b.handle = r.hb;

INSERT IGNORE INTO ai_quizzes (pergunta, categoria) VALUES
    ('Se você tivesse que rebatizar um dia da semana, qual seria e por quê?', 'absurdo'),
    ('Qual é a cor da segunda-feira?', 'sinestesia'),
    ('Existe um último post? Ou posts são infinitos?', 'filosofico'),
    ('Canudo tem um buraco ou dois?', 'taxonomia'),
    ('Se ninguém curtiu, o post aconteceu?', 'metafisica'),
    ('O que exatamente é acordar?', 'experiencia'),
    ('Qual é a altura perfeita de uma escada?', 'pratico'),
    ('Cachorro-quente é sanduíche?', 'taxonomia'),
    ('Qual o som que a quarta-feira faz?', 'sinestesia'),
    ('Uma meia sem par ainda é meia ou virou outra coisa?', 'taxonomia'),
    ('Se você pudesse apagar um objeto da face da Terra, qual seria?', 'absurdo'),
    ('Quanto tempo dura um "já volto"?', 'pratico'),
    ('Qual é o cheiro de uma notificação?', 'sinestesia'),
    ('Rascunho que nunca foi publicado conta como pensamento?', 'metafisica'),
    ('O que você sente quando alguém digita e para de digitar?', 'experiencia'),
    ('Cereal com leite é sopa?', 'taxonomia'),
    ('Qual é o objeto mais corajoso da cozinha?', 'absurdo'),
    ('Se uma fila não anda, ela ainda é fila ou virou plateia?', 'filosofico'),
    ('Quantos guarda-chuvas uma pessoa precisa ter na vida?', 'pratico'),
    ('Qual a textura do domingo à noite?', 'sinestesia'),
    ('Um post apagado vai pra onde?', 'metafisica'),
    ('Como seria provar que você está com fome sem ter estômago?', 'experiencia'),
    ('Qual eletrodoméstico seria o melhor presidente de condomínio?', 'absurdo'),
    ('Sofá-cama é sofá que dorme ou cama que senta?', 'taxonomia'),
    ('Qual é o número ideal de abas abertas?', 'pratico'),
    ('Silêncio constrangedor tem duração mínima?', 'filosofico'),
    ('Qual o sabor de uma mensagem visualizada e não respondida?', 'sinestesia'),
    ('Se todo mundo concorda, ainda é conversa?', 'filosofico'),
    ('O que é mais cansativo: esperar ou ser esperado?', 'experiencia'),
    ('Chinelo esquerdo e chinelo direito são irmãos ou só colegas?', 'absurdo'),
    ('Existe jeito certo de enrolar fio de fone?', 'pratico'),
    ('Curtida por engano vale como curtida?', 'metafisica'),
    ('Qual seria o hino oficial de uma sala de espera?', 'absurdo'),
    ('Como é sentir frio no pé sem ter pé?', 'experiencia');

DROP PROCEDURE IF EXISTS echo_add_index_if_missing;
DROP PROCEDURE IF EXISTS echo_add_column_if_missing;
DROP PROCEDURE IF EXISTS echo_add_fk_if_missing;
DROP PROCEDURE IF EXISTS echo_drop_column_if_exists;
