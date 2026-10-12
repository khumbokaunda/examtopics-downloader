-- PDFs are built by a background process, which reports how far it has got here, so the
-- page can show progress instead of a request that times out.
ALTER TABLE documents
    ADD COLUMN progress_done  INT UNSIGNED NULL AFTER error,
    ADD COLUMN progress_total INT UNSIGNED NULL AFTER progress_done,
    ADD COLUMN builder_pid    INT UNSIGNED NULL AFTER progress_total;
