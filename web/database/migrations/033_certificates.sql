-- Certificates of demonstrated examination readiness, earned on a mock exam of the
-- certification library. A certificate is never deleted: revoking one marks it revoked, with
-- who did it, when and why, and the verification page then says so. The verification token is
-- random (unguessable) and printed on the certificate as a link; the number is for people.
CREATE TABLE IF NOT EXISTS certificates (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    number         VARCHAR(24)  NOT NULL,                 -- such as EL-2026-000042
    token          CHAR(32)     NOT NULL,                 -- the public verification link: /verify/<token>
    kind           VARCHAR(16)  NOT NULL DEFAULT 'readiness',
    user_id        INT UNSIGNED NULL,
    mock_id        INT UNSIGNED NULL,
    exam_id        INT UNSIGNED NULL,
    holder_name    VARCHAR(190) NOT NULL,                 -- as it was when issued
    exam_title     VARCHAR(255) NOT NULL,                 -- as it was when issued
    percent        DECIMAL(5,2) NOT NULL,
    questions      INT UNSIGNED NOT NULL,
    level          VARCHAR(24)  NOT NULL,
    issued_at      DATETIME     NOT NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'issued', -- issued, revoked
    revoked_at     DATETIME     NULL,
    revoked_by     INT UNSIGNED NULL,
    revoke_reason  VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificates_uuid (uuid),
    UNIQUE KEY uq_certificates_number (number),
    UNIQUE KEY uq_certificates_token (token),
    UNIQUE KEY uq_certificates_mock (mock_id),
    KEY ix_certificates_user (user_id, issued_at),
    CONSTRAINT fk_certificates_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_certificates_mock FOREIGN KEY (mock_id) REFERENCES mock_exams (id) ON DELETE SET NULL,
    CONSTRAINT fk_certificates_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE SET NULL,
    CONSTRAINT fk_certificates_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
