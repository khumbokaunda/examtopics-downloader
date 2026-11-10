-- Where a mock exam's questions were chosen from (all, new, mistakes, due, bookmarked) and how
-- many that offered, so readiness counts only attempts drawn at random from a large enough
-- pool. Attempts made before this have neither (NULL): they do not count.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mock_exams' AND COLUMN_NAME = 'source');
SET @s := IF(@c = 0, 'ALTER TABLE mock_exams ADD COLUMN source VARCHAR(16) NULL AFTER version_id, ADD COLUMN pool_size INT UNSIGNED NULL AFTER source', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
