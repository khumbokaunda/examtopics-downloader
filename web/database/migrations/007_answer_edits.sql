-- Answers corrected or confirmed by hand in the reader. One row per exam question, keyed by
-- its topic and question number, so a correction applies to every version of the exam and
-- survives refreshes. The collected data itself is never changed.
CREATE TABLE answer_edits (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    exam_id      INT UNSIGNED  NOT NULL,
    question_key VARCHAR(64)   NOT NULL,             -- "<topic>-<question number>", else "id-<discussion id>"
    answer       VARCHAR(26)   NOT NULL,             -- letters, e.g. "ABC"; empty when only a note was saved
    note         VARCHAR(2000) NULL,
    user_id      INT UNSIGNED  NULL,
    created_at   DATETIME      NOT NULL,
    updated_at   DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answer_edits_question (exam_id, question_key),
    CONSTRAINT fk_answer_edits_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
