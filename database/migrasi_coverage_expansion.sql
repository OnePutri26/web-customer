
-- Jalankan setelah membuat backup database wifi_management.

CREATE TABLE IF NOT EXISTS `coverage_expansion_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nomor_pengajuan` VARCHAR(30) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `nama` VARCHAR(100) NOT NULL,
  `telepon` VARCHAR(20) NOT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `alamat` TEXT NOT NULL,
  `kelurahan` VARCHAR(150) DEFAULT NULL,
  `kecamatan` VARCHAR(150) DEFAULT NULL,
  `patokan` VARCHAR(150) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `maps_url` VARCHAR(500) DEFAULT NULL,
  `catatan` VARCHAR(1000) DEFAULT NULL,
  `status` ENUM(
    'menunggu',
    'perlu_survei',
    'dalam_perencanaan',
    'terjadwal',
    'disetujui',
    'ditolak',
    'evaluasi',
    'survei',
    'tersedia',
    'selesai'
  ) NOT NULL DEFAULT 'menunggu',
  `catatan_admin` VARCHAR(1000) DEFAULT NULL,
  `catatan_teknisi` VARCHAR(500) DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `surveyed_by` INT UNSIGNED DEFAULT NULL,
  `network_available` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  `surveyed_at` DATETIME DEFAULT NULL,
  `network_ready_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coverage_expansion_number` (`nomor_pengajuan`),
  KEY `idx_coverage_expansion_status` (`status`),
  KEY `idx_coverage_expansion_contact` (`telepon`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Tambahkan kolom yang belum tersedia.
-- Memerlukan versi MariaDB yang mendukung ADD COLUMN IF NOT EXISTS.

ALTER TABLE `coverage_expansion_requests`
  ADD COLUMN IF NOT EXISTS `kelurahan` VARCHAR(150) DEFAULT NULL AFTER `alamat`,
  ADD COLUMN IF NOT EXISTS `kecamatan` VARCHAR(150) DEFAULT NULL AFTER `kelurahan`,
  ADD COLUMN IF NOT EXISTS `patokan` VARCHAR(150) DEFAULT NULL AFTER `kecamatan`,
  ADD COLUMN IF NOT EXISTS `maps_url` VARCHAR(500) DEFAULT NULL AFTER `longitude`,
  ADD COLUMN IF NOT EXISTS `catatan_teknisi` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `surveyed_by` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `network_available` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `surveyed_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `network_ready_at` DATETIME DEFAULT NULL;

-- Sesuaikan panjang kolom catatan.
-- Diasumsikan kedua kolom ini sudah ada pada tabel lama.

ALTER TABLE `coverage_expansion_requests`
  MODIFY COLUMN `catatan` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `catatan_admin` VARCHAR(1000) DEFAULT NULL;

-- Pertahankan nilai status lama sementara proses migrasi berlangsung.

ALTER TABLE `coverage_expansion_requests`
  MODIFY COLUMN `status` ENUM(
    'menunggu',
    'perlu_survei',
    'dalam_perencanaan',
    'terjadwal',
    'disetujui',
    'ditolak',
    'evaluasi',
    'survei',
    'tersedia',
    'selesai'
  ) NOT NULL DEFAULT 'menunggu';

-- Normalisasi status lama dan pertahankan informasi ketersediaan jaringan.

UPDATE `coverage_expansion_requests`
SET
  `network_available` = CASE
    WHEN `status` IN ('tersedia', 'selesai') THEN 1
    ELSE `network_available`
  END,
  `status` = CASE
    WHEN `status` = 'evaluasi' THEN 'dalam_perencanaan'
    WHEN `status` = 'survei' THEN 'perlu_survei'
    WHEN `status` IN ('tersedia', 'selesai') THEN 'disetujui'
    ELSE `status`
  END
WHERE `status` IN (
  'evaluasi', 'survei', 'tersedia', 'selesai'
);

-- Terapkan daftar status akhir.

ALTER TABLE `coverage_expansion_requests`
  MODIFY COLUMN `status` ENUM(
    'menunggu',
    'perlu_survei',
    'dalam_perencanaan',
    'terjadwal',
    'disetujui',
    'ditolak'
  ) NOT NULL DEFAULT 'menunggu';

-- Verifikasi hasil migrasi.

SELECT `status`, COUNT(*) AS `jumlah`
FROM `coverage_expansion_requests`
GROUP BY `status`
ORDER BY `status`;
