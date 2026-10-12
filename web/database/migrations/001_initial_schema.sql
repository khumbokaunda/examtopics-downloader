-- Exam Library initial schema (MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4).
-- Question content lives in JSON cache files; these tables hold metadata only and can be
-- rebuilt from cache manifests with bin/rebuild-index.php.

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL,
    last_login_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttling and the security audit trail.
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)  NOT NULL,
    username     VARCHAR(64)  NULL,
    succeeded    TINYINT(1)   NOT NULL,
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_login_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE providers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(64)  NOT NULL,
    name       VARCHAR(128) NULL,
    source     VARCHAR(16)  NOT NULL,             -- 'discovered' | 'manual'
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_providers_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exams (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id            INT UNSIGNED NOT NULL,
    code                   VARCHAR(128) NOT NULL,
    name                   VARCHAR(255) NULL,
    reported_count         INT UNSIGNED NULL,        -- from the latest inventory
    in_inventory           TINYINT(1)   NOT NULL DEFAULT 0,
    inventory_refreshed_at DATETIME     NULL,
    latest_version_id      INT UNSIGNED NULL,        -- latest COMPLETE cache version
    created_at             DATETIME     NOT NULL,
    updated_at             DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exams_provider_code (provider_id, code),
    CONSTRAINT fk_exams_provider FOREIGN KEY (provider_id) REFERENCES providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type             VARCHAR(16)  NOT NULL,          -- 'collect' | 'inventory' | 'providers'
    status           VARCHAR(16)  NOT NULL,          -- queued | running | completed | failed | cancelled
    -- Non-NULL while queued/running. UNIQUE makes "one active job per slot" atomic:
    -- 'collect' (one collection app-wide), 'inventory:<slug>', 'providers'.
    active_slot      VARCHAR(80)  NULL,
    provider_id      INT UNSIGNED NULL,
    exam_code        VARCHAR(128) NULL,
    options          TEXT         NULL,              -- JSON
    phase            VARCHAR(32)  NULL,
    progress_done    INT UNSIGNED NULL,
    progress_total   INT UNSIGNED NULL,
    message          VARCHAR(500) NULL,
    result_status    VARCHAR(16)  NULL,              -- complete | partial (collect jobs)
    error_summary    TEXT         NULL,
    exit_code        INT          NULL,
    worker_pid       INT UNSIGNED NULL,
    child_pid        INT UNSIGNED NULL,
    cancel_requested TINYINT(1)   NOT NULL DEFAULT 0,
    cache_version_id INT UNSIGNED NULL,
    queued_at        DATETIME     NOT NULL,
    started_at       DATETIME     NULL,
    finished_at      DATETIME     NULL,
    heartbeat_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jobs_active_slot (active_slot),
    KEY ix_jobs_status (status, queued_at),
    KEY ix_jobs_exam (provider_id, exam_code),
    CONSTRAINT fk_jobs_provider FOREIGN KEY (provider_id) REFERENCES providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_refreshes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id     INT UNSIGNED NOT NULL,
    job_id          INT UNSIGNED NULL,
    status          VARCHAR(16)  NOT NULL,           -- complete | partial | failed
    exam_count      INT UNSIGNED NULL,
    listing_pages   INT UNSIGNED NULL,
    failed_pages    INT UNSIGNED NULL,
    error           TEXT         NULL,
    finished_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_inventory_provider (provider_id, finished_at),
    CONSTRAINT fk_inventory_provider FOREIGN KEY (provider_id) REFERENCES providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cache_versions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id         INT UNSIGNED NOT NULL,
    version         VARCHAR(40)  NOT NULL,
    status          VARCHAR(16)  NOT NULL,           -- complete | partial
    source          VARCHAR(16)  NOT NULL,           -- live | github-cache
    collected_at    DATETIME     NOT NULL,
    published_at    DATETIME     NOT NULL,
    reported_count  INT UNSIGNED NULL,
    collected_count INT UNSIGNED NOT NULL,
    checksum        CHAR(64)     NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    schema_version  INT UNSIGNED NOT NULL,
    tool_revision   VARCHAR(64)  NULL,
    warnings        TEXT         NULL,               -- JSON array
    job_id          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_versions_exam_version (exam_id, version),
    KEY ix_versions_exam_time (exam_id, collected_at),
    CONSTRAINT fk_versions_exam FOREIGN KEY (exam_id) REFERENCES exams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cache_version_id INT UNSIGNED NOT NULL,
    format           VARCHAR(8)   NOT NULL,          -- md | pdf
    variant          VARCHAR(32)  NOT NULL,
    options_hash     CHAR(64)     NOT NULL,          -- covers options + renderer version
    options          TEXT         NOT NULL,          -- JSON
    status           VARCHAR(16)  NOT NULL,          -- ready | failed
    rel_path         VARCHAR(255) NULL,              -- relative to storage/documents
    filename         VARCHAR(255) NULL,              -- download name
    size_bytes       BIGINT UNSIGNED NULL,
    checksum         CHAR(64)     NULL,
    error            TEXT         NULL,
    generated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_variant (cache_version_id, format, variant, options_hash),
    CONSTRAINT fk_documents_version FOREIGN KEY (cache_version_id) REFERENCES cache_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name       VARCHAR(64) NOT NULL,
    value      TEXT        NOT NULL,
    updated_at DATETIME    NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
