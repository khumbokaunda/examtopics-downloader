-- The activity history: who did what, and when. Rows are only ever added. Each row carries
-- the hash of the row before it and its own hash, so a row changed or removed afterwards
-- breaks the chain where it was (bin/verify-audit.php, or "Check the history" on the page).
-- No foreign key on user_id: the history outlives the account, and names the person as they
-- were called at the time (actor).
CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at   DATETIME     NOT NULL,
    actor_kind   VARCHAR(10)  NOT NULL,              -- user | visitor | system
    user_id      INT UNSIGNED NULL,
    actor        VARCHAR(190) NOT NULL DEFAULT '',   -- username at the time; empty for system
    action       VARCHAR(64)  NOT NULL,
    subject_type VARCHAR(32)  NULL,
    subject_id   VARCHAR(190) NULL,
    details      TEXT         NULL,                  -- JSON
    ip           VARCHAR(45)  NULL,
    prev_hash    CHAR(64)     NOT NULL,
    hash         CHAR(64)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_created (created_at),
    KEY ix_audit_user (user_id, created_at),
    KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
