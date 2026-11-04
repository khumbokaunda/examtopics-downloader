-- Every change to a question's corrections: its answer correction or note (kind "answer"), or
-- its wording, choices and pictures (kind "question"), with what it was before and after and who
-- changed it. NULL before means there was none; NULL after means it was removed. Any earlier
-- state can be put back from the question's history.
CREATE TABLE IF NOT EXISTS correction_history (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    kind         VARCHAR(16)  NOT NULL,
    before_state MEDIUMTEXT   NULL,
    after_state  MEDIUMTEXT   NULL,
    user_id      INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_correction_history_question (exam_id, question_key, id),
    CONSTRAINT fk_correction_history_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_correction_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
