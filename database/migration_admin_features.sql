-- Adds profile picture / parish logo columns and verification fields used by the admin pages.
-- Run once: mysql -u root parish_db < migration_admin_features.sql

ALTER TABLE parishes
  ADD COLUMN IF NOT EXISTS logo VARCHAR(255) DEFAULT NULL AFTER priest_name;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) DEFAULT NULL AFTER status;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS verified_by INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS verified_at DATETIME DEFAULT NULL;
