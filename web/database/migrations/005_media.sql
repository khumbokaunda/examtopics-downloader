-- Images that collected questions refer to. The collection tool downloads them and the
-- worker keeps each one once under storage/media/<first two hex digits>/<sha256>.<ext>,
-- named by its content. One row per image address.
CREATE TABLE media (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    url_hash   CHAR(64)      NOT NULL,             -- SHA-256 of the address (a fixed-length key)
    url        VARCHAR(2048) NOT NULL,
    status     VARCHAR(16)   NOT NULL,             -- 'saved' | 'failed' (tried again) | 'skipped' (never)
    sha256     CHAR(64)      NULL,                 -- content hash, names the file
    mime       VARCHAR(32)   NULL,
    bytes      INT UNSIGNED  NULL,
    error      VARCHAR(500)  NULL,
    attempts   INT UNSIGNED  NOT NULL DEFAULT 1,
    updated_at DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_url (url_hash),
    KEY ix_media_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
