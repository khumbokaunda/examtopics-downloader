-- An exam's objectives (its domains, as the certification's provider lists them), and which
-- objective each question covers. Library questions live in files, not in the database, so the
-- mapping is an overlay keyed like the others, by (exam_id, question_key).
CREATE TABLE IF NOT EXISTS exam_objectives (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid       CHAR(36)     NOT NULL,
    exam_id    INT UNSIGNED NOT NULL,
    code       VARCHAR(32)  NULL,                   -- such as "1.0" or "Domain 2"
    name       VARCHAR(160) NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_objectives_uuid (uuid),
    KEY ix_exam_objectives_exam (exam_id, position),
    CONSTRAINT fk_exam_objectives_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_objectives (
    exam_id      INT UNSIGNED NOT NULL,
    question_key VARCHAR(64)  NOT NULL,
    objective_id INT UNSIGNED NOT NULL,
    set_by       INT UNSIGNED NULL,
    set_at       DATETIME     NOT NULL,
    PRIMARY KEY (exam_id, question_key),
    KEY ix_question_objectives_objective (objective_id),
    CONSTRAINT fk_question_objectives_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_objectives_objective FOREIGN KEY (objective_id) REFERENCES exam_objectives (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
