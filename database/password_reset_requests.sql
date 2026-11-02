CREATE TABLE password_reset_requests (
    request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(100) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    pending_user_id INT(100) GENERATED ALWAYS AS (
        CASE WHEN status = 'pending' THEN user_id ELSE NULL END
    ) STORED,
    token_hash CHAR(64) DEFAULT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by INT(100) DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (request_id),
    UNIQUE KEY uq_password_reset_pending_user (pending_user_id),
    KEY idx_password_reset_status_requested (status, requested_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_password_reset_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
