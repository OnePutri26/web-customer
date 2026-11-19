-- Back up the database before applying. These feature-specific tables leave
-- the existing notifications table and its data unchanged.

CREATE TABLE IF NOT EXISTS `yesnet_network_incidents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `judul` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `area_id` INT UNSIGNED DEFAULT NULL,
  `area_name` VARCHAR(150) NOT NULL,
  `mulai` DATETIME NOT NULL,
  `estimasi_selesai` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_yesnet_incident_active_area` (`is_active`, `area_id`, `mulai`),
  KEY `idx_yesnet_incident_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `yesnet_customer_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `judul` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `action_url` VARCHAR(255) DEFAULT NULL,
  `dedupe_key` VARCHAR(190) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_yesnet_notification_user_key` (`user_id`, `dedupe_key`),
  KEY `idx_yesnet_notification_user_created` (`user_id`, `created_at`, `id`),
  KEY `idx_yesnet_notification_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `yesnet_customer_notification_reads` (
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`, `user_id`),
  KEY `idx_yesnet_notification_reads_user` (`user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add customer-specific targeting to installations that already have the
-- incident table. The conditional ALTER is safe to run more than once.
SET @yesnet_customer_target_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'yesnet_network_incidents'
    AND column_name = 'customer_id'
);
SET @yesnet_customer_target_sql := IF(
  @yesnet_customer_target_column_exists = 0,
  'ALTER TABLE `yesnet_network_incidents` ADD COLUMN `customer_id` INT UNSIGNED DEFAULT NULL AFTER `area_id`',
  'SELECT 1'
);
PREPARE yesnet_customer_target_stmt FROM @yesnet_customer_target_sql;
EXECUTE yesnet_customer_target_stmt;
DEALLOCATE PREPARE yesnet_customer_target_stmt;

SET @yesnet_customer_target_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'yesnet_network_incidents'
    AND index_name = 'idx_yesnet_incident_customer'
);
SET @yesnet_customer_target_index_sql := IF(
  @yesnet_customer_target_index_exists = 0,
  'ALTER TABLE `yesnet_network_incidents` ADD KEY `idx_yesnet_incident_customer` (`customer_id`)',
  'SELECT 1'
);
PREPARE yesnet_customer_target_index_stmt FROM @yesnet_customer_target_index_sql;
EXECUTE yesnet_customer_target_index_stmt;
DEALLOCATE PREPARE yesnet_customer_target_index_stmt;
