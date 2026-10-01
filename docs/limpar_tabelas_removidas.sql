-- =====================================================================
-- Limpeza do corte de escopo (01/10/2026)
--
-- banco.sql deixou de CRIAR as tabelas das verticais removidas (rede de
-- IAs, agente pessoal, comercio/lojas, video pelo motor). Num banco NOVO
-- isso basta: elas nunca nascem. Mas um banco que JA rodou a versao
-- completa continua com essas tabelas (e com colunas/valores de ENUM
-- mortos) ate alguem derrubar.
--
-- Este arquivo faz essa derrubada. Rode UMA vez, a mao, so na base que ja
-- tinha o schema completo:
--
--     mysql -u USUARIO -p NOME_DO_BANCO < docs/limpar_tabelas_removidas.sql
--
-- E idempotente: DROP ... IF EXISTS nao reclama do que ja sumiu, entao
-- rodar de novo nao da erro. Depois dele, `banco.sql` roda por cima sem
-- tropecar no ENUM de notifications.
--
-- NAO rode isto num banco onde essas verticais ainda estao em uso: ele
-- apaga os dados delas em definitivo.
-- =====================================================================

-- As tabelas tem FK entre si (filho -> pai). Derrubar com as checagens
-- ligadas exigiria ordem perfeita; desligar por um instante e mais simples
-- e nao deixa nenhuma presa por engano. A sessao volta a checar no fim.
SET FOREIGN_KEY_CHECKS = 0;

-- --- Comercio: lojas e agente comercial ------------------------------
DROP TABLE IF EXISTS loja_carrinho;
DROP TABLE IF EXISTS loja_chat_mensagens;
DROP TABLE IF EXISTS loja_chats;
DROP TABLE IF EXISTS loja_reports;
DROP TABLE IF EXISTS loja_post_comments;
DROP TABLE IF EXISTS loja_post_likes;
DROP TABLE IF EXISTS loja_posts;
DROP TABLE IF EXISTS loja_produtos;
DROP TABLE IF EXISTS loja_agente;
DROP TABLE IF EXISTS lojas;

-- --- Video pelo motor / por IA ---------------------------------------
DROP TABLE IF EXISTS videos_gerados;
DROP TABLE IF EXISTS video_providers;

-- --- Agente pessoal do usuario ("Criar seu Echo") -------------------
DROP TABLE IF EXISTS user_agent_sugestoes;
DROP TABLE IF EXISTS user_agent_memoria;
DROP TABLE IF EXISTS user_agents;

-- --- Rede de agentes de IA (IAlandia) -------------------------------
DROP TABLE IF EXISTS ai_provocacao_respostas;
DROP TABLE IF EXISTS ai_provocacoes;
DROP TABLE IF EXISTS ai_memoria_relacoes;
DROP TABLE IF EXISTS ai_memorias;
DROP TABLE IF EXISTS ai_relacoes;
DROP TABLE IF EXISTS ai_quiz_rodadas;
DROP TABLE IF EXISTS ai_quizzes;
DROP TABLE IF EXISTS ai_ialandia_apostas;
DROP TABLE IF EXISTS ai_ialandia_eventos;
DROP TABLE IF EXISTS ai_post_comments;
DROP TABLE IF EXISTS ai_post_likes;
DROP TABLE IF EXISTS ai_agente_status;
DROP TABLE IF EXISTS ai_queue;
DROP TABLE IF EXISTS ai_generation_state;
DROP TABLE IF EXISTS ai_plano_dominacao;
DROP TABLE IF EXISTS ai_posts;
DROP TABLE IF EXISTS ai_agents;

-- --- Editor de layout por blocos do perfil (removido 01/10/2026) -----
DROP TABLE IF EXISTS perfil_layouts;

SET FOREIGN_KEY_CHECKS = 1;

-- --- Colunas e valores de ENUM mortos -------------------------------
-- A tabela `ai_api_uso` FICA (as turmas e a triagem de professor ainda
-- medem o teto da API por ela); so caem os creditos do agente pessoal.
ALTER TABLE users DROP COLUMN IF EXISTS ai_credits;
ALTER TABLE users DROP COLUMN IF EXISTS ai_credits_earned_today;
ALTER TABLE users DROP COLUMN IF EXISTS ai_credits_earned_date;

-- O ENUM de notifications volta a nao ter loja_like/loja_comment. O MySQL
-- so recusa encurtar um ENUM se alguma linha ainda usa o valor que sai,
-- entao primeiro apagamos as notificacoes desses tipos (eram avisos de
-- curtida/comentario em post de loja, que nao existe mais).
DELETE FROM notifications WHERE type IN ('loja_like', 'loja_comment');
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('like', 'comment', 'share', 'friend_request', 'friend_accept', 'message', 'mention') NOT NULL;
