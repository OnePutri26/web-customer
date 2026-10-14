-- Run once against wifi_management after taking a database backup.
-- Keeps the existing coverage_expansion_requests table and its submitted data.

CREATE TABLE IF NOT EXISTS `coverage_expansion_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nomor_pengajuan` varchar(30) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `telepon` varchar(20) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `alamat` text NOT NULL,
  `kelurahan` varchar(150) DEFAULT NULL,
  `kecamatan` varchar(150) DEFAULT NULL,
  `patokan` varchar(150) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `maps_url` varchar(500) DEFAULT NULL,
  `catatan` varchar(1000) DEFAULT NULL,
  `status` enum('menunggu','perlu_survei','dalam_perencanaan','terjadwal','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  `catatan_admin` varchar(1000) DEFAULT NULL,
  `catatan_teknisi` varchar(500) DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `surveyed_by` int(10) unsigned DEFAULT NULL,
  `network_available` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `surveyed_at` datetime DEFAULT NULL,
  `network_ready_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coverage_expansion_number` (`nomor_pengajuan`),
  KEY `idx_coverage_expansion_status` (`status`),
  KEY `idx_coverage_expansion_contact` (`telepon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add fields to tables created by earlier releases of coverage_hasil.php.
ALTER TABLE `coverage_expansion_requests`
  ADD COLUMN IF NOT EXISTS `kelurahan` varchar(150) DEFAULT NULL AFTER `alamat`,
  ADD COLUMN IF NOT EXISTS `kecamatan` varchar(150) DEFAULT NULL AFTER `kelurahan`,
  ADD COLUMN IF NOT EXISTS `patokan` varchar(150) DEFAULT NULL AFTER `kecamatan`,
  ADD COLUMN IF NOT EXISTS `maps_url` varchar(500) DEFAULT NULL AFTER `longitude`,
  MODIFY COLUMN `catatan` varchar(1000) DEFAULT NULL,
  MODIFY COLUMN `catatan_admin` varchar(1000) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `catatan_teknisi` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_by` int(10) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `surveyed_by` int(10) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `network_available` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `surveyed_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `network_ready_at` datetime DEFAULT NULL;

-- Temporarily retain old ENUM values so existing rows can be normalized safely.
ALTER TABLE `coverage_expansion_requests`
  MODIFY COLUMN `status` enum(
    'menunggu','perlu_survei','dalam_perencanaan','terjadwal','disetujui','ditolak',
    'evaluasi','survei','tersedia','selesai'
  ) NOT NULL DEFAULT 'menunggu';

UPDATE `coverage_expansion_requests`
SET `network_available` = CASE
  WHEN `status` IN ('tersedia','selesai') THEN 1
  ELSE `network_available`
END,
`status` = CASE
  WHEN `status` = 'evaluasi' THEN 'dalam_perencanaan'
  WHEN `status` = 'survei' THEN 'perlu_survei'
  WHEN `status` IN ('tersedia','selesai') THEN 'disetujui'
  ELSE `status`
END;

ALTER TABLE `coverage_expansion_requests`
  MODIFY COLUMN `status` enum(
    'menunggu','perlu_survei','dalam_perencanaan','terjadwal','disetujui','ditolak'
  ) NOT NULL DEFAULT 'menunggu';
