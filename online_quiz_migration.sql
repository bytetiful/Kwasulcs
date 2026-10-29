-- Lecturer-authored Online Quiz/Test. Separate from existing AI quiz_* tables.
CREATE TABLE IF NOT EXISTS online_quizzes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 course_id INT NOT NULL, created_by INT NOT NULL,
 title VARCHAR(255) NOT NULL DEFAULT 'Untitled quiz', instructions TEXT NOT NULL,
 status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
 visibility ENUM('enrolled','hidden') NOT NULL DEFAULT 'enrolled',
 opens_at DATETIME NULL, closes_at DATETIME NULL,
 duration_minutes INT NOT NULL DEFAULT 60, attempts_allowed INT NOT NULL DEFAULT 1,
 grading_type ENUM('percentage','points') NOT NULL DEFAULT 'percentage',
 pass_percent DECIMAL(5,2) NULL DEFAULT 50, expose_pass_mark TINYINT NOT NULL DEFAULT 0,
 settings_json LONGTEXT NOT NULL,
 results_released_at DATETIME NULL, answers_released_at DATETIME NULL,
 wizard_step TINYINT NOT NULL DEFAULT 1, revision INT NOT NULL DEFAULT 1,
 published_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY oq_course(course_id,status),
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL,
 position INT NOT NULL, question_type VARCHAR(30) NOT NULL,
 question_text TEXT NOT NULL, marks DECIMAL(8,2) NOT NULL DEFAULT 1,
 options_json LONGTEXT NOT NULL, answer_key_json LONGTEXT NOT NULL,
 grading_policy VARCHAR(30) NOT NULL DEFAULT 'exact_normalized', explanation TEXT NOT NULL,
 UNIQUE KEY oq_question_position(quiz_id,position),
 FOREIGN KEY(quiz_id) REFERENCES online_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL,
 student_id INT NOT NULL, attempt_number INT NOT NULL,
 status ENUM('writing','submitted','expired') NOT NULL DEFAULT 'writing',
 settings_snapshot_json LONGTEXT NOT NULL, quiz_snapshot_json LONGTEXT NOT NULL,
 started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, submitted_at DATETIME NULL,
 locked_until DATETIME NULL, warning_count INT NOT NULL DEFAULT 0, flagged TINYINT NOT NULL DEFAULT 0,
 current_position INT NOT NULL DEFAULT 0, pending_integrity_type VARCHAR(40) NULL,
 pending_integrity_at DATETIME NULL, last_seen_at DATETIME NULL, network_state VARCHAR(20) NOT NULL DEFAULT 'online',
 score DECIMAL(10,2) NULL, total_marks DECIMAL(10,2) NOT NULL,
 needs_review TINYINT NOT NULL DEFAULT 0, review_note TEXT NULL,
 UNIQUE KEY oq_attempt_number(quiz_id,student_id,attempt_number), KEY oq_expiry(status,expires_at),
 FOREIGN KEY(quiz_id) REFERENCES online_quizzes(id) ON DELETE CASCADE,
 FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_attempt_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL,
 source_question_id BIGINT UNSIGNED NOT NULL, position INT NOT NULL, snapshot_json LONGTEXT NOT NULL,
 UNIQUE KEY oq_frozen_position(attempt_id,position),
 FOREIGN KEY(attempt_id) REFERENCES online_quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_question_id BIGINT UNSIGNED NOT NULL,
 answer_json LONGTEXT NOT NULL, revision INT NOT NULL DEFAULT 1,
 awarded_marks DECIMAL(8,2) NULL, is_correct TINYINT NULL, feedback TEXT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY oq_answer(attempt_question_id),
 FOREIGN KEY(attempt_question_id) REFERENCES online_quiz_attempt_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_integrity_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) NOT NULL, event_type VARCHAR(40) NOT NULL,
 is_violation TINYINT NOT NULL DEFAULT 0, warning_number INT NULL,
 server_at DATETIME NOT NULL, metadata_json LONGTEXT NULL,
 UNIQUE KEY oq_integrity_request(attempt_id,request_key),
 FOREIGN KEY(attempt_id) REFERENCES online_quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS online_quiz_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL,
 actor_id INT NULL, action VARCHAR(50) NOT NULL, metadata_json LONGTEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quiz_id) REFERENCES online_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS online_quiz_id BIGINT UNSIGNED NULL;
CREATE UNIQUE INDEX IF NOT EXISTS calendar_online_quiz ON calendar_events(online_quiz_id);
