-- Jobs and documents are addressed by a random UUID in URLs instead of their row number.
-- Existing rows get one from UUID(); new rows get a random (version 4) UUID from the app.
ALTER TABLE jobs ADD COLUMN uuid CHAR(36) NULL AFTER id;
UPDATE jobs SET uuid = UUID() WHERE uuid IS NULL;
ALTER TABLE jobs MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_jobs_uuid (uuid);
ALTER TABLE documents ADD COLUMN uuid CHAR(36) NULL AFTER id;
UPDATE documents SET uuid = UUID() WHERE uuid IS NULL;
ALTER TABLE documents MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_documents_uuid (uuid);
