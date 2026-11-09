-- Promo berlangganan bayar X bulan, bonus Y bulan.
-- Jalankan sekali setelah memilih database YesNet. Dapat dijalankan ulang.

SET @db_name = DATABASE();

SET @ddl = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'subscription'
      AND COLUMN_NAME = 'promo_code'
  ),
  'SELECT 1',
  'ALTER TABLE `subscription` ADD COLUMN `promo_code` VARCHAR(32) DEFAULT NULL AFTER `status`'
);
PREPARE promo_stmt FROM @ddl;
EXECUTE promo_stmt;
DEALLOCATE PREPARE promo_stmt;

SET @ddl = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'subscription'
      AND COLUMN_NAME = 'bulan_dibayar'
  ),
  'SELECT 1',
  'ALTER TABLE `subscription` ADD COLUMN `bulan_dibayar` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `promo_code`'
);
PREPARE promo_stmt FROM @ddl;
EXECUTE promo_stmt;
DEALLOCATE PREPARE promo_stmt;

SET @ddl = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'subscription'
      AND COLUMN_NAME = 'bulan_bonus'
  ),
  'SELECT 1',
  'ALTER TABLE `subscription` ADD COLUMN `bulan_bonus` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `bulan_dibayar`'
);
PREPARE promo_stmt FROM @ddl;
EXECUTE promo_stmt;
DEALLOCATE PREPARE promo_stmt;

SET @ddl = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'subscription'
      AND COLUMN_NAME = 'masa_berlangganan_bulan'
  ),
  'SELECT 1',
  'ALTER TABLE `subscription` ADD COLUMN `masa_berlangganan_bulan` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `bulan_bonus`'
);
PREPARE promo_stmt FROM @ddl;
EXECUTE promo_stmt;
DEALLOCATE PREPARE promo_stmt;

SET @ddl = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'calon_pelanggan'
      AND COLUMN_NAME = 'promo_code'
  ),
  'SELECT 1',
  IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = @db_name
        AND TABLE_NAME = 'calon_pelanggan'
    ),
    'ALTER TABLE `calon_pelanggan` ADD COLUMN `promo_code` VARCHAR(32) DEFAULT NULL AFTER `paket_id`',
    'SELECT 1'
  )
);
PREPARE promo_stmt FROM @ddl;
EXECUTE promo_stmt;
DEALLOCATE PREPARE promo_stmt;
