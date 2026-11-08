-- Candidates sitting assessments. A sitting (assessment_sessions) is one attempt by one student:
-- when it started, when it must end (the server's clock decides, never the browser's), and its
-- result once handed in. Its exact form is stored in assessment_session_questions: the question
-- versions it drew, in the order shown, with each one's choice order, so the form can always be
-- shown again exactly as it was. Answers are saved as they are picked (assessment_responses,
-- the latest per question) and every change is kept (assessment_response_history, append-only).
CREATE TABLE IF NOT EXISTS assessment_sessions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,                 -- its address: /sit/<uuid>
    institution_id INT UNSIGNED NOT NULL,
    assessment_id  INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,                 -- the student
    class_id       INT UNSIGNED NULL,                     -- the class it was assigned through, at the time it started
    attempt        INT UNSIGNED NOT NULL,
    seed           INT UNSIGNED NOT NULL,                 -- the draw that made its form
    status         VARCHAR(16)  NOT NULL DEFAULT 'in_progress', -- in_progress, submitted, auto_submitted
    terms_accepted_at DATETIME  NOT NULL,
    started_at     DATETIME     NOT NULL,
    ends_at        DATETIME     NOT NULL,                 -- started_at + time allowed, or the closing time if sooner
    submitted_at   DATETIME     NULL,
    score          DECIMAL(9,2) NULL,
    max_score      DECIMAL(9,2) NOT NULL,
    percent        DECIMAL(5,2) NULL,
    passed         TINYINT(1)   NULL,                     -- NULL when the assessment has no pass mark
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_sessions_uuid (uuid),
    UNIQUE KEY uq_assessment_sessions_attempt (assessment_id, membership_id, attempt),
    UNIQUE KEY uq_assessment_sessions_tenant (id, institution_id),
    KEY ix_assessment_sessions_assessment (assessment_id, institution_id, status),
    KEY ix_assessment_sessions_member (membership_id, institution_id),
    KEY ix_assessment_sessions_class (class_id, institution_id),
    KEY ix_assessment_sessions_open (status, ends_at),
    CONSTRAINT fk_assessment_sessions_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id),
    CONSTRAINT fk_assessment_sessions_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id),
    CONSTRAINT fk_assessment_sessions_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_session_questions (
    session_id          INT UNSIGNED NOT NULL,
    institution_id      INT UNSIGNED NOT NULL,
    position            INT UNSIGNED NOT NULL,            -- 1, 2, 3... as shown
    question_version_id INT UNSIGNED NOT NULL,
    choice_order        VARCHAR(64)  NOT NULL,            -- the choice keys in the order shown, such as "CADB"
    marks               DECIMAL(6,2) NOT NULL,
    awarded             DECIMAL(6,2) NULL,                -- set when graded
    correct             TINYINT(1)   NULL,
    PRIMARY KEY (session_id, position),
    KEY ix_assessment_session_questions_session (session_id, institution_id),
    KEY ix_assessment_session_questions_version (question_version_id, institution_id),
    CONSTRAINT fk_assessment_session_questions_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_session_questions_version FOREIGN KEY (question_version_id, institution_id) REFERENCES question_versions (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seq orders a candidate's saves: a save carrying a lower number than the one stored arrived
-- late and is ignored, so a slow request never overwrites a newer answer.
CREATE TABLE IF NOT EXISTS assessment_responses (
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    position       INT UNSIGNED NOT NULL,
    answer         VARCHAR(16)  NOT NULL DEFAULT '',     -- the choice keys picked, sorted, such as "AC"
    flagged        TINYINT(1)   NOT NULL DEFAULT 0,
    seq            BIGINT UNSIGNED NOT NULL DEFAULT 0,
    saved_at       DATETIME     NOT NULL,
    PRIMARY KEY (session_id, position),
    KEY ix_assessment_responses_session (session_id, institution_id),
    CONSTRAINT fk_assessment_responses_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_response_history (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    position       INT UNSIGNED NOT NULL,
    answer         VARCHAR(16)  NOT NULL,
    flagged        TINYINT(1)   NOT NULL,
    seq            BIGINT UNSIGNED NOT NULL,
    received_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_assessment_response_history_session (session_id, institution_id, position),
    CONSTRAINT fk_assessment_response_history_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
