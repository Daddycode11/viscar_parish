-- Latest refund/reschedule/platform migration, matching tools/migrate_separation.php.
-- Select your EXISTING application database before importing this file.
-- Back up the database first. This is NOT a full database export or initial install.
-- Prerequisites: existing users, applications, services and site_settings tables
-- from the earlier project migrations. Do not import into an empty database.
-- Compatible with MySQL/MariaDB: no stored procedures or ADD COLUMN IF NOT EXISTS.
-- Existing rows are retained. DDL auto-commits; restore a backup to undo schema changes.
-- Run one import at a time. Safe to rerun after a successful import.

SET NAMES utf8mb4;

-- Prerequisite checks: these must succeed before continuing.
SELECT id FROM users LIMIT 0;
SELECT id FROM applications LIMIT 0;
SELECT id, name FROM services LIMIT 0;
SELECT setting_key, setting_value FROM site_settings LIMIT 0;

-- Add users.language only when absent.
SET @separation_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users' AND COLUMN_NAME = 'language'
    ),
    'SELECT 1 AS column_already_present',
    'ALTER TABLE `users` ADD COLUMN `language` VARCHAR(3) NOT NULL DEFAULT ''en'''
);
PREPARE separation_statement FROM @separation_ddl;
EXECUTE separation_statement;
DEALLOCATE PREPARE separation_statement;

-- Add users.auth_version only when absent.
SET @separation_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_version'
    ),
    'SELECT 1 AS column_already_present',
    'ALTER TABLE `users` ADD COLUMN `auth_version` INT NOT NULL DEFAULT 1'
);
PREPARE separation_statement FROM @separation_ddl;
EXECUTE separation_statement;
DEALLOCATE PREPARE separation_statement;

-- Add applications.source only when absent.
SET @separation_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'source'
    ),
    'SELECT 1 AS column_already_present',
    'ALTER TABLE `applications` ADD COLUMN `source` VARCHAR(10) NOT NULL DEFAULT ''online'''
);
PREPARE separation_statement FROM @separation_ddl;
EXECUTE separation_statement;
DEALLOCATE PREPARE separation_statement;

-- Add applications.created_by only when absent.
SET @separation_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'created_by'
    ),
    'SELECT 1 AS column_already_present',
    'ALTER TABLE `applications` ADD COLUMN `created_by` INT NULL'
);
PREPARE separation_statement FROM @separation_ddl;
EXECUTE separation_statement;
DEALLOCATE PREPARE separation_statement;

-- Add services.sacrament_type only when absent.
SET @separation_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'services' AND COLUMN_NAME = 'sacrament_type'
    ),
    'SELECT 1 AS column_already_present',
    'ALTER TABLE `services` ADD COLUMN `sacrament_type` VARCHAR(100) NULL'
);
PREPARE separation_statement FROM @separation_ddl;
EXECUTE separation_statement;
DEALLOCATE PREPARE separation_statement;

CREATE TABLE IF NOT EXISTS `accounting_documents` (
    id INT AUTO_INCREMENT PRIMARY KEY, parish_id INT NOT NULL,
        document_type VARCHAR(32) NOT NULL, document_number VARCHAR(64) NULL UNIQUE,
        document_date DATE NOT NULL, party_name VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL,
        description TEXT NOT NULL, reference VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'draft', approved_by INT NULL, approved_at DATETIME NULL,
        created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        request_key CHAR(64) NOT NULL UNIQUE, INDEX accounting_filter(parish_id,document_type,document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX reset_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `security_rate_limits` (
    bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        window_started BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `parish_subscriptions` (
    user_id INT NOT NULL, parish_id INT NOT NULL,
        in_app TINYINT NOT NULL DEFAULT 1, email TINYINT NOT NULL DEFAULT 1,
        sms TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(user_id,parish_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcement_parishes` (
    announcement_id INT NOT NULL, parish_id INT NOT NULL, PRIMARY KEY(announcement_id,parish_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcement_dispatches` (
    request_key CHAR(64) PRIMARY KEY, announcement_id INT NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcement_deliveries` (
    id INT AUTO_INCREMENT PRIMARY KEY, announcement_id INT NOT NULL,
        user_id INT NOT NULL, channel VARCHAR(10) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued',
        error_code VARCHAR(100) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY delivery_once(announcement_id,user_id,channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Classify exact canonical names once; preserve manually configured values.
START TRANSACTION;
SET @separation_seed_needed = NOT EXISTS (
    SELECT 1 FROM site_settings WHERE setting_key = 'separation_sacrament_seed'
);

UPDATE services SET sacrament_type = 'Baptism'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Baptism');

UPDATE services SET sacrament_type = 'Confirmation'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Confirmation');

UPDATE services SET sacrament_type = 'First Communion'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('First Communion');

UPDATE services SET sacrament_type = 'Marriage'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Marriage');

UPDATE services SET sacrament_type = 'Anointing of the Sick'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Anointing of the Sick');

UPDATE services SET sacrament_type = 'Holy Orders'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Holy Orders');

UPDATE services SET sacrament_type = 'Reconciliation'
WHERE @separation_seed_needed = 1 AND sacrament_type IS NULL
  AND LOWER(TRIM(name)) = LOWER('Reconciliation');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'separation_sacrament_seed', '1' WHERE @separation_seed_needed = 1;
COMMIT;

SET @separation_ddl = NULL;
SET @separation_seed_needed = NULL;
SELECT 'Separation migration complete; existing records retained.' AS result;
