-- Institution assessments: what is asked, how it is marked, when it is open and who takes it.
-- Questions come from the institution's question banks in two ways that can be combined: chosen
-- one by one (assessment_items), or described by a blueprint (assessment_blueprints: so many
-- questions from this bank, topic and difficulty), drawn for each candidate.
--
-- An assessment is edited as a draft. Publishing checks it and fixes every chosen question to the
-- exact question version published at that moment (assessment_items.question_version_id); a
-- blueprint draws only published versions. Once candidates sit it (phase 4), each one's drawn
-- form is stored with the version ids it used, so nothing about a sitting changes afterwards.
CREATE TABLE IF NOT EXISTS assessments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)     NOT NULL,
    institution_id    INT UNSIGNED NOT NULL,
    title             VARCHAR(200) NOT NULL,
    description       VARCHAR(2000) NULL,
    type              VARCHAR(16)  NOT NULL,          -- practice, diagnostic, readiness, formative, summative, formal
    instructions      TEXT         NULL,
    duration_minutes  INT UNSIGNED NOT NULL DEFAULT 60,
    pass_percent      DECIMAL(5,2) NULL,
    max_attempts      INT UNSIGNED NOT NULL DEFAULT 1,
    opens_at          DATETIME     NULL,              -- UTC; NULL opens on publishing
    closes_at         DATETIME     NULL,              -- UTC; NULL stays open until closed by hand
    shuffle_questions TINYINT(1)   NOT NULL DEFAULT 1,
    shuffle_choices   TINYINT(1)   NOT NULL DEFAULT 1,
    negative_marking  DECIMAL(4,2) NOT NULL DEFAULT 0, -- share of a question's marks taken off for a wrong answer, 0 to 1
    review_policy     VARCHAR(16)  NOT NULL DEFAULT 'score', -- after the result is published: none, score, answers
    status            VARCHAR(16)  NOT NULL DEFAULT 'draft', -- draft, published, closed, archived
    created_by        INT UNSIGNED NULL,              -- a membership
    published_by      INT UNSIGNED NULL,
    published_at      DATETIME     NULL,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessments_uuid (uuid),
    UNIQUE KEY uq_assessments_tenant (id, institution_id),
    KEY ix_assessments_status (institution_id, status),
    CONSTRAINT fk_assessments_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_items (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id      INT UNSIGNED NOT NULL,
    assessment_id       INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    question_version_id INT UNSIGNED NULL,            -- fixed when the assessment is published
    position            INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_items_question (assessment_id, question_id),
    KEY ix_assessment_items_assessment (assessment_id, institution_id),
    KEY ix_assessment_items_question (question_id, institution_id),
    KEY ix_assessment_items_version (question_version_id, institution_id),
    CONSTRAINT fk_assessment_items_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_items_question FOREIGN KEY (question_id, institution_id) REFERENCES bank_questions (id, institution_id),
    CONSTRAINT fk_assessment_items_version FOREIGN KEY (question_version_id, institution_id) REFERENCES question_versions (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- topic_key and difficulty_key are '' for "any", so the unique key works without NULLs.
CREATE TABLE IF NOT EXISTS assessment_blueprints (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    assessment_id  INT UNSIGNED NOT NULL,
    bank_id        INT UNSIGNED NOT NULL,
    topic_id       INT UNSIGNED NULL,                 -- a topic includes its subtopics
    difficulty     VARCHAR(8)   NOT NULL DEFAULT '',  -- '' for any
    question_count INT UNSIGNED NOT NULL,
    position       INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY ix_assessment_blueprints_assessment (assessment_id, institution_id),
    KEY ix_assessment_blueprints_bank (bank_id, institution_id),
    KEY ix_assessment_blueprints_topic (topic_id, institution_id),
    CONSTRAINT fk_assessment_blueprints_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_blueprints_bank FOREIGN KEY (bank_id, institution_id) REFERENCES question_banks (id, institution_id),
    CONSTRAINT fk_assessment_blueprints_topic FOREIGN KEY (topic_id, institution_id) REFERENCES bank_topics (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who takes it: whole classes (their students at the time of sitting) and named students.
CREATE TABLE IF NOT EXISTS assessment_assignments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    assessment_id  INT UNSIGNED NOT NULL,
    class_id       INT UNSIGNED NULL,
    membership_id  INT UNSIGNED NULL,
    assigned_by    INT UNSIGNED NULL,                 -- a membership
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_assessment_assignments_assessment (assessment_id, institution_id),
    KEY ix_assessment_assignments_class (class_id, institution_id),
    KEY ix_assessment_assignments_member (membership_id, institution_id),
    CONSTRAINT fk_assessment_assignments_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_assignments_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_assignments_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
