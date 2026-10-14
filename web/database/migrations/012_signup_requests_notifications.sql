-- Emailed six-digit codes: "verify" proves an email address, "reset" sets a new password.
-- Only a hash of the code is stored.
CREATE TABLE account_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    purpose     VARCHAR(16)  NOT NULL,              -- verify | reset
    code_hash   CHAR(64)     NOT NULL,
    email       VARCHAR(190) NOT NULL,              -- the address it was sent to
    attempts    INT UNSIGNED NOT NULL DEFAULT 0,    -- wrong codes typed against it
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_account_codes_user (user_id, purpose, id),
    CONSTRAINT fk_account_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limits for sign-ups and emailed codes. Subjects (an IP address, an account) are hashed.
CREATE TABLE throttle_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket     VARCHAR(32) NOT NULL,
    subject    CHAR(64)    NOT NULL,
    created_at DATETIME    NOT NULL,
    PRIMARY KEY (id),
    KEY ix_throttle_events (bucket, subject, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The inbox. A repeat of the same kind and link merges into the unread row (count goes up);
-- resolve_key marks a family read once it is dealt with.
CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED  NOT NULL,
    kind        VARCHAR(32)   NOT NULL,
    title       VARCHAR(255)  NOT NULL,
    body        VARCHAR(1000) NULL,
    link        VARCHAR(255)  NOT NULL,             -- a path inside the app
    resolve_key VARCHAR(200)  NULL,
    count       INT UNSIGNED  NOT NULL DEFAULT 1,
    read_at     DATETIME      NULL,
    created_at  DATETIME      NOT NULL,
    updated_at  DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notifications_user (user_id, read_at, updated_at),
    KEY ix_notifications_resolve (resolve_key),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests for exams that have not been collected. open_key is "<user>:<provider>:<exam>"
-- while open and NULL after, so each person has at most one open request per exam.
CREATE TABLE exam_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    exam_code   VARCHAR(128) NOT NULL,
    note        VARCHAR(500) NULL,
    status      VARCHAR(16)  NOT NULL,              -- open | collected | declined | cancelled
    response    VARCHAR(500) NULL,
    open_key    VARCHAR(200) NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL,
    resolved_at DATETIME     NULL,
    resolved_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_requests_uuid (uuid),
    UNIQUE KEY uq_exam_requests_open (open_key),
    KEY ix_exam_requests_exam (provider_id, exam_code, status),
    CONSTRAINT fk_exam_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_exam_requests_provider FOREIGN KEY (provider_id) REFERENCES providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
