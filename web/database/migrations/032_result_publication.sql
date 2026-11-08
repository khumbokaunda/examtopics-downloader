-- Publishing an assessment's results to its candidates. Until results_published_at is set,
-- candidates know only that they handed in; afterwards they see what the assessment's
-- review_policy allows (nothing, their score, or their answers too). Unpublishing hides them again.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'results_published_at');
SET @sql := IF(@col = 0, 'ALTER TABLE assessments ADD COLUMN results_published_at DATETIME NULL AFTER published_at, ADD COLUMN results_published_by INT UNSIGNED NULL AFTER results_published_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
