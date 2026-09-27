ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_bot TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS help_conversations (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, parish_id INT NOT NULL, secretary_id INT NOT NULL,
 staff_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY help_owner_parish(user_id,parish_id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(parish_id) REFERENCES parishes(id), FOREIGN KEY(secretary_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS site_settings (
 setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS fee_snapshot DECIMAL(10,2) NULL;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS checked_in_by INT NULL;
UPDATE applications a JOIN services s ON s.id=a.service_id SET a.fee_snapshot=s.fee WHERE a.fee_snapshot IS NULL;
CREATE TABLE IF NOT EXISTS application_requests (
 id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, requested_by INT NOT NULL,
 request_type ENUM('refund','reschedule') NOT NULL, reason TEXT NOT NULL, proposed_schedule DATETIME NULL,
 status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL, review_note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL, completed_at DATETIME NULL,
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS reminder_deliveries (
 application_id INT NOT NULL, schedule DATETIME NOT NULL, sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(application_id,schedule), FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE application_document_requests ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT NULL;
