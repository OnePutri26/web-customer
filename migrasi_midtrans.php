-- Jalankan sekali. Lewati baris ADD COLUMN yang kolomnya sudah ada.
ALTER TABLE pembayaran
    ADD COLUMN midtrans_order_id VARCHAR(64) NULL,
    ADD COLUMN snap_token VARCHAR(255) NULL,
    ADD UNIQUE KEY uq_pembayaran_midtrans_order (midtrans_order_id);

-- Hanya jika kolom di bawah bertipe ENUM (cek dengan: SHOW COLUMNS FROM pembayaran;)
-- agar bisa menyimpan 'pending' serta label metode dari Midtrans:
ALTER TABLE pembayaran
    MODIFY metode_pembayaran VARCHAR(50) NOT NULL,
    MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending',
    MODIFY transaction_status VARCHAR(30) NULL;