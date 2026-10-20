-- Sign-in protection. An account locks for a while after too many wrong passwords in a row
-- (failed_logins counts them, locked_until ends the lock). A network is refused for a while
-- after too many failed sign-ins across any names (blocked_networks). Each attempt keeps the
-- account it named, why it failed, and whether an administrator forgave it (unblocking a
-- network forgives its failures, so they do not block it again at once).
ALTER TABLE users
    ADD COLUMN failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN locked_until DATETIME NULL;

ALTER TABLE login_attempts
    ADD COLUMN user_id INT UNSIGNED NULL,
    ADD COLUMN reason VARCHAR(32) NULL,
    ADD COLUMN forgiven TINYINT(1) NOT NULL DEFAULT 0,
    ADD KEY ix_login_attempts_user (user_id, attempted_at),
    ADD KEY ix_login_attempts_time (attempted_at);

CREATE TABLE IF NOT EXISTS blocked_networks (
    ip            VARCHAR(45)  NOT NULL,
    blocked_until DATETIME     NOT NULL,
    failures      INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "It was them": a sign-in flagged as possibly not the account owner, cleared by someone who
-- checked. A row here, never a change to the history.
CREATE TABLE IF NOT EXISTS sign_in_flag_clearances (
    attempt_id BIGINT UNSIGNED NOT NULL,
    cleared_by INT UNSIGNED NULL,
    cleared_at DATETIME NOT NULL,
    PRIMARY KEY (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attempts made before this update: tie them to the account they named, so real names are not
-- listed as names that do not exist, and keep anything else that may be a password (12 or
-- more characters, not an email address) as its length only.
UPDATE login_attempts a JOIN users u ON u.username = a.username OR u.email = a.username
    SET a.user_id = u.id, a.username = u.username
    WHERE a.user_id IS NULL;
UPDATE login_attempts
    SET username = CONCAT('(', CHAR_LENGTH(username), ' characters, possibly a password)')
    WHERE user_id IS NULL AND username IS NOT NULL AND CHAR_LENGTH(username) >= 12 AND username NOT LIKE '%@%'
      AND username NOT LIKE '(% characters, possibly a password)';
UPDATE login_attempts
    SET reason = CASE WHEN user_id IS NULL THEN 'no such account' ELSE 'wrong password' END
    WHERE succeeded = 0 AND reason IS NULL;
