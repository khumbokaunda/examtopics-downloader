-- Institution question banks. Separate from the certification library in every way: its own
-- tables, owned by one institution, never mixed with collected data.
--
-- A question's wording, choices, answer and marks live in question_versions, one row per saved
-- edit, never changed once written. bank_questions points at its latest version (what authors
-- are working on, with its review status) and at its published version (what assessments use).
-- Editing a published question writes a new draft version; the published one stays in use until
-- the new one is approved and published. An assessment that used a version keeps using it.
CREATE TABLE IF NOT EXISTS question_banks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    name           VARCHAR(160) NOT NULL,
    subject        VARCHAR(120) NULL,
    description    VARCHAR(1000) NULL,
    programme_id   INT UNSIGNED NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, archived
    created_by     INT UNSIGNED NULL,                     -- a membership
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_question_banks_uuid (uuid),
    UNIQUE KEY uq_question_banks_name (institution_id, name),
    UNIQUE KEY uq_question_banks_tenant (id, institution_id),
    KEY ix_question_banks_programme (programme_id, institution_id),
    KEY ix_question_banks_creator (created_by, institution_id),
    CONSTRAINT fk_question_banks_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_banks_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People besides its creator who may write in a bank.
CREATE TABLE IF NOT EXISTS question_bank_editors (
    bank_id        INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    added_at       DATETIME     NOT NULL,
    PRIMARY KEY (bank_id, membership_id),
    KEY ix_question_bank_editors_bank (bank_id, institution_id),
    KEY ix_question_bank_editors_member (membership_id, institution_id),
    CONSTRAINT fk_question_bank_editors_bank FOREIGN KEY (bank_id, institution_id) REFERENCES question_banks (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_question_bank_editors_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Topics of a bank, with one level of subtopics. parent_key is the parent's id, or 0 for a
-- topic, so the unique key (bank, parent_key, name) works: a unique index holds any number of
-- NULLs. It is an ordinary column written by the application, never generated.
CREATE TABLE IF NOT EXISTS bank_topics (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    bank_id        INT UNSIGNED NOT NULL,
    parent_id      INT UNSIGNED NULL,
    parent_key     INT UNSIGNED NOT NULL DEFAULT 0,
    name           VARCHAR(120) NOT NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_topics_uuid (uuid),
    UNIQUE KEY uq_bank_topics_name (bank_id, parent_key, name),
    UNIQUE KEY uq_bank_topics_tenant (id, institution_id),
    KEY ix_bank_topics_bank (bank_id, institution_id),
    KEY ix_bank_topics_parent (parent_id, institution_id),
    CONSTRAINT fk_bank_topics_bank FOREIGN KEY (bank_id, institution_id) REFERENCES question_banks (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_bank_topics_parent FOREIGN KEY (parent_id, institution_id) REFERENCES bank_topics (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_questions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)     NOT NULL,
    institution_id    INT UNSIGNED NOT NULL,
    bank_id           INT UNSIGNED NOT NULL,
    status            VARCHAR(16)  NOT NULL DEFAULT 'draft', -- of the latest version: draft, review, approved, published; or archived
    latest_version    INT UNSIGNED NOT NULL DEFAULT 1,
    published_version INT UNSIGNED NULL,                    -- the version assessments use; NULL until first published
    review_note       VARCHAR(1000) NULL,                   -- why it was sent back for changes, until it is next submitted
    created_by        INT UNSIGNED NULL,                    -- a membership
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_questions_uuid (uuid),
    UNIQUE KEY uq_bank_questions_tenant (id, institution_id),
    KEY ix_bank_questions_bank (bank_id, institution_id, status),
    CONSTRAINT fk_bank_questions_bank FOREIGN KEY (bank_id, institution_id) REFERENCES question_banks (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_versions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    question_id    INT UNSIGNED NOT NULL,
    version        INT UNSIGNED NOT NULL,
    type           VARCHAR(16)  NOT NULL,                 -- single, multiple, true_false
    text           MEDIUMTEXT   NOT NULL,
    choices        MEDIUMTEXT   NOT NULL,                 -- JSON list of {key, text, correct}, in the order written
    images         TEXT         NULL,                     -- JSON list of uploaded picture addresses
    marks          DECIMAL(6,2) NOT NULL,
    partial_credit TINYINT(1)   NOT NULL DEFAULT 0,       -- multiple select: marks in proportion to right picks
    difficulty     VARCHAR(8)   NOT NULL,                 -- easy, medium, hard
    topic_id       INT UNSIGNED NULL,
    explanation    TEXT         NULL,
    reference      VARCHAR(1000) NULL,
    created_by     INT UNSIGNED NULL,                     -- a membership
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_question_versions_number (question_id, version),
    UNIQUE KEY uq_question_versions_tenant (id, institution_id),
    KEY ix_question_versions_question (question_id, institution_id),
    KEY ix_question_versions_topic (topic_id, institution_id),
    CONSTRAINT fk_question_versions_question FOREIGN KEY (question_id, institution_id) REFERENCES bank_questions (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_question_versions_topic FOREIGN KEY (topic_id, institution_id) REFERENCES bank_topics (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
