-- assignment_test_stage3_migration.sql
-- Local-only workflow integration after Stage 1 and Stage 2 migrations.

ALTER TABLE web_notifications
    ADD COLUMN assignment_test_id INT NULL AFTER event_id,
    ADD COLUMN notification_type VARCHAR(40) NULL AFTER assignment_test_id,
    ADD COLUMN action_url VARCHAR(500) NULL AFTER message,
    ADD INDEX idx_web_notifications_assignment_test (assignment_test_id),
    ADD CONSTRAINT fk_web_notifications_assignment_test
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE SET NULL;

ALTER TABLE assignment_test_submission_files
    ADD COLUMN replaces_file_id INT NULL AFTER submission_id,
    ADD INDEX idx_assignment_test_files_replaces (replaces_file_id),
    ADD CONSTRAINT fk_assignment_test_files_replaces
        FOREIGN KEY (replaces_file_id)
        REFERENCES assignment_test_submission_files(id) ON DELETE SET NULL;

CREATE TABLE assignment_test_notification_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    assignment_test_id INT NOT NULL,
    user_id INT NOT NULL,
    notification_type VARCHAR(40) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assignment_test_notification_job
        (assignment_test_id, user_id, notification_type, scheduled_at),
    INDEX idx_assignment_test_notification_due
        (sent_at, cancelled_at, scheduled_at),
    CONSTRAINT fk_assignment_test_notification_job_item
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_test_notification_job_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assignment_test_submission_receipts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    submission_attempt INT UNSIGNED NOT NULL,
    receipt_code VARCHAR(64) NOT NULL,
    submitted_at DATETIME NOT NULL,
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    note_text_snapshot MEDIUMTEXT NULL,
    file_manifest_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assignment_test_receipt_attempt (submission_id, submission_attempt),
    UNIQUE KEY uniq_assignment_test_receipt_code (receipt_code),
    CONSTRAINT fk_assignment_test_receipt_submission
        FOREIGN KEY (submission_id) REFERENCES assignment_test_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback (destructive for Assignment/Test notification scheduling/history):
-- DROP TABLE assignment_test_submission_receipts;
-- DROP TABLE assignment_test_notification_jobs;
-- ALTER TABLE assignment_test_submission_files
--     DROP FOREIGN KEY fk_assignment_test_files_replaces,
--     DROP INDEX idx_assignment_test_files_replaces,
--     DROP COLUMN replaces_file_id;
-- ALTER TABLE web_notifications
--     DROP FOREIGN KEY fk_web_notifications_assignment_test,
--     DROP INDEX idx_web_notifications_assignment_test,
--     DROP COLUMN action_url,
--     DROP COLUMN notification_type,
--     DROP COLUMN assignment_test_id;
