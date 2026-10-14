-- Coverage expansion request workflow for YesNet.
-- Run against wifi_management before deploying the related PHP changes.

CREATE TABLE IF NOT EXISTS `coverage_expansion_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nomor_pengajuan` varchar(30) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `telepon` varchar(20) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `alamat` text NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `catatan` varchar(500) DEFAULT NULL,
  `status` enum('menunggu','evaluasi','survei','disetujui','ditolak','tersedia','selesai') NOT NULL DEFAULT 'menunggu',
  `catatan_admin` varchar(500) DEFAULT NULL,
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

-- Upgrade tables created by earlier coverage_hasil.php versions.
ALTER TABLE `coverage_expansion_requests`
  MODIFY `status` enum('menunggu','evaluasi','survei','disetujui','ditolak','tersedia','selesai') NOT NULL DEFAULT 'menunggu',
  ADD COLUMN IF NOT EXISTS `catatan_teknisi` varchar(500) DEFAULT NULL AFTER `catatan_admin`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` int(10) unsigned DEFAULT NULL AFTER `catatan_teknisi`,
  ADD COLUMN IF NOT EXISTS `surveyed_by` int(10) unsigned DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN IF NOT EXISTS `network_available` tinyint(1) NOT NULL DEFAULT 0 AFTER `surveyed_by`,
  ADD COLUMN IF NOT EXISTS `surveyed_at` datetime DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN IF NOT EXISTS `network_ready_at` datetime DEFAULT NULL AFTER `surveyed_at`;

UPDATE `coverage_expansion_requests`
SET `network_available` = 1
WHERE `status` = 'tersedia';
