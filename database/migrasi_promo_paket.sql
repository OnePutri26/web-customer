-- Tambahkan dukungan promo harga per paket. Jalankan sekali sebelum memakai
-- form admin atau mengaktifkan promo pada paket_wifi.
ALTER TABLE `paket_wifi`
  ADD COLUMN `promo_jenis` ENUM('nominal', 'persentase') DEFAULT NULL,
  ADD COLUMN `promo_nilai` DECIMAL(15,2) DEFAULT NULL,
  ADD COLUMN `promo_mulai` DATE DEFAULT NULL,
  ADD COLUMN `promo_berakhir` DATE DEFAULT NULL;
