-- Question wording, choices and pictures corrected by hand, per exam question, like
-- answer_edits: shown over the collected data in the reader and in documents made after, for
-- every version of the exam. The collected data is never changed. NULL means "as collected".
CREATE TABLE IF NOT EXISTS question_edits (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id         INT UNSIGNED NOT NULL,
    question_key    VARCHAR(64)  NOT NULL,      -- as answer_edits: "<topic>-<question number>"
    text            MEDIUMTEXT   NULL,          -- the wording; "//IMG//" marks where a picture goes
    choices         TEXT         NULL,          -- JSON {"A": "wording"}: only the choices changed
    question_images TEXT         NULL,          -- JSON list of picture addresses, in order
    answer_images   TEXT         NULL,          -- the same for the answer's pictures
    user_id         INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_question_edits_question (exam_id, question_key),
    CONSTRAINT fk_question_edits_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pictures uploaded by hand, kept under storage/uploads and named by their content (the
-- address "upload:<sha256>"). Unlike downloaded images they cannot be fetched again, so every
-- backup includes them, with or without the downloaded images.
CREATE TABLE IF NOT EXISTS uploaded_images (
    sha256     CHAR(64)     NOT NULL,
    mime       VARCHAR(32)  NOT NULL,
    bytes      INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the integrity page knows about each exam's best version, worked out once per version
-- and set of corrections instead of reading every dataset on every visit. Worked out again
-- whenever the version or the corrections change; never backed up.
CREATE TABLE IF NOT EXISTS exam_checks (
    exam_id     INT UNSIGNED NOT NULL,
    version_id  INT UNSIGNED NOT NULL,
    fingerprint VARCHAR(32)  NOT NULL,          -- the exam's corrections when worked out ('' = none)
    questions   INT UNSIGNED NOT NULL,
    to_check    INT UNSIGNED NOT NULL,          -- answers that look doubtful, not corrected or checked
    no_answer   INT UNSIGNED NOT NULL,          -- of those, questions with no answer at all
    corrected   INT UNSIGNED NOT NULL,
    checked     INT UNSIGNED NOT NULL,
    edited      INT UNSIGNED NOT NULL,          -- questions whose wording or pictures were edited
    image_urls  MEDIUMTEXT   NOT NULL,          -- JSON list of the version's picture addresses
    computed_at DATETIME     NOT NULL,
    PRIMARY KEY (exam_id),
    CONSTRAINT fk_exam_checks_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
