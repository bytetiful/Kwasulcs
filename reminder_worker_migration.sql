-- Apply once before enabling the new worker. MariaDB 10.4 compatible.
-- Additive: legacy calendar_notifications and completed records remain untouched.
CREATE TABLE IF NOT EXISTS reminder_delivery_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(30) NOT NULL DEFAULT 'calendar',
    source_id INT NOT NULL,
    occurrence_key VARCHAR(60) NOT NULL,
    occurrence_at DATETIME NOT NULL,
    occurrence_end_at DATETIME NOT NULL,
    user_id INT NOT NULL,
    channel VARCHAR(20) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status ENUM('pending','processing','sent','failed','cancelled','superseded') NOT NULL DEFAULT 'pending',
    claim_token CHAR(32) NULL,
    claimed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    sent_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    schedule_hash CHAR(64) NOT NULL,
    legacy_notification_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY reminder_delivery_identity (source_type,source_id,occurrence_key,user_id,channel),
    KEY reminder_due (status,next_retry_at,scheduled_at),
    KEY reminder_lease (status,lease_expires_at),
    CONSTRAINT reminder_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deliberately no event FK: deleted events leave cancelled delivery history.
ALTER TABLE web_notifications ADD COLUMN IF NOT EXISTS reminder_delivery_job_id BIGINT UNSIGNED NULL;
CREATE UNIQUE INDEX IF NOT EXISTS web_reminder_delivery_unique ON web_notifications(reminder_delivery_job_id);

CREATE TABLE IF NOT EXISTS reminder_worker_health (
    id TINYINT NOT NULL PRIMARY KEY,
    legacy_imported_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'not_run',
    stats_json LONGTEXT NULL,
    last_error VARCHAR(1000) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO reminder_worker_health (id) VALUES (1);
