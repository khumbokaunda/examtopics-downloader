-- A large exam's PDF is made in parts, each a file of its own (questions 1 to 465, 466 to
-- 930, ...), so no build needs the memory of one huge PDF. JSON list of
-- {file, first, last, size, checksum}; NULL for a document in one file.
ALTER TABLE documents
    ADD COLUMN parts TEXT NULL AFTER checksum;
