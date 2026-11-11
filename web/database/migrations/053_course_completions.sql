-- A module taught through a course with a pass mark is completed by the final course grade, not
-- by passing one assessment. The completion keeps the course it came from.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_completions' AND COLUMN_NAME = 'course_id');
SET @s := IF(@c = 0, 'ALTER TABLE module_completions ADD COLUMN course_id INT UNSIGNED NULL AFTER session_id, ADD KEY ix_module_completions_course (course_id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_completions' AND CONSTRAINT_NAME = 'fk_module_completions_course');
SET @s := IF(@c = 0, 'ALTER TABLE module_completions ADD CONSTRAINT fk_module_completions_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
