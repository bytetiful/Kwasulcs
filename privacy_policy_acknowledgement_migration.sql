CREATE TABLE IF NOT EXISTS privacy_policy_acknowledgements (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    policy_version VARCHAR(64) NOT NULL,
    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_privacy_ack_user_version (user_id, policy_version),
    KEY idx_privacy_ack_policy_version (policy_version),
    CONSTRAINT fk_privacy_ack_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
