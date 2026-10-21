-- Study progress: the answers each person checked in practice mode, where they were in each
-- exam, and the exams they saved. Each person sees their own; administrators (and anyone given
-- progress.view_all) can see everyone's.

-- One row per person, exam and question: the latest answer they checked and whether it was
-- right, graded on the server. question_key is QuestionView::key (topic and question number),
-- which stays the same when the exam is collected again.
CREATE TABLE IF NOT EXISTS study_answers (
    user_id      INT UNSIGNED NOT NULL,
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(48)  NOT NULL,
    number       INT UNSIGNED NOT NULL,       -- its number in the version last answered
    picked       VARCHAR(16)  NOT NULL,       -- the letters chosen, e.g. "BE"
    correct      TINYINT(1)   NOT NULL,       -- the latest answer was right
    attempts     INT UNSIGNED NOT NULL DEFAULT 1,
    wrong_count  INT UNSIGNED NOT NULL DEFAULT 0,
    first_at     DATETIME     NOT NULL,
    last_at      DATETIME     NOT NULL,
    PRIMARY KEY (user_id, exam_id, question_key),
    KEY ix_study_answers_exam (exam_id),
    CONSTRAINT fk_study_answers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_study_answers_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The page each person last opened in each exam, to continue from.
CREATE TABLE IF NOT EXISTS study_places (
    user_id    INT UNSIGNED NOT NULL,
    exam_id    INT UNSIGNED NOT NULL,
    mode       VARCHAR(16)  NOT NULL,         -- study | practice
    page       INT UNSIGNED NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (user_id, exam_id),
    KEY ix_study_places_recent (user_id, updated_at),
    CONSTRAINT fk_study_places_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_study_places_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exams a person saved to their own shelf in the library.
CREATE TABLE IF NOT EXISTS saved_exams (
    user_id  INT UNSIGNED NOT NULL,
    exam_id  INT UNSIGNED NOT NULL,
    saved_at DATETIME     NOT NULL,
    PRIMARY KEY (user_id, exam_id),
    CONSTRAINT fk_saved_exams_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_exams_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
