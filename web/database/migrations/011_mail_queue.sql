-- Emails about events, sent by bin/send-mail.php with retries. The idempotency key stops the
-- same event being mailed twice.
CREATE TABLE mail_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(191) NOT NULL,
    to_email        VARCHAR(190) NOT NULL,
    to_name         VARCHAR(128) NULL,
    subject         VARCHAR(255) NOT NULL,
    html            MEDIUMTEXT   NOT NULL,             -- the fragment; the letterhead is added when sent
    status          VARCHAR(16)  NOT NULL,             -- queued | sending | sent | failed
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME     NOT NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL,
    sent_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mail_queue_key (idempotency_key),
    KEY ix_mail_queue_due (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
