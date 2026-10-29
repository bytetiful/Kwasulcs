-- Run this in phpMyAdmin against your live database before deploying the new files

ALTER TABLE quiz_questions
    ADD COLUMN marks DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER type,
    ADD COLUMN format VARCHAR(20) NOT NULL DEFAULT 'general' AFTER marks,
    ADD COLUMN rubric JSON NULL AFTER options,
    ADD COLUMN image_ref INT NULL AFTER rubric,
    ADD COLUMN awarded_marks DECIMAL(5,2) NULL AFTER user_answer,
    ADD COLUMN grading_breakdown JSON NULL AFTER awarded_marks;

ALTER TABLE quiz_sessions
    ADD COLUMN uploaded_images JSON NULL AFTER scope,
    ADD COLUMN total_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00 AFTER uploaded_images;
