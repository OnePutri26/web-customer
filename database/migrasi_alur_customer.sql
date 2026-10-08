-- =========================================================
-- MIGRASI: alur coverage -> berlangganan YesNet
-- Jalankan sekali di database wifi_management.
-- Tabel lain (customers, subscription, pengajuan_pemasangan,
-- instalasi, tagihan, pembayaran, odp, paket_wifi) sudah ada.
-- =========================================================

-- Menyimpan permintaan dari alamat yang belum tercover (form request)
CREATE TABLE IF NOT EXISTS `coverage_requests` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `telephone` varchar(30) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `alamat` text NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('baru','dihubungi','selesai') NOT NULL DEFAULT 'baru',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OPSIONAL: samakan paket dengan desain landing page (20/50/100/200 Mbps).
-- Hapus tanda komentar jika ingin dipakai.
-- UPDATE paket_wifi SET nama_paket='Basic',    speed_mbps=20,  harga=200000, deskripsi='Cocok untuk browsing dan sosmed' WHERE id=1;
-- UPDATE paket_wifi SET nama_paket='Family',   speed_mbps=50,  harga=350000, deskripsi='Ideal untuk keluarga modern'     WHERE id=2;
-- UPDATE paket_wifi SET nama_paket='Premium',  speed_mbps=100, harga=500000, deskripsi='Untuk streaming & gaming'         WHERE id=3;
-- UPDATE paket_wifi SET nama_paket='Business', speed_mbps=200, harga=750000, deskripsi='Solusi terbaik untuk bisnis Anda' WHERE id=4;
