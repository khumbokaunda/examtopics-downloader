-- Plans: what an institution gets (how many students and staff), with a price written by the
-- operator. An institution with no plan has no limits, so institutions there were before are
-- not affected. Every change of an institution's plan is kept.
CREATE TABLE IF NOT EXISTS plans (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36)     NOT NULL,
    name         VARCHAR(80)  NOT NULL,
    price        VARCHAR(80)  NULL,                  -- as shown, such as "MWK 40,000 a month"
    summary      VARCHAR(255) NULL,
    max_students INT UNSIGNED NULL,                  -- NULL: no limit
    max_staff    INT UNSIGNED NULL,
    public       TINYINT(1)   NOT NULL DEFAULT 1,    -- listed on the pricing page
    position     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    archived_at  DATETIME     NULL,                  -- not offered any more; institutions on it keep it
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institutions' AND COLUMN_NAME = 'plan_id');
SET @s := IF(@c = 0, 'ALTER TABLE institutions ADD COLUMN plan_id INT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institutions' AND CONSTRAINT_NAME = 'fk_institutions_plan');
SET @s := IF(@c = 0, 'ALTER TABLE institutions ADD CONSTRAINT fk_institutions_plan FOREIGN KEY (plan_id) REFERENCES plans (id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS institution_plan_changes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    plan_id        INT UNSIGNED NULL,                -- NULL: no plan, no limits
    note           VARCHAR(255) NULL,
    changed_by     INT UNSIGNED NULL,
    changed_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_institution_plan_changes (institution_id, changed_at),
    CONSTRAINT fk_plan_changes_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_changes_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
    CONSTRAINT fk_plan_changes_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages sent from the public contact page, for the people who look after institutions.
CREATE TABLE IF NOT EXISTS contact_messages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36)     NOT NULL,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    organisation VARCHAR(160) NULL,
    phone        VARCHAR(40)  NULL,
    body         TEXT         NOT NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'new',   -- new | answered
    handled_by   INT UNSIGNED NULL,
    handled_at   DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_messages_uuid (uuid),
    KEY ix_contact_messages_status (status, created_at),
    CONSTRAINT fk_contact_messages_user FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
