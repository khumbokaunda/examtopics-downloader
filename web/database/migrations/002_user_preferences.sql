-- Per-user appearance preferences (theme, accent, density, surface, motion) as a small JSON
-- object. TEXT rather than JSON so the schema stays portable across MySQL and MariaDB versions.
ALTER TABLE users ADD COLUMN preferences TEXT NULL AFTER last_login_at;
