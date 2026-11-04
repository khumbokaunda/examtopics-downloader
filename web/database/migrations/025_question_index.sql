-- Search across the whole library: each exam's questions as they are shown (corrected wording
-- and choices), one row per question of the exam's best version. Written by the background
-- check (bin/integrity.php) whenever it works an exam out, so it is rebuilt from the data and
-- never backed up.
CREATE TABLE IF NOT EXISTS question_index (
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    number       INT UNSIGNED NOT NULL,
    body         MEDIUMTEXT   NOT NULL,      -- the question, then "A. ..." for each choice
    PRIMARY KEY (exam_id, question_key),
    CONSTRAINT fk_question_index_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every exam is worked out again once, so the index is filled for the exams already checked.
DELETE FROM exam_checks;
