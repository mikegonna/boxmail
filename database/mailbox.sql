CREATE TABLE mail_messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id INT(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_mail_messages_sender_sent (sender_id, sent_at),
    CONSTRAINT fk_mail_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE mail_recipients (
    message_id BIGINT UNSIGNED NOT NULL,
    recipient_id INT(100) NOT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (message_id, recipient_id),
    KEY idx_mail_recipients_inbox (recipient_id, read_at),
    CONSTRAINT fk_mail_recipients_message
        FOREIGN KEY (message_id) REFERENCES mail_messages (message_id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_recipients_user
        FOREIGN KEY (recipient_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE mail_documents (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name CHAR(64) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    KEY idx_mail_documents_message (message_id),
    CONSTRAINT fk_mail_documents_message
        FOREIGN KEY (message_id) REFERENCES mail_messages (message_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
