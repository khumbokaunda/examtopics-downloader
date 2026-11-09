-- An exam's edition (for example V1.0), set by an administrator when the one worked out from
-- its name or code is wrong. Shown on PDF covers.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'edition_label');
SET @s := IF(@c = 0, 'ALTER TABLE exams ADD COLUMN edition_label VARCHAR(40) NULL AFTER name_locked', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
