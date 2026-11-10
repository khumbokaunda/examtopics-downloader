-- Whether an account has the library (reading, practising and requesting collected exams).
-- Accounts there were before keep it; accounts that arrive through an institution start without.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'library_access');
SET @s := IF(@c = 0, 'ALTER TABLE users ADD COLUMN library_access TINYINT(1) NOT NULL DEFAULT 1 AFTER permissions', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
