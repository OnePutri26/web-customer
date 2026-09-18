<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = (int) $_SESSION['user_id'];
$subscriptionStmt = $conn->prepare(
    "SELECT s.id, s.status, s.id_package, p.nama_paket
     FROM subscriptions s
     LEFT JOIN packages p ON p.id = s.id_package
     WHERE s.id_user = ?
     ORDER BY s.id DESC
     LIMIT 1"
);
$subscriptionStmt->bind_param("i", $userId);
$subscriptionStmt->execute();
$subscription = $subscriptionStmt->get_result()->fetch_assoc();
$subscriptionStmt->close();

if (!$subscription || $subscription['status'] !== 'expired') {
    header("Location: subscription.php");
    exit;
}

$packages = $conn->query(
    "SELECT id, nama_paket, kecepatan, harga, deskripsi
     FROM packages
     WHERE status = 'aktif'
     ORDER BY harga ASC"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perpanjang Langganan | Customer Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/packages.css">
    <style>
        .renewal-page { min-height: 100vh; background: #f5f7fb; }
        .renewal-page .container { max-width: 1120px; padding-top: 34px; }
        .renewal-alert { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px; padding: 16px; border: 1px solid #fed7aa; border-radius: 13px; color: #9a3412; background: #fff7ed; font-size: 12px; line-height: 1.6; }
        .renewal-alert i { font-size: 19px; }
        .renewal-title { margin-bottom: 24px; }
        .renewal-title h1 { margin: 6px 0; color: #172033; font-size: 30px; font-weight: 800; }
        .renewal-title p { color: #667085; font-size: 13px; }
    </style>
</head>
<body>
<div class="renewal-page">
    <nav class="packages-navbar"><a href="subscription.php" class="back-dashboard"><i class="bi bi-arrow-left"></i></a><div class="nav-title"><strong>Perpanjang Langganan</strong><span>Aktifkan kembali koneksi internetmu</span></div><a href="subscription.php" class="dashboard-link"><i class="bi bi-person-check-fill"></i> Langganan</a></nav>
    <main class="container">
        <div class="renewal-alert"><i class="bi bi-clock-history"></i><span>Subscription <strong><?= htmlspecialchars($subscription['nama_paket'] ?? 'sebelumnya') ?></strong> sudah expired. Pilih paket untuk melanjutkan layanan.</span></div>
        <div class="renewal-title"><span class="eyebrow"><i class="bi bi-arrow-repeat"></i> PERPANJANG LAYANAN</span><h1>Pilih paket perpanjangan</h1><p>Paket lama bisa dipilih kembali atau diganti sesuai kebutuhanmu.</p></div>
        <div class="packages">
            <?php while ($package = $packages->fetch_assoc()): ?>
                <div class="package-card">
                    <div class="package-icon"><i class="bi bi-wifi"></i></div>
                    <h2><?= htmlspecialchars($package['nama_paket']) ?></h2>
                    <div class="speed"><?= htmlspecialchars($package['kecepatan']) ?></div>
                    <div class="price">Rp <?= number_format($package['harga'], 0, ',', '.') ?> <span>/bulan</span></div>
                    <p><?= htmlspecialchars($package['deskripsi'] ?? 'Paket internet untuk kebutuhan harian.') ?></p>
                    <form action="payment.php" method="POST"><input type="hidden" name="package_id" value="<?= (int) $package['id'] ?>"><input type="hidden" name="renewal" value="1"><button type="submit"><i class="bi bi-arrow-repeat"></i> Perpanjang Paket</button></form>
                </div>
            <?php endwhile; ?>
        </div>
    </main>
</div>
</body>
</html>
