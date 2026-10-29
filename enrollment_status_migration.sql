-- enrollment_status_migration.sql
--
-- Two separate, additive concerns on enrollments:
--
-- status ('active'/'inactive') — whether an enrollment currently grants
-- access at all. Existing rows all become 'active' by default — correct,
-- not a reinterpretation: every existing row today genuinely represents
-- active access. Nothing is deleted; no historical data is lost.
--
-- access_type ('normal'/'borrowed'/'carryover') — a SEPARATE concern from
-- status: WHY the enrollment exists, independent of whether it's currently
-- active. Added because status alone conflated two different things: a
-- plain enrollments row previously had to mean either "your normal
-- section" or "a deliberate cross-section exception" with no way to tell
-- which. Pre-existing rows are backfilled by comparing their course's
-- department/level against the student's CURRENT department/level (see
-- ensure_enrollment_schema()/classify_enrollment_access() in config.php for
-- the exact rule) — a best-effort classification based on present state,
-- since the historical state at original registration isn't recoverable.
--
-- Root problem this fixes: courses_for_user()'s student query previously
-- treated ANY active enrollments row as "current" regardless of why it
-- exists, AND there was no way to tell a genuine carry-over/borrowed course
-- apart from an ordinary registration in the stored data at all.
--
-- Safe to run multiple times (idempotent) and matches this project's
-- existing convention of small, additive, backward-compatible ALTERs
-- (see department_level_migration.sql for the same pattern applied to
-- courses.department_id/level and users.department_id/level).

ALTER TABLE enrollments
    ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER course_id;

ALTER TABLE enrollments
    ADD COLUMN access_type ENUM('normal', 'borrowed', 'carryover') NOT NULL DEFAULT 'normal' AFTER status;

UPDATE enrollments e
JOIN courses c ON c.id = e.course_id
JOIN users u ON u.id = e.student_id
SET e.access_type = CASE
    WHEN c.department_id <> u.department_id THEN 'borrowed'
    WHEN u.level IS NOT NULL AND c.level <> u.level THEN 'carryover'
    ELSE 'normal'
END;
