-- assignment_test_migration.sql
--
-- Local-only additive migration for the combined Assignment/Test module.
-- Target verified during Stage 0/1: local MariaDB 10.4, database kwasulcs_lcs.
--
-- Important design notes:
--   * assignment_test_submissions has one persistent row per item/student.
--   * submission_attempt increments only when a final submission or
--     resubmission is made; draft file changes do not create new rows.
--   * file rows retain version/current metadata and a SHA-256 checksum so
--     later code can preserve and audit the exact bytes originally uploaded.
--   * plagiarism fields are nullable schema reservations only. This migration
--     does not implement or trigger plagiarism detection.
--   * chat_messages.assignment_test_id is deliberately non-unique because
--     multiple present/future system messages may reference the same item.
--   * audit metadata is stored as text containing application-generated JSON
--     to remain compatible with this project's MariaDB/MySQL deployments.

CREATE TABLE assignment_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    created_by INT NOT NULL,
    type ENUM('assignment', 'test') NOT NULL,
    title VARCHAR(255) NOT NULL,
    instructions MEDIUMTEXT NOT NULL,
    due_at DATETIME NOT NULL,
    total_marks DECIMAL(8,2) UNSIGNED NOT NULL,
    allowed_formats VARCHAR(255) NOT NULL DEFAULT 'pdf,doc,docx,ppt,pptx,zip',
    max_file_size INT UNSIGNED NOT NULL DEFAULT 20971520,
    allow_late_submissions TINYINT(1) NOT NULL DEFAULT 0,
    allow_resubmission TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'closed') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assignment_tests_course_status_due (course_id, status, due_at),
    INDEX idx_assignment_tests_created_by (created_by),
    CONSTRAINT fk_assignment_tests_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_tests_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assignment_test_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_test_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('draft', 'submitted', 'reviewed') NOT NULL DEFAULT 'draft',
    submission_attempt INT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at DATETIME NULL,
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    note_text MEDIUMTEXT NULL,
    score DECIMAL(8,2) UNSIGNED NULL,
    feedback MEDIUMTEXT NULL,
    plagiarism_status VARCHAR(32) NULL,
    plagiarism_score DECIMAL(5,2) UNSIGNED NULL,
    plagiarism_report MEDIUMTEXT NULL,
    plagiarism_checked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assignment_test_student (assignment_test_id, student_id),
    INDEX idx_assignment_test_submissions_student (student_id),
    INDEX idx_assignment_test_submissions_status (assignment_test_id, status, submitted_at),
    CONSTRAINT fk_assignment_test_submissions_item
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_test_submissions_student
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assignment_test_submission_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_extension VARCHAR(20) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assignment_test_files_current (submission_id, is_current),
    INDEX idx_assignment_test_files_version (submission_id, version_number),
    CONSTRAINT fk_assignment_test_submission_files_submission
        FOREIGN KEY (submission_id) REFERENCES assignment_test_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assignment_test_audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    assignment_test_id INT NOT NULL,
    submission_id INT NULL,
    actor_id INT NULL,
    event_type VARCHAR(64) NOT NULL,
    metadata_json LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assignment_test_audit_item_time (assignment_test_id, created_at),
    INDEX idx_assignment_test_audit_submission_time (submission_id, created_at),
    INDEX idx_assignment_test_audit_actor_time (actor_id, created_at),
    CONSTRAINT fk_assignment_test_audit_item
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_test_audit_submission
        FOREIGN KEY (submission_id) REFERENCES assignment_test_submissions(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_test_audit_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chat_messages
    ADD COLUMN assignment_test_id INT NULL AFTER content,
    ADD INDEX idx_chat_messages_assignment_test (assignment_test_id),
    ADD CONSTRAINT fk_chat_messages_assignment_test
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE SET NULL;

-- Rollback (destructive; use only if all Assignment/Test data can be removed):
--
-- ALTER TABLE chat_messages
--     DROP FOREIGN KEY fk_chat_messages_assignment_test,
--     DROP INDEX idx_chat_messages_assignment_test,
--     DROP COLUMN assignment_test_id;
-- DROP TABLE assignment_test_audit_logs;
-- DROP TABLE assignment_test_submission_files;
-- DROP TABLE assignment_test_submissions;
-- DROP TABLE assignment_tests;
