-- Institution onboarding. An application goes draft, submitted (pending), under review, more
-- information requested, then approved (verified), not approved (rejected) or later suspended.
-- Verification is kept in separate dimensions, so a registered institution is never shown as
-- accredited: organisation identity, legal registration, accreditation and the representative's
-- authority, each with its own state; the platform approval is institutions.status.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institutions' AND COLUMN_NAME = 'identity_status');
SET @s := IF(@c = 0, 'ALTER TABLE institutions
    ADD COLUMN year_established SMALLINT UNSIGNED NULL AFTER website,
    ADD COLUMN services VARCHAR(1000) NULL AFTER year_established,
    ADD COLUMN registration_authority VARCHAR(160) NULL AFTER registration_info,
    ADD COLUMN registration_number VARCHAR(80) NULL AFTER registration_authority,
    ADD COLUMN accreditation_authority VARCHAR(160) NULL AFTER registration_number,
    ADD COLUMN accreditation_reference VARCHAR(80) NULL AFTER accreditation_authority,
    ADD COLUMN accreditation_expires DATE NULL AFTER accreditation_reference,
    ADD COLUMN contact_phone VARCHAR(40) NULL AFTER contact_position,
    ADD COLUMN identity_status VARCHAR(16) NOT NULL DEFAULT ''pending'' AFTER status_note,
    ADD COLUMN registration_status VARCHAR(16) NOT NULL DEFAULT ''unverified'' AFTER identity_status,
    ADD COLUMN accreditation_status VARCHAR(16) NOT NULL DEFAULT ''unverified'' AFTER registration_status,
    ADD COLUMN representative_status VARCHAR(16) NOT NULL DEFAULT ''pending'' AFTER accreditation_status,
    ADD COLUMN submitted_at DATETIME NULL AFTER representative_status', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Institutions approved before this existed were checked by a reviewer: their identity and
-- representative count as verified. Registration and accreditation stay unverified.
UPDATE institutions SET identity_status = 'verified', representative_status = 'verified', submitted_at = COALESCE(submitted_at, created_at)
 WHERE status IN ('verified', 'suspended') AND identity_status = 'pending';
UPDATE institutions SET submitted_at = created_at WHERE submitted_at IS NULL AND status IN ('pending', 'rejected');

-- Which verification dimension a review row is about ('platform' for the application itself).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institution_reviews' AND COLUMN_NAME = 'dimension');
SET @s := IF(@c = 0, 'ALTER TABLE institution_reviews ADD COLUMN dimension VARCHAR(24) NOT NULL DEFAULT ''platform'' AFTER institution_id', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Supporting documents of an application, stored under storage/institution_documents (outside
-- the web root) and served only to the institution's managers and platform reviewers. A removed
-- document is marked removed, never deleted while it may be evidence for a decision.
CREATE TABLE IF NOT EXISTS institution_documents (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    kind           VARCHAR(32)  NOT NULL,             -- App\Services\Onboarding::DOCUMENTS
    filename       VARCHAR(190) NOT NULL,             -- as uploaded, for the download
    mime           VARCHAR(80)  NOT NULL,
    size           INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    uploaded_by    INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    removed_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_documents_uuid (uuid),
    KEY ix_institution_documents_institution (institution_id, removed_at),
    CONSTRAINT fk_institution_documents_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_documents_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which version of which policy or notice a person accepted, when, and in what context (an
-- institution application, a membership, a sitting). Only ever added to.
CREATE TABLE IF NOT EXISTS policy_acceptances (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    policy       VARCHAR(40)  NOT NULL,               -- App\Services\Policies::POLICIES
    version      VARCHAR(20)  NOT NULL,
    context      VARCHAR(24)  NOT NULL,               -- institution, membership, sitting, account
    context_id   INT UNSIGNED NULL,
    ip           VARCHAR(45)  NULL,
    accepted_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_policy_acceptances_user (user_id, policy),
    KEY ix_policy_acceptances_context (context, context_id),
    CONSTRAINT fk_policy_acceptances_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
