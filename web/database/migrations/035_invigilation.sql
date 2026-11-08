-- Live invigilation. Invigilators are assigned to assessments; they watch the sittings and record
-- observations (only ever added). Examination officers can pause a sitting (its clock stops while
-- paused), give extra time, or end it; each action is also kept in the sitting's integrity record.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_sessions' AND COLUMN_NAME = 'paused_at');
SET @s := IF(@c = 0, 'ALTER TABLE assessment_sessions ADD COLUMN paused_at DATETIME NULL AFTER ends_at, ADD COLUMN extra_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER paused_at', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS invigilator_assignments (
    assessment_id  INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    assigned_at    DATETIME     NOT NULL,
    PRIMARY KEY (assessment_id, membership_id),
    KEY ix_invigilator_assignments_assessment (assessment_id, institution_id),
    KEY ix_invigilator_assignments_member (membership_id, institution_id),
    CONSTRAINT fk_invigilator_assignments_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_invigilator_assignments_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invigilator_observations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    category       VARCHAR(24)  NOT NULL,             -- note, incident, technical, interruption, identity_checked
    note           VARCHAR(2000) NOT NULL,
    membership_id  INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_invigilator_observations_session (session_id, institution_id, id),
    CONSTRAINT fk_invigilator_observations_session FOREIGN KEY (session_id, institution_id) REFERENCES assessment_sessions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
