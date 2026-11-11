-- Safe to rerun. Stores customer-owned browser-to-YESNET-server speed test
-- results; no existing customer, payment, or network tables are changed.
CREATE TABLE IF NOT EXISTS `customer_speedtest_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `download_mbps` DECIMAL(12,3) NOT NULL,
  `upload_mbps` DECIMAL(12,3) NOT NULL,
  `ping_ms` DECIMAL(12,3) NOT NULL,
  `jitter_ms` DECIMAL(12,3) DEFAULT NULL,
  `tested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_speedtest_history_customer_date` (`customer_id`, `tested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
