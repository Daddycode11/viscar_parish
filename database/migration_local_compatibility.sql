-- Additive compatibility for the local imported schema inspected during revision.
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS created_by INT NULL;
ALTER TABLE users MODIFY COLUMN status ENUM('active','suspended','pending') DEFAULT 'active';
CREATE TABLE IF NOT EXISTS staff_audit_log (
 id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, action VARCHAR(64) NOT NULL,
 target_type VARCHAR(32) NOT NULL, target_id INT NOT NULL, details TEXT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(staff_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
