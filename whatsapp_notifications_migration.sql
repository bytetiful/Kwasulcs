-- Stage 1 WhatsApp Cloud API foundation. Additive and MariaDB 10.4 compatible.
-- No existing reminder, email, web-notification, or user columns are changed.

CREATE TABLE IF NOT EXISTS whatsapp_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    phone_e164 VARCHAR(16) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    consent_status ENUM('pending','consented','revoked') NOT NULL DEFAULT 'pending',
    consented_at DATETIME NULL,
    revoked_at DATETIME NULL,
    verification_status ENUM('unverified','pending','verified','failed') NOT NULL DEFAULT 'unverified',
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY whatsapp_contact_user (user_id),
    UNIQUE KEY whatsapp_contact_phone (phone_e164),
    KEY whatsapp_contact_eligibility (enabled, consent_status, verification_status),
    CONSTRAINT whatsapp_contact_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_delivery_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    contact_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(40) NULL,
    source_id BIGINT UNSIGNED NULL,
    notification_type VARCHAR(80) NOT NULL,
    template_name VARCHAR(512) NOT NULL,
    template_language VARCHAR(20) NOT NULL,
    template_parameters_json LONGTEXT NOT NULL,
    destination_phone_e164 VARCHAR(16) NOT NULL,
    status ENUM('queued','processing','sent','delivered','read','retry','failed','cancelled') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(191) NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token CHAR(32) NULL,
    claimed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    failed_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message VARCHAR(1000) NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY whatsapp_delivery_idempotency (idempotency_key),
    UNIQUE KEY whatsapp_delivery_provider_message (provider_message_id),
    KEY whatsapp_delivery_due (status, next_attempt_at, id),
    KEY whatsapp_delivery_lease (status, lease_expires_at),
    KEY whatsapp_delivery_user (user_id, created_at),
    KEY whatsapp_delivery_source (source_type, source_id),
    CONSTRAINT whatsapp_delivery_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT whatsapp_delivery_contact_fk FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_event_id VARCHAR(191) NULL,
    provider_message_id VARCHAR(191) NULL,
    event_type VARCHAR(80) NOT NULL,
    raw_payload LONGTEXT NOT NULL,
    dedupe_key CHAR(64) NOT NULL,
    processing_status ENUM('pending','processing','processed','failed','ignored') NOT NULL DEFAULT 'pending',
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    error_code VARCHAR(100) NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY whatsapp_webhook_dedupe (dedupe_key),
    KEY whatsapp_webhook_provider_event (provider_event_id),
    KEY whatsapp_webhook_message (provider_message_id),
    KEY whatsapp_webhook_processing (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback is intentionally documented but never run automatically:
-- DROP TABLE whatsapp_webhook_events;
-- DROP TABLE whatsapp_delivery_jobs;
-- DROP TABLE whatsapp_contacts;
