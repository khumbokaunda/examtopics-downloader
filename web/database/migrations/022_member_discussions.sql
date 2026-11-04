-- Members' own discussion of a question, beside the one collected from ExamTopics. A comment
-- may suggest an answer: that is the person's vote, and their latest suggestion is the one
-- counted. Keyed like answer_edits, so it applies to every version of the exam.
CREATE TABLE IF NOT EXISTS question_comments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    user_id      INT UNSIGNED NULL,          -- NULL once the account is deleted; its vote no longer counts
    body         TEXT         NOT NULL,      -- may be empty when the comment only suggests an answer
    answer       VARCHAR(16)  NULL,          -- suggested answer letters, sorted ("AC")
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_question_comments_question (exam_id, question_key),
    CONSTRAINT fk_question_comments_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Problems with a question reported by members (a wrong answer, wording, a broken picture), for
-- the people who correct questions. Open until one of them marks it dealt with.
CREATE TABLE IF NOT EXISTS question_reports (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    user_id      INT UNSIGNED NULL,
    kind         VARCHAR(16)  NOT NULL,      -- answer | wording | picture | other
    body         TEXT         NOT NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'open',   -- open | resolved
    resolved_by  INT UNSIGNED NULL,
    resolved_at  DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_question_reports_open (status, exam_id, question_key),
    CONSTRAINT fk_question_reports_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_question_reports_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
