-- Stage 3 WhatsApp Settings, email authorization, and category preferences.
-- Additive and MariaDB 10.4 compatible. Existing notification tables are unchanged.

CREATE TABLE IF NOT EXISTS whatsapp_verification_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    request_ip_hash CHAR(64) NULL,
    sent_at DATETIME NULL,
    consumed_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY whatsapp_challenge_user_active (user_id, invalidated_at, consumed_at, expires_at),
    KEY whatsapp_challenge_contact (contact_id, created_at),
    KEY whatsapp_challenge_ip_rate (request_ip_hash, created_at),
    CONSTRAINT whatsapp_challenge_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT whatsapp_challenge_contact_fk FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_notification_preferences (
    contact_id BIGINT UNSIGNED NOT NULL,
    category ENUM('calendar_lecture','assignment_test','online_quiz_test') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (contact_id, category),
    KEY whatsapp_preference_category (category, enabled),
    CONSTRAINT whatsapp_preference_contact_fk FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback is documented only and is never run automatically:
-- DROP TABLE whatsapp_notification_preferences;
-- DROP TABLE whatsapp_verification_challenges;
