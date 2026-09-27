-- ============================================================
-- Migration: Add all tables for complete system features
-- Apostolic Vicariate of San Jose - Parish Service Platform
-- ============================================================

-- Messages table (parishioner <-> secretary messaging)
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    parish_id INT DEFAULT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications table (system-wide notifications for all roles)
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('application','payment','announcement','message','system','schedule') DEFAULT 'system',
    link VARCHAR(500) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FAQs table (managed by secretary, viewed by parishioners)
CREATE TABLE IF NOT EXISTS faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parish_id INT DEFAULT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    sort_order INT DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parish_id) REFERENCES parishes(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sacramental records table
CREATE TABLE IF NOT EXISTS sacramental_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT DEFAULT NULL,
    parish_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    record_type VARCHAR(100) NOT NULL,
    parishioner_name VARCHAR(255) NOT NULL,
    date_of_sacrament DATE NOT NULL,
    minister_name VARCHAR(255) DEFAULT NULL,
    sponsors TEXT DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    certificate_number VARCHAR(100) DEFAULT NULL,
    certificate_generated_at DATETIME DEFAULT NULL,
    status ENUM('active','archived') DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
    FOREIGN KEY (parish_id) REFERENCES parishes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service form fields (dynamic form builder)
CREATE TABLE IF NOT EXISTS service_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    field_name VARCHAR(255) NOT NULL,
    field_label VARCHAR(255) NOT NULL,
    field_type ENUM('text','number','date','select','textarea','file','email','phone') DEFAULT 'text',
    field_options TEXT DEFAULT NULL,
    is_required TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service requirements (required documents per service)
CREATE TABLE IF NOT EXISTS service_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    is_required TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Receipts table (official receipts for bookkeeper)
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    parishioner_name VARCHAR(255) NOT NULL,
    service_name VARCHAR(255) DEFAULT NULL,
    parish_name VARCHAR(255) DEFAULT NULL,
    issued_by INT DEFAULT NULL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail table (activity logging)
CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mass schedules table
CREATE TABLE IF NOT EXISTS mass_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parish_id INT NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME DEFAULT NULL,
    mass_type VARCHAR(100) DEFAULT 'Regular Mass',
    language VARCHAR(50) DEFAULT 'Filipino',
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parish_id) REFERENCES parishes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application form data (stores dynamic field values as JSON)
ALTER TABLE applications ADD COLUMN IF NOT EXISTS form_data LONGTEXT DEFAULT NULL AFTER uploaded_files;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL AFTER form_data;

-- Add max_daily_limit to services
ALTER TABLE services ADD COLUMN IF NOT EXISTS max_daily_limit INT DEFAULT 0 AFTER fee;
ALTER TABLE services ADD COLUMN IF NOT EXISTS requirements_note TEXT DEFAULT NULL AFTER max_daily_limit;

-- Add processed_by to payments for audit
ALTER TABLE payments ADD COLUMN IF NOT EXISTS processed_by INT DEFAULT NULL AFTER created_at;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS refund_reason TEXT DEFAULT NULL AFTER processed_by;

-- Add receipt_number to payments
ALTER TABLE payments ADD COLUMN IF NOT EXISTS receipt_number VARCHAR(50) DEFAULT NULL AFTER refund_reason;

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_messages_receiver ON messages(receiver_id, is_read);
CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_faqs_parish ON faqs(parish_id, status);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_trail(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_trail(user_id);
CREATE INDEX IF NOT EXISTS idx_mass_schedules_parish ON mass_schedules(parish_id, status);
CREATE INDEX IF NOT EXISTS idx_service_fields ON service_fields(service_id, sort_order);
