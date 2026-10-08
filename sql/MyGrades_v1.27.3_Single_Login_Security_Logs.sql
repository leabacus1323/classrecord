-- MYGRADES v1.27.3 - Single Login + Student Security Logs
-- Import this file ONCE in phpMyAdmin before uploading the v1.27.3 PHP files.
-- This upgrade preserves existing grades, attendance, accounts, and submissions.

CREATE TABLE IF NOT EXISTS active_user_sessions (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    session_token CHAR(64) NOT NULL,
    php_session_id VARCHAR(128) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    device_type VARCHAR(40) NULL,
    browser VARCHAR(80) NULL,
    login_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    UNIQUE KEY uq_active_session_token (session_token),
    KEY idx_active_last_seen (last_seen_at),
    CONSTRAINT fk_active_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    device_type VARCHAR(40) NULL,
    browser VARCHAR(80) NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_security_user_time (user_id,created_at),
    KEY idx_security_event_time (event_type,created_at),
    KEY idx_security_actor_time (actor_user_id,created_at),
    CONSTRAINT fk_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_security_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
