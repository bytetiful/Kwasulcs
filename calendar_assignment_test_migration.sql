-- Local Stage: Calendar integration. Run once after the Assignment/Test migrations.
-- Ordinary events retain NULL. MariaDB permits multiple NULLs in a UNIQUE key.
ALTER TABLE calendar_events
    ADD COLUMN assignment_test_id INT NULL,
    ADD UNIQUE KEY uniq_calendar_assignment_test (assignment_test_id),
    ADD CONSTRAINT fk_calendar_assignment_test
        FOREIGN KEY (assignment_test_id) REFERENCES assignment_tests(id) ON DELETE CASCADE;

-- Backfill existing published/closed work without making duplicate reminders.
INSERT INTO calendar_events
    (assignment_test_id,title,event_type,scope,course_id,created_by,
     start_datetime,end_datetime,notify_email,notify_web)
SELECT id,LEFT(title,200),IF(type='test','test','general'),'course',course_id,created_by,
       due_at,due_at,0,0
FROM assignment_tests WHERE status IN ('published','closed')
ON DUPLICATE KEY UPDATE title=VALUES(title), event_type=VALUES(event_type),
    course_id=VALUES(course_id), start_datetime=VALUES(start_datetime), end_datetime=VALUES(end_datetime);
