-- The integrity page's figures are worked out by a background process (bin/integrity.php). An
-- exam whose data file cannot be read is recorded with the reason, so it is shown as unreadable
-- rather than tried again on every visit; it is tried again once its data file changes.
-- images_known keeps how many of the exam's images are in each download status, counted when
-- the media table last looked like media_mark, so a visit counts again only after a download.
ALTER TABLE exam_checks
    ADD COLUMN error VARCHAR(1000) NULL,
    ADD COLUMN images_known VARCHAR(255) NULL,
    ADD COLUMN media_mark CHAR(32) NULL;
