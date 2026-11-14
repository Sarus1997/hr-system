-- Align the legacy password column with the existing password hash.
-- Run this once before deploying the password-storage code changes.
UPDATE users
SET password = password_hash
WHERE password <> password_hash;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_employee_id VARCHAR(20) NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(80) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_entity (entity_type, entity_id, created_at),
    INDEX idx_audit_logs_actor (actor_user_id, created_at),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_employee
        FOREIGN KEY (actor_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
