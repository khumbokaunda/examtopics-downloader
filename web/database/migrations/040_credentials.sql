-- Credentials issued by institutions, separate from ExamLib's own readiness certificates
-- (certificates). Each has a category with its own rules: assessment completion, institutional
-- achievement, and formal qualification (only for an institution the platform recorded as an
-- authorised awarding body). Templates say what a credential certifies and who is eligible;
-- signatories are the staff an owner authorised to approve and sign each category.
--
-- A credential is never changed once issued: a correction is a new credential that replaces
-- it, revocation keeps it with its reason, and every step is in credential_events.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institutions' AND COLUMN_NAME = 'awarding_body');
SET @s := IF(@c = 0, 'ALTER TABLE institutions ADD COLUMN awarding_body VARCHAR(160) NULL AFTER accreditation_status, ADD COLUMN logo_file VARCHAR(80) NULL AFTER awarding_body', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS credential_signatories (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    position       VARCHAR(120) NOT NULL,             -- for example Principal, Director of Studies
    categories     VARCHAR(120) NOT NULL,             -- comma-separated App\Services\Credentials::CATEGORIES keys
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credential_signatories_uuid (uuid),
    UNIQUE KEY uq_credential_signatories_member (institution_id, membership_id),
    UNIQUE KEY uq_credential_signatories_tenant (id, institution_id),
    CONSTRAINT fk_credential_signatories_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_templates (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)     NOT NULL,
    institution_id    INT UNSIGNED NOT NULL,
    category          VARCHAR(16)  NOT NULL,          -- completion, achievement, qualification
    name              VARCHAR(120) NOT NULL,          -- for staff
    title             VARCHAR(160) NOT NULL,          -- printed: "Certificate of Achievement"
    body              VARCHAR(1000) NOT NULL,         -- what it certifies, printed under the name
    footer            VARCHAR(300) NULL,
    rule              VARCHAR(16)  NOT NULL,          -- assessment, programme, manual
    assessment_id     INT UNSIGNED NULL,
    programme_id      INT UNSIGNED NULL,
    min_percent       DECIMAL(5,2) NULL,
    requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
    validity_months   SMALLINT UNSIGNED NULL,         -- NULL: does not expire
    status            VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, retired
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credential_templates_uuid (uuid),
    UNIQUE KEY uq_credential_templates_tenant (id, institution_id),
    KEY ix_credential_templates_institution (institution_id, status),
    CONSTRAINT fk_credential_templates_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_templates_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id),
    CONSTRAINT fk_credential_templates_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id),
    CONSTRAINT fk_credential_templates_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credentials (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid               CHAR(36)     NOT NULL,
    number             VARCHAR(24)  NOT NULL,         -- EXL-YYYY-XXXXXXXX
    token              CHAR(32)     NOT NULL,         -- the public verification link
    institution_id     INT UNSIGNED NOT NULL,
    membership_id      INT UNSIGNED NOT NULL,
    template_id        INT UNSIGNED NOT NULL,
    category           VARCHAR(16)  NOT NULL,
    title              VARCHAR(160) NOT NULL,
    holder_name        VARCHAR(160) NOT NULL,
    achievement        VARCHAR(1000) NOT NULL,
    programme_name     VARCHAR(160) NULL,
    assessment_title   VARCHAR(160) NULL,
    assessment_ref     CHAR(36)     NULL,             -- the sitting it rests on
    percent            DECIMAL(5,2) NULL,
    achieved_on        DATE         NOT NULL,
    expires_on         DATE         NULL,
    status             VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending, issued, declined, revoked, replaced
    subject_key        VARCHAR(80)  NOT NULL,         -- template, holder and achievement it is for
    active_key         VARCHAR(80)  NULL,             -- subject_key while pending or issued: one at a time unless a duplicate is authorised
    requested_by       INT UNSIGNED NULL,
    requested_at       DATETIME     NOT NULL,
    issuer_name        VARCHAR(160) NULL,             -- the institution's name when it was issued
    signatory_id       INT UNSIGNED NULL,
    signatory_name     VARCHAR(160) NULL,
    signatory_position VARCHAR(120) NULL,
    approved_by        INT UNSIGNED NULL,
    issued_at          DATETIME     NULL,
    replaces_id        INT UNSIGNED NULL,
    revoked_at         DATETIME     NULL,
    revoked_by         INT UNSIGNED NULL,
    revoke_reason      VARCHAR(500) NULL,
    fingerprint        CHAR(64)     NULL,             -- SHA-256 of the issued fields
    signature          VARCHAR(200) NULL,             -- Ed25519 over the fingerprint, base64
    key_id             CHAR(16)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credentials_uuid (uuid),
    UNIQUE KEY uq_credentials_number (number),
    UNIQUE KEY uq_credentials_token (token),
    UNIQUE KEY uq_credentials_active (active_key),
    KEY ix_credentials_institution (institution_id, status),
    KEY ix_credentials_member (membership_id, institution_id),
    CONSTRAINT fk_credentials_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_credentials_template FOREIGN KEY (template_id, institution_id) REFERENCES credential_templates (id, institution_id),
    CONSTRAINT fk_credentials_signatory FOREIGN KEY (signatory_id) REFERENCES credential_signatories (id) ON DELETE SET NULL,
    CONSTRAINT fk_credentials_replaces FOREIGN KEY (replaces_id) REFERENCES credentials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    credential_id INT UNSIGNED NOT NULL,
    action        VARCHAR(24)  NOT NULL,              -- requested, approved, issued, declined, revoked, replaced
    note          VARCHAR(500) NULL,
    user_id       INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_credential_events_credential (credential_id, id),
    CONSTRAINT fk_credential_events_credential FOREIGN KEY (credential_id) REFERENCES credentials (id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
