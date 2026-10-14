-- Accounts for more than one person: a display name, an email address, a role, extra
-- permissions on top of the role (a JSON list) and a status. Accounts that existed before
-- this migration keep full access, so they become administrators; new accounts default to
-- members.
ALTER TABLE users
    ADD COLUMN display_name      VARCHAR(128) NULL AFTER username,
    ADD COLUMN email             VARCHAR(190) NULL AFTER display_name,
    ADD COLUMN email_verified_at DATETIME     NULL AFTER email,
    ADD COLUMN role              VARCHAR(16)  NOT NULL DEFAULT 'admin' AFTER email_verified_at,
    ADD COLUMN permissions       TEXT         NULL AFTER role,
    ADD COLUMN status            VARCHAR(16)  NOT NULL DEFAULT 'active' AFTER permissions,
    ADD UNIQUE KEY uq_users_email (email);

ALTER TABLE users ALTER COLUMN role SET DEFAULT 'member';
