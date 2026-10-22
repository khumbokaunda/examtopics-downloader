-- Accounts are addressed by a random id in URLs (/users/<id>, /progress/people/<id>) instead
-- of their username, so a username stays out of browser history, server logs and shared
-- screens. NULL is allowed so that a backup made before this restores; the application gives
-- any account without one an id (when it is created, and after a restore).
ALTER TABLE users ADD COLUMN uuid CHAR(36) NULL AFTER id;
-- Existing accounts get a random id in the same (version 4) form the application makes:
-- 32 random hex digits first, then set out as a UUID. The database's UUID() alone would give
-- ids that differ by a few digits and show the order accounts were made.
UPDATE users SET uuid = MD5(CONCAT(UUID(), RAND(), id)) WHERE uuid IS NULL;
UPDATE users SET uuid = CONCAT(SUBSTR(uuid, 1, 8), '-', SUBSTR(uuid, 9, 4), '-4', SUBSTR(uuid, 14, 3), '-',
        ELT(1 + FLOOR(RAND() * 4), '8', '9', 'a', 'b'), SUBSTR(uuid, 18, 3), '-', SUBSTR(uuid, 21, 12))
    WHERE CHAR_LENGTH(uuid) = 32;
ALTER TABLE users ADD UNIQUE KEY uq_users_uuid (uuid);
