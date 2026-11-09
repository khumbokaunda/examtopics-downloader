-- An exam's name set by an administrator: kept when the exam list or a collection brings
-- another name.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'name_locked');
SET @s := IF(@c = 0, 'ALTER TABLE exams ADD COLUMN name_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER name', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
