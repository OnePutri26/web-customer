-- Backup database first, then run this migration once against the application database.
-- Existing Midtrans columns and payment records are retained.
ALTER TABLE pembayaran
    ADD COLUMN payment_provider VARCHAR(20) NULL,
    ADD COLUMN xendit_external_id VARCHAR(100) NULL,
    ADD COLUMN xendit_invoice_id VARCHAR(100) NULL,
    ADD COLUMN xendit_payment_id VARCHAR(100) NULL,
    ADD COLUMN xendit_invoice_url TEXT NULL,
    ADD UNIQUE KEY uq_pembayaran_xendit_external (xendit_external_id),
    ADD UNIQUE KEY uq_pembayaran_xendit_invoice (xendit_invoice_id),
    MODIFY COLUMN metode_pembayaran VARCHAR(50) NOT NULL,
    MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
    MODIFY COLUMN transaction_status VARCHAR(50) NULL;

-- Label historical Midtrans payments only when the old migration columns exist.
-- Other pre-existing rows remain explicitly identifiable as legacy.
SET @has_midtrans_columns = (
    SELECT COUNT(*) = 2
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pembayaran'
      AND column_name IN ('midtrans_order_id', 'snap_token')
);

SET @provider_backfill = IF(
    @has_midtrans_columns,
    'UPDATE pembayaran SET payment_provider = ''midtrans'' WHERE payment_provider IS NULL AND (midtrans_order_id IS NOT NULL OR snap_token IS NOT NULL)',
    'UPDATE pembayaran SET payment_provider = ''legacy'' WHERE payment_provider IS NULL'
);

PREPARE xendit_provider_backfill FROM @provider_backfill;
EXECUTE xendit_provider_backfill;
DEALLOCATE PREPARE xendit_provider_backfill;
