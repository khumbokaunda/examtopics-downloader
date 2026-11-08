-- Messages from an invigilator to a candidate during a sitting (one candidate, or everyone
-- writing). The candidate's page shows them; seen_at records when the page first received one.
CREATE TABLE IF NOT EXISTS invigilator_messages (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    body           VARCHAR(500) NOT NULL,
    membership_id  INT UNSIGNED NULL,                 -- who sent it
    created_at     DATETIME     NOT NULL,
    seen_at        DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_invigilator_messages_session (session_id, institution_id, id),
    CONSTRAINT fk_invigilator_messages_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
