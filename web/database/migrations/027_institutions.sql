-- Institutions (tenants) and who belongs to them. An institution is registered by a signed-in
-- account, which becomes its owner, and waits for a platform reviewer to verify it. People join
-- through a membership with an institution role; the global account and its role (member,
-- administrator) are not changed by joining. A membership that ends is marked removed, never
-- deleted, so what the person did in the institution keeps its context.
CREATE TABLE IF NOT EXISTS institutions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)     NOT NULL,          -- its address: /institutions/<uuid>
    name              VARCHAR(160) NOT NULL,          -- official name
    short_name        VARCHAR(80)  NULL,              -- trading or commonly used name
    type              VARCHAR(32)  NOT NULL,          -- App\Services\Institutions::TYPES
    address           VARCHAR(255) NOT NULL,
    region            VARCHAR(40)  NOT NULL,
    district          VARCHAR(80)  NOT NULL,
    email             VARCHAR(190) NOT NULL,
    phone             VARCHAR(40)  NOT NULL,
    website           VARCHAR(255) NULL,
    registration_info VARCHAR(500) NULL,              -- registration or accreditation details, as given
    contact_name      VARCHAR(120) NOT NULL,
    contact_position  VARCHAR(120) NOT NULL,
    intended_use      VARCHAR(1000) NOT NULL,
    status            VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending, verified, rejected, suspended
    status_note       VARCHAR(1000) NULL,             -- the reason given with the latest decision
    decided_by        INT UNSIGNED NULL,
    decided_at        DATETIME     NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institutions_uuid (uuid),
    KEY ix_institutions_status (status, created_at),
    CONSTRAINT fk_institutions_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_institutions_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every verification decision, with its reviewer, time, reason and the evidence it rested on.
CREATE TABLE IF NOT EXISTS institution_reviews (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    from_status    VARCHAR(16)  NOT NULL,
    to_status      VARCHAR(16)  NOT NULL,
    note           VARCHAR(1000) NULL,
    evidence       VARCHAR(1000) NULL,
    user_id        INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_institution_reviews_institution (institution_id, id),
    CONSTRAINT fk_institution_reviews_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_memberships (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    role           VARCHAR(32)  NOT NULL,             -- App\Services\InstitutionAccess::ROLES
    permissions    TEXT         NULL,                 -- JSON list of extra institution permissions
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, removed
    invited_by     INT UNSIGNED NULL,
    joined_at      DATETIME     NOT NULL,
    removed_at     DATETIME     NULL,
    removed_by     INT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_memberships_uuid (uuid),
    UNIQUE KEY uq_institution_memberships_person (institution_id, user_id),
    UNIQUE KEY uq_institution_memberships_tenant (id, institution_id), -- for composite foreign keys that keep references inside one institution
    KEY ix_institution_memberships_user (user_id, status),
    CONSTRAINT fk_institution_memberships_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_memberships_invited_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_institution_memberships_removed_by FOREIGN KEY (removed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An invitation is sent to an email address; its link carries a random token, of which only
-- the SHA-256 is stored. It is accepted by a signed-in account with the same email address.
CREATE TABLE IF NOT EXISTS institution_invitations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    email          VARCHAR(190) NOT NULL,
    role           VARCHAR(32)  NOT NULL,
    details        TEXT         NULL,                 -- JSON applied on acceptance: student number, classes (from a student import)
    token_sha256   CHAR(64)     NOT NULL,
    invited_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    expires_at     DATETIME     NOT NULL,
    accepted_at    DATETIME     NULL,
    accepted_by    INT UNSIGNED NULL,
    declined_at    DATETIME     NULL,
    revoked_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_invitations_token (token_sha256),
    KEY ix_institution_invitations_institution (institution_id, email),
    CONSTRAINT fk_institution_invitations_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_invitations_invited_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_institution_invitations_accepted_by FOREIGN KEY (accepted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
