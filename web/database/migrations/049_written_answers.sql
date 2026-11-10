-- Written-answer (essay) questions. An answer was a few choice letters; it can now be text.
-- A sitting with a written answer is handed in "awaiting marking": its score, percent and pass
-- are filled in when a marker has marked every written answer, and its results cannot be
-- published before then.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_responses' AND COLUMN_NAME = 'answer' AND DATA_TYPE = 'varchar');
SET @s := IF(@c = 1, 'ALTER TABLE assessment_responses MODIFY answer TEXT NOT NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_response_history' AND COLUMN_NAME = 'answer' AND DATA_TYPE = 'varchar');
SET @s := IF(@c = 1, 'ALTER TABLE assessment_response_history MODIFY answer TEXT NOT NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_sessions' AND COLUMN_NAME = 'marking');
SET @s := IF(@c = 0, 'ALTER TABLE assessment_sessions ADD COLUMN marking VARCHAR(16) NULL AFTER passed', 'DO 0'); -- NULL: nothing to mark; awaiting; marked
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_session_questions' AND COLUMN_NAME = 'marked_at');
SET @s := IF(@c = 0, 'ALTER TABLE assessment_session_questions ADD COLUMN marked_at DATETIME NULL, ADD COLUMN marked_by INT UNSIGNED NULL, ADD COLUMN marker_feedback TEXT NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
