-- Integrity monitoring. An assessment chooses how much is observed (monitoring): basic (timing
-- and answers only), standard (also when the page is hidden or loses focus, reloads and the
-- connection), or enhanced (also leaving full screen and copy, cut and paste, never what was
-- copied). A sitting keeps the policy it started under, so monitoring is never switched on part
-- way through. Events are observations, never verdicts, and are only ever added.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'monitoring');
SET @s := IF(@c = 0, 'ALTER TABLE assessments ADD COLUMN monitoring VARCHAR(16) NOT NULL DEFAULT ''basic'' AFTER review_policy', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_sessions' AND COLUMN_NAME = 'monitoring');
SET @s := IF(@c = 0, 'ALTER TABLE assessment_sessions ADD COLUMN monitoring VARCHAR(16) NOT NULL DEFAULT ''basic'' AFTER seed', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- seq is the browser's own counter: an event sent twice (a retry) is stored once.
CREATE TABLE IF NOT EXISTS assessment_events (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    seq            BIGINT UNSIGNED NOT NULL,          -- 0 for events the server records itself
    type           VARCHAR(24)  NOT NULL,
    source         VARCHAR(8)   NOT NULL,             -- browser or server
    client_at      DATETIME     NULL,                 -- when the browser says it happened (not trusted for timing)
    received_at    DATETIME     NOT NULL,             -- when the server received it
    duration_ms    INT UNSIGNED NULL,                 -- how long a hidden or offline spell lasted, as the browser measured it
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_events_seq (session_id, seq, type),
    KEY ix_assessment_events_session (session_id, institution_id, id),
    CONSTRAINT fk_assessment_events_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A person's conclusion about a sitting's integrity, kept apart from the automatic observations.
-- A new review is added; earlier ones stay.
CREATE TABLE IF NOT EXISTS integrity_reviews (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    outcome        VARCHAR(24)  NOT NULL,             -- no_concern, concern_noted, refer
    note           VARCHAR(2000) NOT NULL,
    membership_id  INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_integrity_reviews_session (session_id, institution_id, id),
    CONSTRAINT fk_integrity_reviews_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
