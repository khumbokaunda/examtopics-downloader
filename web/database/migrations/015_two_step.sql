-- Two-step sign-in: a code from an authenticator app (TOTP, RFC 6238) after the password.
-- A row with enabled_at NULL is a setup that was started but not confirmed with a code.
-- last_used_step stops a code being used twice.
CREATE TABLE IF NOT EXISTS user_totp (
    user_id        INT UNSIGNED NOT NULL,
    secret         VARCHAR(64)  NOT NULL,     -- base32
    enabled_at     DATETIME     NULL,
    last_used_step BIGINT       NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_totp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time recovery codes for a lost phone. Only a hash of each is stored.
CREATE TABLE IF NOT EXISTS totp_recovery_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  CHAR(64)     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_totp_recovery_user (user_id),
    CONSTRAINT fk_totp_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
