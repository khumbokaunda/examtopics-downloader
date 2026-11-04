-- Timed mock exams: a random set of an exam's questions, answered without seeing the answers,
-- then graded on the server. Picks are saved as they are made, so a reload or a lost connection
-- loses nothing, and the time limit is kept by the server (ends_at), not the browser.
CREATE TABLE IF NOT EXISTS mock_exams (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)     NOT NULL,         -- its address: /mock/<uuid>
    user_id       INT UNSIGNED NOT NULL,
    exam_id       INT UNSIGNED NOT NULL,
    version_id    INT UNSIGNED NOT NULL,         -- the version its questions came from
    question_keys TEXT         NOT NULL,         -- JSON list of question keys, in the order asked
    picks         MEDIUMTEXT   NULL,             -- JSON {"<key>": "BE"} picked so far
    flags         TEXT         NULL,             -- JSON list of keys marked to look at again
    minutes       INT UNSIGNED NOT NULL,         -- time limit; 0 = none
    started_at    DATETIME     NOT NULL,
    ends_at       DATETIME     NULL,             -- started_at + minutes; NULL when untimed
    finished_at   DATETIME     NULL,
    total         INT UNSIGNED NOT NULL,
    right_count   INT UNSIGNED NULL,             -- set when finished
    results       MEDIUMTEXT   NULL,             -- JSON per question: picked, answer, right, number
    PRIMARY KEY (id),
    UNIQUE KEY uq_mock_exams_uuid (uuid),
    KEY ix_mock_exams_user (user_id, exam_id, finished_at),
    CONSTRAINT fk_mock_exams_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_mock_exams_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
