-- Adds the columns the admin/staff announcement code expects.
-- Run once: mysql -u root parish_db < migration_announcements.sql

ALTER TABLE announcements
  ADD COLUMN IF NOT EXISTS message    TEXT             DEFAULT NULL AFTER content,
  ADD COLUMN IF NOT EXISTS target     VARCHAR(64)      DEFAULT NULL AFTER message,
  ADD COLUMN IF NOT EXISTS channel    VARCHAR(32)      DEFAULT 'In-App' AFTER target,
  ADD COLUMN IF NOT EXISTS status     ENUM('active','inactive') DEFAULT 'active' AFTER channel,
  ADD COLUMN IF NOT EXISTS parish_id  INT              DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS sent_by    INT              DEFAULT NULL AFTER parish_id,
  ADD COLUMN IF NOT EXISTS sent_at    DATETIME         DEFAULT NULL AFTER sent_by;

-- Backfill so old rows render in lists
UPDATE announcements
   SET sent_at = COALESCE(sent_at, created_at),
       sent_by = COALESCE(sent_by, created_by),
       message = COALESCE(message, content)
 WHERE sent_at IS NULL OR sent_by IS NULL OR message IS NULL;
