-- Programmes with modules, programme history per student, module completions, transcripts, and
-- what an institution records about a student under 18.
--
-- Completions are written once and never changed: relinking an assessment to another module, or
-- retiring a module, does not rewrite what a student already completed. Transcripts store a
-- snapshot of the record as issued, so a later change never alters an issued transcript.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institution_programmes' AND COLUMN_NAME = 'description');
SET @s := IF(@c = 0, 'ALTER TABLE institution_programmes ADD COLUMN description VARCHAR(2000) NULL AFTER code, ADD COLUMN duration VARCHAR(60) NULL AFTER description,
    ADD COLUMN objectives VARCHAR(2000) NULL AFTER duration, ADD COLUMN completion_requirements VARCHAR(1000) NULL AFTER objectives,
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT ''active'' AFTER completion_requirements', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS programme_modules (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    programme_id   INT UNSIGNED NOT NULL,
    code           VARCHAR(32)  NULL,
    name           VARCHAR(160) NOT NULL,
    description    VARCHAR(1000) NULL,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    required       TINYINT(1)   NOT NULL DEFAULT 1,
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, retired
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_programme_modules_uuid (uuid),
    UNIQUE KEY uq_programme_modules_tenant (id, institution_id),
    KEY ix_programme_modules_programme (programme_id, institution_id, position),
    CONSTRAINT fk_programme_modules_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The module an assessment belongs to, if any.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'module_id');
SET @s := IF(@c = 0, 'ALTER TABLE assessments ADD COLUMN module_id INT UNSIGNED NULL AFTER institution_id, ADD KEY ix_assessments_module (module_id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND CONSTRAINT_NAME = 'fk_assessments_module');
SET @s := IF(@c = 0, 'ALTER TABLE assessments ADD CONSTRAINT fk_assessments_module FOREIGN KEY (module_id, institution_id) REFERENCES programme_modules (id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Each programme a student has been on, from when to when, and how it ended.
CREATE TABLE IF NOT EXISTS programme_enrolments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    programme_id   INT UNSIGNED NOT NULL,
    started_on     DATE         NOT NULL,
    ended_on       DATE         NULL,
    outcome        VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, completed, withdrawn, changed
    recorded_by    INT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_programme_enrolments_member (membership_id, institution_id, outcome),
    CONSTRAINT fk_programme_enrolments_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_programme_enrolments_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_programme_enrolments_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO programme_enrolments (institution_id, membership_id, programme_id, started_on, outcome, updated_at)
SELECT s.institution_id, s.membership_id, s.programme_id, COALESCE(s.enrolled_on, DATE(s.updated_at)), 'active', s.updated_at
  FROM institution_students s
 WHERE s.programme_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM programme_enrolments e WHERE e.membership_id = s.membership_id AND e.programme_id = s.programme_id);

-- A module a student completed: by passing an assessment of it (once results were published), or
-- recorded by staff. Written once.
CREATE TABLE IF NOT EXISTS module_completions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    module_id      INT UNSIGNED NOT NULL,
    session_id     INT UNSIGNED NULL,
    percent        DECIMAL(5,2) NULL,
    method         VARCHAR(16)  NOT NULL DEFAULT 'assessment', -- assessment, manual
    recorded_by    INT UNSIGNED NULL,
    completed_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_module_completions (membership_id, module_id),
    KEY ix_module_completions_module (module_id, institution_id),
    CONSTRAINT fk_module_completions_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_module_completions_module FOREIGN KEY (module_id, institution_id) REFERENCES programme_modules (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_module_completions_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcripts (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                 CHAR(36)     NOT NULL,
    number               VARCHAR(24)  NULL,
    institution_id       INT UNSIGNED NOT NULL,
    membership_id        INT UNSIGNED NOT NULL,
    snapshot             MEDIUMTEXT   NOT NULL,          -- JSON of the record as issued
    includes_provisional TINYINT(1)   NOT NULL DEFAULT 0,
    status               VARCHAR(16)  NOT NULL DEFAULT 'issued', -- issued, withdrawn
    issued_by            INT UNSIGNED NULL,
    issued_at            DATETIME     NOT NULL,
    withdrawn_at         DATETIME     NULL,
    withdrawn_reason     VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transcripts_uuid (uuid),
    UNIQUE KEY uq_transcripts_number (number),
    KEY ix_transcripts_member (membership_id, institution_id),
    CONSTRAINT fk_transcripts_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_transcripts_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student under 18: date of birth, a guardian, and when the institution recorded the
-- guardian's agreement (the institution establishes the lawful basis; ExamLib records it).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institution_students' AND COLUMN_NAME = 'date_of_birth');
SET @s := IF(@c = 0, 'ALTER TABLE institution_students ADD COLUMN date_of_birth DATE NULL AFTER enrolled_on, ADD COLUMN guardian_name VARCHAR(120) NULL AFTER date_of_birth,
    ADD COLUMN guardian_contact VARCHAR(190) NULL AFTER guardian_name, ADD COLUMN guardian_consent_at DATETIME NULL AFTER guardian_contact,
    ADD COLUMN guardian_consent_by INT UNSIGNED NULL AFTER guardian_consent_at', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
