-- Stage 2 Telegram Bot API foundation. Additive and MariaDB 10.4 compatible.
-- Existing web, email, reminder, WhatsApp, and user records are unchanged.

CREATE TABLE IF NOT EXISTS telegram_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    telegram_user_id BIGINT NOT NULL,
    telegram_chat_id BIGINT NOT NULL,
    chat_type ENUM('private') NOT NULL DEFAULT 'private',
    display_name VARCHAR(191) NOT NULL,
    username VARCHAR(191) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    consent_status ENUM('pending','consented','revoked') NOT NULL DEFAULT 'pending',
    consented_at DATETIME NULL,
    connected_at DATETIME NULL,
    disconnected_at DATETIME NULL,
    blocked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY telegram_connection_user (user_id),
    UNIQUE KEY telegram_connection_telegram_user (telegram_user_id),
    UNIQUE KEY telegram_connection_chat (telegram_chat_id),
    KEY telegram_connection_eligibility (enabled, consent_status, disconnected_at, blocked_at),
    CONSTRAINT telegram_connection_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_link_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY telegram_link_token_hash (token_hash),
    KEY telegram_link_token_active (user_id, consumed_at, invalidated_at, expires_at),
    KEY telegram_link_token_expiry (expires_at),
    CONSTRAINT telegram_link_token_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_notification_preferences (
    connection_id BIGINT UNSIGNED NOT NULL,
    category ENUM('calendar_lecture','assignment_test','online_quiz_test') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (connection_id, category),
    KEY telegram_preference_category (category, enabled),
    CONSTRAINT telegram_preference_connection_fk FOREIGN KEY (connection_id) REFERENCES telegram_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_delivery_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    connection_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(40) NULL,
    source_id BIGINT UNSIGNED NULL,
    notification_type VARCHAR(80) NOT NULL,
    notification_category ENUM('calendar_lecture','assignment_test','online_quiz_test') NOT NULL,
    message_payload_json LONGTEXT NOT NULL,
    inline_keyboard_json LONGTEXT NULL,
    destination_chat_id BIGINT NOT NULL,
    status ENUM('queued','processing','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
    provider_message_id BIGINT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token CHAR(32) NULL,
    claimed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message VARCHAR(1000) NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY telegram_delivery_idempotency (idempotency_key),
    KEY telegram_delivery_provider_message (destination_chat_id, provider_message_id),
    KEY telegram_delivery_due (status, next_attempt_at, id),
    KEY telegram_delivery_lease (status, lease_expires_at),
    KEY telegram_delivery_user (user_id, created_at),
    KEY telegram_delivery_source (source_type, source_id),
    KEY telegram_delivery_connection (connection_id, status),
    CONSTRAINT telegram_delivery_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT telegram_delivery_connection_fk FOREIGN KEY (connection_id) REFERENCES telegram_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_webhook_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    update_id BIGINT NOT NULL,
    update_type VARCHAR(40) NOT NULL,
    telegram_user_id BIGINT NULL,
    telegram_chat_id BIGINT NULL,
    command VARCHAR(32) NULL,
    token_hash CHAR(64) NULL,
    processing_status ENUM('pending','processing','processed','failed','ignored') NOT NULL DEFAULT 'pending',
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    error_code VARCHAR(100) NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY telegram_webhook_update (update_id),
    KEY telegram_webhook_processing (processing_status, received_at),
    KEY telegram_webhook_identity (telegram_user_id, telegram_chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback is documented only and is never run automatically:
-- DROP TABLE telegram_webhook_updates;
-- DROP TABLE telegram_delivery_jobs;
-- DROP TABLE telegram_notification_preferences;
-- DROP TABLE telegram_link_tokens;
-- DROP TABLE telegram_connections;
