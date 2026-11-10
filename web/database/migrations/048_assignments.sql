-- Course materials, assignments with file submissions, and their marking.
--
-- Files (materials and submissions) are stored outside the web root under
-- storage/institution_documents/<institution>/coursework/, which backups already include, and are
-- checked by their content, not their name. Nothing is changed in place: a resubmission or a
-- new mark supersedes the row before it, which is kept.
CREATE TABLE IF NOT EXISTS course_materials (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    heading        VARCHAR(120) NOT NULL DEFAULT '',  -- materials are grouped under headings
    title          VARCHAR(160) NOT NULL,
    kind           VARCHAR(8)   NOT NULL,             -- file, link
    url            VARCHAR(1000) NULL,                -- a link
    filename       VARCHAR(200) NULL,                 -- a file
    mime           VARCHAR(120) NULL,
    size           INT UNSIGNED NULL,
    sha256         CHAR(64)     NULL,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    removed_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_materials_uuid (uuid),
    KEY ix_course_materials_course (course_id, institution_id, removed_at),
    CONSTRAINT fk_course_materials_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    title          VARCHAR(160) NOT NULL,
    instructions   TEXT         NULL,
    due_at         DATETIME     NULL,                 -- UTC
    late_policy    VARCHAR(8)   NOT NULL DEFAULT 'accept', -- accept (marked late), refuse
    max_marks      DECIMAL(9,2) NOT NULL DEFAULT 100,
    file_kinds     VARCHAR(120) NOT NULL DEFAULT 'pdf', -- comma-separated: pdf, word, spreadsheet, slides, image, text, zip
    max_files      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    max_mb         TINYINT UNSIGNED NOT NULL DEFAULT 10,
    status         VARCHAR(16)  NOT NULL DEFAULT 'draft', -- draft, published, closed
    released_at    DATETIME     NULL,                 -- marks shown to learners from then
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignments_uuid (uuid),
    UNIQUE KEY uq_assignments_tenant (id, institution_id),
    KEY ix_assignments_course (course_id, institution_id, status),
    CONSTRAINT fk_assignments_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An optional rubric: criteria whose marks add up to the assignment's mark.
CREATE TABLE IF NOT EXISTS assignment_criteria (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    assignment_id  INT UNSIGNED NOT NULL,
    title          VARCHAR(160) NOT NULL,
    description    VARCHAR(500) NULL,
    max_marks      DECIMAL(9,2) NOT NULL,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_assignment_criteria_assignment (assignment_id, institution_id),
    CONSTRAINT fk_assignment_criteria_assignment FOREIGN KEY (assignment_id, institution_id) REFERENCES assignments (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_submissions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    assignment_id  INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    note           VARCHAR(2000) NULL,
    late           TINYINT(1)   NOT NULL DEFAULT 0,
    submitted_at   DATETIME     NOT NULL,
    superseded_at  DATETIME     NULL,                 -- a later submission replaced it
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_submissions_uuid (uuid),
    UNIQUE KEY uq_assignment_submissions_tenant (id, institution_id),
    KEY ix_assignment_submissions_assignment (assignment_id, institution_id, superseded_at),
    KEY ix_assignment_submissions_member (membership_id, institution_id),
    CONSTRAINT fk_assignment_submissions_assignment FOREIGN KEY (assignment_id, institution_id) REFERENCES assignments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_submissions_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_files (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    submission_id  INT UNSIGNED NOT NULL,
    filename       VARCHAR(200) NOT NULL,
    mime           VARCHAR(120) NOT NULL,
    size           INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_files_uuid (uuid),
    KEY ix_submission_files_submission (submission_id, institution_id),
    CONSTRAINT fk_submission_files_submission FOREIGN KEY (submission_id, institution_id) REFERENCES assignment_submissions (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_marks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    assignment_id  INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    marks          DECIMAL(9,2) NULL,
    criteria       TEXT         NULL,                 -- JSON {criterion id: marks} when there is a rubric
    feedback       TEXT         NULL,
    entered_by     INT UNSIGNED NULL,
    entered_at     DATETIME     NOT NULL,
    superseded_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_assignment_marks_assignment (assignment_id, institution_id, superseded_at),
    CONSTRAINT fk_assignment_marks_assignment FOREIGN KEY (assignment_id, institution_id) REFERENCES assignments (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_marks_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An assignment can be a grade item too.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_items' AND COLUMN_NAME = 'assignment_id');
SET @s := IF(@c = 0, 'ALTER TABLE grade_items ADD COLUMN assignment_id INT UNSIGNED NULL AFTER assessment_id, ADD KEY ix_grade_items_assignment (assignment_id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_items' AND CONSTRAINT_NAME = 'fk_grade_items_assignment');
SET @s := IF(@c = 0, 'ALTER TABLE grade_items ADD CONSTRAINT fk_grade_items_assignment FOREIGN KEY (assignment_id, institution_id) REFERENCES assignments (id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
