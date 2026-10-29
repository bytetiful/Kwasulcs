-- assignment_test_stage2_migration.sql
--
-- Local-only lifecycle extension applied after assignment_test_migration.sql.
-- The submission row remains stable across every state and final attempt.

ALTER TABLE assignment_test_submissions
    MODIFY COLUMN status ENUM(
        'draft',
        'submitted',
        'under_review',
        'marked',
        'returned',
        'resubmitted'
    ) NOT NULL DEFAULT 'draft',
    ADD COLUMN review_started_at DATETIME NULL AFTER submitted_at,
    ADD COLUMN marked_at DATETIME NULL AFTER score,
    ADD COLUMN returned_at DATETIME NULL AFTER marked_at;

-- Rollback (only safe while no row uses a Stage 2 lifecycle status):
--
-- ALTER TABLE assignment_test_submissions
--     MODIFY COLUMN status ENUM('draft', 'submitted', 'reviewed') NOT NULL DEFAULT 'draft',
--     DROP COLUMN review_started_at,
--     DROP COLUMN marked_at,
--     DROP COLUMN returned_at;
