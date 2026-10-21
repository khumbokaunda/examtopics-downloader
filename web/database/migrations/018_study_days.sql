-- Study days: how much each person did on each day, in their own timezone. The dashboard's
-- week, its 14-day chart and the streak are read from here. A day counts towards the streak
-- when the person checked an answer or opened a page of an exam.
CREATE TABLE IF NOT EXISTS study_days (
    user_id  INT UNSIGNED NOT NULL,
    day      DATE         NOT NULL,
    answered INT UNSIGNED NOT NULL DEFAULT 0,   -- answers checked in practice mode
    correct  INT UNSIGNED NOT NULL DEFAULT 0,   -- of those, right
    pages    INT UNSIGNED NOT NULL DEFAULT 0,   -- reader pages opened
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_study_days_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Start from what is already known: each answer on the day it was last checked, and each
-- exam's last opened page (UTC days; later days use the person's timezone). INSERT IGNORE
-- keeps this safe to run again.
INSERT IGNORE INTO study_days (user_id, day, answered, correct, pages)
    SELECT user_id, DATE(last_at), COUNT(*), SUM(correct), 0 FROM study_answers GROUP BY user_id, DATE(last_at);
INSERT IGNORE INTO study_days (user_id, day, answered, correct, pages)
    SELECT user_id, DATE(updated_at), 0, 0, COUNT(*) FROM study_places GROUP BY user_id, DATE(updated_at);
