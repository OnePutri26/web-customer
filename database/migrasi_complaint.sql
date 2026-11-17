-- Back up the database before applying. This migration retains the existing
-- customer complaint table and adds permanent activity history and private
-- image storage. Run once against the YESNET database (MariaDB).

CREATE TABLE IF NOT EXISTS `complaint` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_customer` INT UNSIGNED NOT NULL,
  `complaint_code` VARCHAR(40) NOT NULL,
  `category` VARCHAR(40) NOT NULL,
  `subject` VARCHAR(150) NOT NULL,
  `description` TEXT NOT NULL,
  `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaint_code` (`complaint_code`),
  KEY `idx_complaint_customer_created` (`id_customer`, `created_at`),
  KEY `idx_complaint_status` (`status`),
  KEY `idx_complaint_assigned_user` (`assigned_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- These ALTER clauses are supported by the MariaDB versions used by YESNET.
ALTER TABLE `complaint`
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `complaint`
  MODIFY COLUMN `status` VARCHAR(32) NULL DEFAULT 'pending',
  MODIFY COLUMN `category` VARCHAR(40) NULL DEFAULT 'other';

UPDATE `complaint`
SET `status` = 'pending'
WHERE `status` IS NULL OR TRIM(`status`) = '';

UPDATE `complaint`
SET `category` = 'other'
WHERE `category` IS NULL OR TRIM(`category`) = '';

UPDATE `complaint`
SET `created_at` = CURRENT_TIMESTAMP
WHERE `created_at` IS NULL;

ALTER TABLE `complaint`
  MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  MODIFY COLUMN `category` VARCHAR(40) NOT NULL DEFAULT 'other';
ALTER TABLE `complaint` ENGINE=InnoDB;

UPDATE `complaint`
SET `status` = CASE LOWER(TRIM(`status`))
  WHEN 'open' THEN 'pending'
  WHEN 'new' THEN 'pending'
  WHEN 'baru' THEN 'pending'
  WHEN 'verified' THEN 'pending'
  WHEN 'assigned' THEN 'pending'
  WHEN 'processing' THEN 'in_progress'
  WHEN 'process' THEN 'in_progress'
  WHEN 'proses' THEN 'in_progress'
  WHEN 'waiting_customer' THEN 'waiting_confirmation'
  WHEN 'closed' THEN 'resolved'
  WHEN 'selesai' THEN 'resolved'
  ELSE LOWER(TRIM(`status`))
END;

-- Add the ticket uniqueness constraint only when an equivalent unique index
-- does not already exist. Existing duplicate ticket codes must be resolved
-- before this step can succeed.
SET @complaint_code_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics s
  WHERE s.table_schema = DATABASE()
    AND s.table_name = 'complaint'
    AND s.column_name = 'complaint_code'
    AND s.non_unique = 0
    AND (
      SELECT COUNT(*)
      FROM information_schema.statistics s2
      WHERE s2.table_schema = DATABASE()
        AND s2.table_name = 'complaint'
        AND s2.index_name = s.index_name
    ) = 1
);
SET @complaint_code_index_sql = IF(
  @complaint_code_index_exists = 0,
  'ALTER TABLE `complaint` ADD UNIQUE KEY `uq_complaint_code` (`complaint_code`)',
  'SELECT 1'
);
PREPARE complaint_code_index_stmt FROM @complaint_code_index_sql;
EXECUTE complaint_code_index_stmt;
DEALLOCATE PREPARE complaint_code_index_stmt;

CREATE TABLE IF NOT EXISTS `complaint_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `complaint_id` INT UNSIGNED NOT NULL,
  `actor_user_id` INT UNSIGNED DEFAULT NULL,
  `actor_name` VARCHAR(150) NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `description` TEXT NOT NULL,
  `old_status` VARCHAR(32) DEFAULT NULL,
  `new_status` VARCHAR(32) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint_history_ticket` (`complaint_id`, `created_at`, `id`),
  KEY `idx_complaint_history_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `complaint_attachments` (
  `complaint_id` INT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(32) NOT NULL,
  `contents` MEDIUMBLOB NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing tickets receive a clearly identified baseline event; subsequent
-- events are append-only and are never overwritten by status changes.
INSERT INTO `complaint_histories`
  (`complaint_id`, `actor_user_id`, `actor_name`, `event_type`, `description`, `new_status`, `created_at`)
SELECT c.`id`, NULL, 'Sistem YESNET', 'created',
       CONCAT('Riwayat awal dibuat saat fitur history diaktifkan. Status sebelumnya: ', c.`status`),
       c.`status`, COALESCE(c.`created_at`, CURRENT_TIMESTAMP)
FROM `complaint` c
WHERE NOT EXISTS (
  SELECT 1 FROM `complaint_histories` h
  WHERE h.`complaint_id` = c.`id`
);
