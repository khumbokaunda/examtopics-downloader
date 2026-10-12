-- The number of exams ExamTopics lists for a provider on its exams index ("Cisco (120 exams)"),
-- recorded by provider discovery. It counts exams, not discussions, so it is shown beside the
-- inventory count rather than in place of it.
ALTER TABLE providers ADD COLUMN listed_exam_count INT UNSIGNED NULL AFTER source;
