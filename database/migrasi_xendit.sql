-- Back up the database before applying. This migration is safe to rerun.
-- Existing payment rows and legacy Midtrans identifiers are retained.
SET @xendit_payment_ddl =
    'ALTER TABLE `pembayaran`
     MODIFY COLUMN `metode_pembayaran` VARCHAR(50) NOT NULL,
     MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''pending'',
     MODIFY COLUMN `transaction_status` VARCHAR(50) NULL';

SET @xendit_has_column = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name = 'payment_provider'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_column = 0,
    CONCAT(@xendit_payment_ddl, ', ADD COLUMN `payment_provider` VARCHAR(20) NULL'),
    @xendit_payment_ddl
);

SET @xendit_has_column = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name = 'xendit_external_id'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_column = 0,
    CONCAT(@xendit_payment_ddl, ', ADD COLUMN `xendit_external_id` VARCHAR(100) NULL'),
    @xendit_payment_ddl
);

SET @xendit_has_column = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name = 'xendit_invoice_id'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_column = 0,
    CONCAT(@xendit_payment_ddl, ', ADD COLUMN `xendit_invoice_id` VARCHAR(100) NULL'),
    @xendit_payment_ddl
);

SET @xendit_has_column = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name = 'xendit_payment_id'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_column = 0,
    CONCAT(@xendit_payment_ddl, ', ADD COLUMN `xendit_payment_id` VARCHAR(100) NULL'),
    @xendit_payment_ddl
);

SET @xendit_has_column = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name = 'xendit_invoice_url'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_column = 0,
    CONCAT(@xendit_payment_ddl, ', ADD COLUMN `xendit_invoice_url` TEXT NULL'),
    @xendit_payment_ddl
);

SET @xendit_has_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND index_name = 'uq_pembayaran_xendit_external'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_index = 0,
    CONCAT(@xendit_payment_ddl, ', ADD UNIQUE KEY `uq_pembayaran_xendit_external` (`xendit_external_id`)'),
    @xendit_payment_ddl
);

SET @xendit_has_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND index_name = 'uq_pembayaran_xendit_invoice'
);
SET @xendit_payment_ddl = IF(
    @xendit_has_index = 0,
    CONCAT(@xendit_payment_ddl, ', ADD UNIQUE KEY `uq_pembayaran_xendit_invoice` (`xendit_invoice_id`)'),
    @xendit_payment_ddl
);

PREPARE xendit_payment_schema FROM @xendit_payment_ddl;
EXECUTE xendit_payment_schema;
DEALLOCATE PREPARE xendit_payment_schema;

-- Preserve provider identity for historical payments; do not rewrite old
-- Midtrans/manual rows to Xendit.
SET @xendit_has_midtrans_columns = (
    SELECT COUNT(*) = 2
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name IN ('midtrans_order_id', 'snap_token')
);

SET @xendit_provider_backfill = IF(
    @xendit_has_midtrans_columns,
    'UPDATE `pembayaran`
     SET `payment_provider` = IF(
         `midtrans_order_id` IS NOT NULL OR `snap_token` IS NOT NULL,
         ''midtrans'',
         ''legacy''
     )
     WHERE `payment_provider` IS NULL',
    'UPDATE `pembayaran`
     SET `payment_provider` = ''legacy''
     WHERE `payment_provider` IS NULL'
);

PREPARE xendit_provider_backfill FROM @xendit_provider_backfill;
EXECUTE xendit_provider_backfill;
DEALLOCATE PREPARE xendit_provider_backfill;
