-- A course's gradebook: what is graded (grade_items), marks entered by hand for work done off
-- the platform (grade_marks), and the course grade's overrides (grade_overrides). An item is
-- either an online assessment, whose mark is its published result, or an offline item (a paper
-- test, a practical) with marks typed or uploaded.
--
-- Marks and overrides are never changed in place: a new row is written and the one it replaces
-- is marked superseded, so who changed what, when, and why stays on record.
CREATE TABLE IF NOT EXISTS grade_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    assessment_id  INT UNSIGNED NULL,                  -- an online item; NULL for an offline one
    title          VARCHAR(160) NOT NULL,
    max_marks      DECIMAL(9,2) NOT NULL DEFAULT 100,  -- offline items; an online item is out of 100 (its percent)
    weight         DECIMAL(6,2) NOT NULL,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    released_at    DATETIME     NULL,                  -- offline marks shown to learners from then
    removed_at     DATETIME     NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade_items_uuid (uuid),
    UNIQUE KEY uq_grade_items_tenant (id, institution_id),
    KEY ix_grade_items_course (course_id, institution_id, removed_at, position),
    KEY ix_grade_items_assessment (assessment_id, institution_id),
    CONSTRAINT fk_grade_items_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_grade_items_assessment FOREIGN KEY (assessment_id, institution_id) REFERENCES assessments (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grade_marks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    marks          DECIMAL(9,2) NULL,                  -- NULL: cleared
    feedback       TEXT         NULL,
    entered_by     INT UNSIGNED NULL,                  -- a membership
    entered_at     DATETIME     NOT NULL,
    superseded_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_grade_marks_item (item_id, institution_id, superseded_at),
    KEY ix_grade_marks_member (membership_id, institution_id),
    CONSTRAINT fk_grade_marks_item FOREIGN KEY (item_id, institution_id) REFERENCES grade_items (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_grade_marks_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grade_overrides (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    percent        DECIMAL(5,2) NULL,                  -- NULL: the override was taken away
    reason         VARCHAR(500) NOT NULL,
    set_by         INT UNSIGNED NULL,                  -- a membership
    set_at         DATETIME     NOT NULL,
    superseded_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_grade_overrides_course (course_id, institution_id, superseded_at),
    CONSTRAINT fk_grade_overrides_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_grade_overrides_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The course's pass mark, and when its grades were made final.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'pass_percent');
SET @s := IF(@c = 0, 'ALTER TABLE courses ADD COLUMN pass_percent DECIMAL(5,2) NULL AFTER ends_on, ADD COLUMN grades_final_at DATETIME NULL AFTER status, ADD COLUMN grades_final_by INT UNSIGNED NULL AFTER grades_final_at', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
