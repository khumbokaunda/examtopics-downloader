-- How much memory a background PDF build was using at its last progress update, in MB. When
-- the operating system stops a build for using too much memory, nothing else is recorded,
-- so this is what lets the page say why the build stopped.
ALTER TABLE documents
    ADD COLUMN builder_memory_mb INT UNSIGNED NULL AFTER builder_pid;
