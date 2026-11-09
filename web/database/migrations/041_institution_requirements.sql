-- What the platform asks of institutions, set by platform administrators: the documents to upload
-- (one slot each, required or optional, for some or all kinds of institution), extra profile
-- fields, and which optional built-in profile fields are shown. Also soft deletion.
CREATE TABLE IF NOT EXISTS institution_requirements (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    kind        VARCHAR(16)  NOT NULL,              -- document, field, builtin
    field_key   VARCHAR(40)  NULL,                  -- builtin: the institutions column
    label       VARCHAR(120) NOT NULL,
    help        VARCHAR(255) NULL,
    field_type  VARCHAR(16)  NOT NULL DEFAULT 'text', -- field: text, textarea, number, date, url, email, select
    options     VARCHAR(1000) NULL,                 -- field of type select: one option per line
    required    TINYINT(1)   NOT NULL DEFAULT 0,
    applies_to  VARCHAR(255) NULL,                  -- comma-separated institution types; NULL for all
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_requirements_uuid (uuid),
    KEY ix_institution_requirements_kind (kind, active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_field_values (
    institution_id INT UNSIGNED NOT NULL,
    requirement_id INT UNSIGNED NOT NULL,
    value          TEXT         NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (institution_id, requirement_id),
    CONSTRAINT fk_institution_field_values_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_field_values_requirement FOREIGN KEY (requirement_id) REFERENCES institution_requirements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The document slots there were until now, and the optional built-in fields, all shown.
INSERT INTO institution_requirements (uuid, kind, field_key, label, help, required, applies_to, position, created_at, updated_at)
SELECT * FROM (
    SELECT UUID() AS u, 'document' AS k, 'registration_certificate' AS fk, 'Registration certificate' AS l, NULL AS h, 1 AS r, 'university,college,tevet,exam_provider' AS a,
        1 AS p, NOW() AS c, NOW() AS m UNION ALL
    SELECT UUID(), 'document', 'accreditation_certificate', 'Accreditation certificate or letter', NULL, 0, NULL, 2, NOW(), NOW() UNION ALL
    SELECT UUID(), 'document', 'authority_letter', 'Letter authorising the representative', NULL, 0, NULL, 3, NOW(), NOW() UNION ALL
    SELECT UUID(), 'document', 'identification', 'Representative\'s identification', NULL, 0, NULL, 4, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'short_name', 'Trading name', NULL, 0, NULL, 10, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'year_established', 'Year established', NULL, 0, NULL, 11, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'services', 'Training offered', NULL, 0, NULL, 12, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'website', 'Website', NULL, 0, NULL, 13, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'registration', 'Registration authority and number', NULL, 0, NULL, 14, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'accreditation', 'Accreditation details', NULL, 0, NULL, 15, NOW(), NOW() UNION ALL
    SELECT UUID(), 'builtin', 'registration_info', 'Other notes', NULL, 0, NULL, 16, NOW(), NOW()
) seed
WHERE NOT EXISTS (SELECT 1 FROM institution_requirements);

-- Which document slot an uploaded document fills.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institution_documents' AND COLUMN_NAME = 'requirement_id');
SET @s := IF(@c = 0, 'ALTER TABLE institution_documents ADD COLUMN requirement_id INT UNSIGNED NULL AFTER kind, ADD KEY ix_institution_documents_requirement (requirement_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE institution_documents d JOIN institution_requirements r ON r.kind = 'document' AND r.field_key = d.kind SET d.requirement_id = r.id WHERE d.requirement_id IS NULL;

-- Soft deletion: hidden and closed, kept and restorable.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institutions' AND COLUMN_NAME = 'deleted_at');
SET @s := IF(@c = 0, 'ALTER TABLE institutions ADD COLUMN deleted_at DATETIME NULL AFTER updated_at, ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
