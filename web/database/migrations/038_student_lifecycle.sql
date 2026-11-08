-- The student lifecycle. Invitations remember when their link was first opened and how often
-- they were sent, so the institution can tell "invited" from "pending registration" and
-- "pending acceptance". Enrolment codes let a student with an account ask to join (eligibility,
-- never proof of identity); a code can require approval, be limited to a programme or class,
-- expire, and cap its uses.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institution_invitations' AND COLUMN_NAME = 'viewed_at');
SET @s := IF(@c = 0, 'ALTER TABLE institution_invitations ADD COLUMN viewed_at DATETIME NULL AFTER expires_at,
    ADD COLUMN sent_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER viewed_at, ADD COLUMN last_sent_at DATETIME NULL AFTER sent_count', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS enrolment_codes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)     NOT NULL,
    institution_id    INT UNSIGNED NOT NULL,
    code              VARCHAR(24)  NOT NULL,          -- what students type, e.g. BICT-7K2M-Q9WD
    label             VARCHAR(120) NULL,              -- for staff: which group it is for
    programme_id      INT UNSIGNED NULL,
    class_id          INT UNSIGNED NULL,
    requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
    max_uses          INT UNSIGNED NULL,
    uses              INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at        DATETIME     NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL,
    revoked_at        DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_codes_uuid (uuid),
    UNIQUE KEY uq_enrolment_codes_code (code),
    UNIQUE KEY uq_enrolment_codes_tenant (id, institution_id),
    KEY ix_enrolment_codes_institution (institution_id, revoked_at),
    CONSTRAINT fk_enrolment_codes_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_enrolment_codes_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id),
    CONSTRAINT fk_enrolment_codes_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id),
    CONSTRAINT fk_enrolment_codes_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A request to join made with a code that needs approval. Kept after a decision.
CREATE TABLE IF NOT EXISTS enrolment_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    code_id        INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending, approved, declined
    created_at     DATETIME     NOT NULL,
    decided_by     INT UNSIGNED NULL,
    decided_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_requests_uuid (uuid),
    KEY ix_enrolment_requests_institution (institution_id, status),
    KEY ix_enrolment_requests_user (user_id, status),
    CONSTRAINT fk_enrolment_requests_code FOREIGN KEY (code_id, institution_id) REFERENCES enrolment_codes (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_enrolment_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_enrolment_requests_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
