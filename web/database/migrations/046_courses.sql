-- A course offering: one class being taught something, between two dates. It organises work
-- around what exists already: its learners are the class's students (class_enrolments), its
-- teachers the class's instructors plus an optional lead, and assessments link to it with an
-- optional course_id. A module is an optional link, for institutions that have programmes.
CREATE TABLE IF NOT EXISTS courses (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid               CHAR(36)     NOT NULL,
    institution_id     INT UNSIGNED NOT NULL,
    class_id           INT UNSIGNED NOT NULL,
    module_id          INT UNSIGNED NULL,
    title              VARCHAR(160) NOT NULL,
    summary            VARCHAR(1000) NULL,
    starts_on          DATE         NULL,
    ends_on            DATE         NULL,
    lead_membership_id INT UNSIGNED NULL,
    status             VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, archived
    created_by         INT UNSIGNED NULL,                      -- a membership
    created_at         DATETIME     NOT NULL,
    updated_at         DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_uuid (uuid),
    UNIQUE KEY uq_courses_tenant (id, institution_id),
    KEY ix_courses_class (class_id, institution_id, status),
    KEY ix_courses_module (module_id, institution_id),
    KEY ix_courses_lead (lead_membership_id, institution_id),
    CONSTRAINT fk_courses_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id),
    CONSTRAINT fk_courses_module FOREIGN KEY (module_id, institution_id) REFERENCES programme_modules (id, institution_id),
    CONSTRAINT fk_courses_lead FOREIGN KEY (lead_membership_id, institution_id) REFERENCES institution_memberships (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notices to a course's learners. Kept as posted: a notice is removed, never edited.
CREATE TABLE IF NOT EXISTS course_announcements (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    title          VARCHAR(160) NOT NULL,
    body           TEXT         NOT NULL,
    posted_by      INT UNSIGNED NULL,                          -- a membership
    posted_at      DATETIME     NOT NULL,
    removed_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_announcements_uuid (uuid),
    KEY ix_course_announcements_course (course_id, institution_id, posted_at),
    CONSTRAINT fk_course_announcements_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The course an assessment belongs to, if any. Assessments without one work as before.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'course_id');
SET @s := IF(@c = 0, 'ALTER TABLE assessments ADD COLUMN course_id INT UNSIGNED NULL AFTER module_id, ADD KEY ix_assessments_course (course_id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments' AND CONSTRAINT_NAME = 'fk_assessments_course');
SET @s := IF(@c = 0, 'ALTER TABLE assessments ADD CONSTRAINT fk_assessments_course FOREIGN KEY (course_id, institution_id) REFERENCES courses (id, institution_id)', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
