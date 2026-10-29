-- Local additive migration; deploy only after separate production approval.
-- Normal nullable index: application locking creates one publication card.
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS online_quiz_id BIGINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS chat_online_quiz ON chat_messages(online_quiz_id);
-- MariaDB supports ADD CONSTRAINT IF NOT EXISTS via the named FOREIGN KEY form.
ALTER TABLE chat_messages ADD FOREIGN KEY IF NOT EXISTS chat_online_quiz_fk (online_quiz_id) REFERENCES online_quizzes(id) ON DELETE SET NULL;
