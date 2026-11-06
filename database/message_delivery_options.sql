-- Run once against an existing Boxmail database before deploying this feature.
ALTER TABLE users
    ADD COLUMN department VARCHAR(100) NULL;

ALTER TABLE mail_messages
    ADD COLUMN urgency ENUM('normal', 'urgent', 'very_urgent', 'most_urgent')
        NOT NULL DEFAULT 'normal',
    ADD COLUMN delivery_type ENUM('individual', 'department', 'all')
        NOT NULL DEFAULT 'individual',
    ADD COLUMN department VARCHAR(100) NULL;

-- Assign existing users to their departments before using department delivery.
-- Example:
-- UPDATE users SET department = 'ฝ่ายบัญชี' WHERE user_id IN (1, 2, 3);
