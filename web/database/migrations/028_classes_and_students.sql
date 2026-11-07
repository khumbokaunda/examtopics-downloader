-- An institution's structure (departments, programmes, classes), its student records, and who is
-- in which class. Every table carries the institution's id, and every reference to another
-- institution-owned row is a composite foreign key on (id, institution_id), so the database
-- itself refuses a class, enrolment or record that points into another institution.
CREATE TABLE IF NOT EXISTS institution_departments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    name           VARCHAR(120) NOT NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_departments_uuid (uuid),
    UNIQUE KEY uq_institution_departments_name (institution_id, name),
    UNIQUE KEY uq_institution_departments_tenant (id, institution_id),
    CONSTRAINT fk_institution_departments_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_programmes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    department_id  INT UNSIGNED NULL,
    name           VARCHAR(160) NOT NULL,
    code           VARCHAR(32)  NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_programmes_uuid (uuid),
    UNIQUE KEY uq_institution_programmes_name (institution_id, name),
    UNIQUE KEY uq_institution_programmes_tenant (id, institution_id),
    KEY ix_institution_programmes_department (department_id, institution_id),
    CONSTRAINT fk_institution_programmes_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_programmes_department FOREIGN KEY (department_id, institution_id) REFERENCES institution_departments (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A class or cohort. No intake is stored as the empty string, never NULL, so the unique key
-- (institution, name, intake) stops the same class being created twice.
CREATE TABLE IF NOT EXISTS institution_classes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    programme_id   INT UNSIGNED NULL,
    name           VARCHAR(120) NOT NULL,
    intake         VARCHAR(40)  NOT NULL DEFAULT '',   -- academic year or intake, such as "2026" or "January 2027"
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, archived
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_classes_uuid (uuid),
    UNIQUE KEY uq_institution_classes_name (institution_id, name, intake),
    UNIQUE KEY uq_institution_classes_tenant (id, institution_id),
    KEY ix_institution_classes_programme (programme_id, institution_id),
    CONSTRAINT fk_institution_classes_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_classes_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_instructors (
    class_id       INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    assigned_at    DATETIME     NOT NULL,
    PRIMARY KEY (class_id, membership_id),
    KEY ix_class_instructors_member (membership_id, institution_id),
    KEY ix_class_instructors_class (class_id, institution_id),
    CONSTRAINT fk_class_instructors_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_class_instructors_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per stint in a class. Leaving or moving ends the row (status removed or moved) and it
-- is kept, so what a student did while in a class keeps that context. At most one active row per
-- student and class, enforced by the application.
CREATE TABLE IF NOT EXISTS class_enrolments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id INT UNSIGNED NOT NULL,
    class_id       INT UNSIGNED NOT NULL,
    membership_id  INT UNSIGNED NOT NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, removed, moved
    enrolled_at    DATETIME     NOT NULL,
    enrolled_by    INT UNSIGNED NULL,
    ended_at       DATETIME     NULL,
    ended_by       INT UNSIGNED NULL,
    moved_to       INT UNSIGNED NULL,                     -- the class a move went to
    PRIMARY KEY (id),
    KEY ix_class_enrolments_class (class_id, institution_id, status),
    KEY ix_class_enrolments_member (membership_id, institution_id, status),
    CONSTRAINT fk_class_enrolments_class FOREIGN KEY (class_id, institution_id) REFERENCES institution_classes (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_class_enrolments_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_class_enrolments_enrolled_by FOREIGN KEY (enrolled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_class_enrolments_ended_by FOREIGN KEY (ended_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the institution keeps about a student beyond their account: their institutional number,
-- programme and standing. Names and email addresses stay on the account, never copied here.
CREATE TABLE IF NOT EXISTS institution_students (
    membership_id  INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    student_number VARCHAR(40)  NULL,
    programme_id   INT UNSIGNED NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'active', -- active, suspended, completed, withdrawn
    enrolled_on    DATE         NULL,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (membership_id),
    UNIQUE KEY uq_institution_students_number (institution_id, student_number),
    KEY ix_institution_students_member (membership_id, institution_id),
    KEY ix_institution_students_programme (programme_id, institution_id),
    CONSTRAINT fk_institution_students_member FOREIGN KEY (membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_students_programme FOREIGN KEY (programme_id, institution_id) REFERENCES institution_programmes (id, institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
