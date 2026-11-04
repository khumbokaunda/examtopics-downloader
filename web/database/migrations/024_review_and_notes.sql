-- Spaced review: each answered question has a box (0 to 5) and a day it is due again. Answered
-- right when due, it moves up a box and comes back after 1, 2, 4, 8, 16 or 32 days; answered
-- wrong, it goes back to box 0 and is due the next day. Answered right before it is due, the
-- schedule stays as it was.
ALTER TABLE study_answers
    ADD COLUMN box TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN due_on DATE NULL,
    ADD KEY ix_study_answers_due (user_id, due_on);

-- Answers from before: right ones are due two days after, wrong ones the day after.
UPDATE study_answers SET box = correct, due_on = DATE_ADD(DATE(last_at), INTERVAL IF(correct = 1, 2, 1) DAY) WHERE due_on IS NULL;

-- A person's own notes and bookmarks on questions. Private: only they see them.
CREATE TABLE IF NOT EXISTS question_notes (
    user_id      INT UNSIGNED NOT NULL,
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    note         TEXT         NULL,
    bookmarked   TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (user_id, exam_id, question_key),
    CONSTRAINT fk_question_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_notes_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
