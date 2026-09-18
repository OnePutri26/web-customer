<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

$stmt = mysqli_prepare(
    $conn,
    "SELECT s.*, p.nama_paket, p.kecepatan, p.harga
     FROM subscriptions s
     JOIN packages p ON p.id = s.id_package
     WHERE s.id_user = ?
     ORDER BY s.id DESC
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$subscription = mysqli_fetch_assoc($result);

$subscriptionStatus = $subscription['status'] ?? null;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Langganan WiFi | Customer Portal</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/subscription.css">
</head>

<body>

<div class="subscription-page">
    <nav class="subscription-navbar">
        <a href="<?= $subscriptionStatus === 'active' ? 'dashboard.php' : 'packages.php' ?>" class="back-dashboard" aria-label="Kembali"><i class="bi bi-arrow-left"></i></a>
        <div class="nav-title"><strong>Langganan Saya</strong><span>Kelola layanan internet kamu</span></div>
        <a href="<?= $subscriptionStatus === 'active' ? 'dashboard.php' : 'packages.php' ?>" class="dashboard-link"><i class="bi bi-grid-fill"></i> <?= $subscriptionStatus === 'active' ? 'Dashboard' : 'Dashboard' ?></a>
    </nav>

    <main class="subscription-container">
        <section class="subscription-hero">
            <div class="hero-copy">
                <span class="eyebrow"><i class="bi bi-broadcast-pin"></i> CUSTOMER PORTAL</span>
                <h1>Internet yang siap menemani aktivitasmu.</h1>
                <p>Lihat status layanan atau pilih paket yang paling sesuai dengan kebutuhan rumahmu.</p>
            </div>
            <div class="hero-icon"><i class="bi bi-wifi"></i></div>
        </section>

        <div class="subscription-card">

            <div class="icon"><i class="bi bi-router-fill"></i></div>

        <?php if (!$subscription): ?>

            <span class="card-kicker">STATUS LAYANAN</span>
            <h2>Belum berlangganan</h2>

            <p>
                Saat ini akun kamu belum memiliki layanan internet aktif. Pilih paket WiFi dan ajukan pemasangan untuk mulai menikmati koneksi yang stabil.
            </p>

            <a href="packages.php" class="btn-primary">
                <i class="bi bi-boxes"></i> Lihat Paket WiFi
            </a>

        <?php elseif ($subscriptionStatus === 'expired'): ?>

            <span class="card-kicker">LANGGANAN BERAKHIR</span>
            <h2>Perpanjang layananmu</h2>
            <p>Subscription kamu sudah expired. Pilih paket dan selesaikan pembayaran untuk mengaktifkan kembali layanan internet.</p>
            <a href="renewal.php" class="btn-primary"><i class="bi bi-arrow-repeat"></i> Perpanjang Sekarang</a>

        <?php elseif ($subscriptionStatus !== 'active'): ?>

            <span class="card-kicker">PENGAJUAN DIPROSES</span>
            <h2>Menunggu aktivasi</h2>
            <p>Status layanan saat ini <strong><?= htmlspecialchars(strtoupper(str_replace('_', ' ', $subscriptionStatus))) ?></strong>. Kami akan memproses pengajuanmu.</p>
            <a href="packages.php" class="btn-primary"><i class="bi bi-boxes"></i> Lihat Paket</a>

        <?php else: ?>

            <span class="card-kicker">STATUS LANGGANAN</span>
            <h2>Langganan aktif</h2>

            <div class="package-name">
                <?= htmlspecialchars($subscription['nama_paket']) ?>
            </div>

            <p>
                <i class="bi bi-lightning-charge-fill"></i> <?= htmlspecialchars($subscription['kecepatan']) ?>
            </p>

            <div class="status">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars(
                    strtoupper(str_replace('_', ' ', $subscription['status']))
                ) ?>
            </div>

            <?php if ($subscription['status'] === 'active'): ?>

                <a href="dashboard.php" class="btn-primary">
                    <i class="bi bi-grid-fill"></i> Masuk Dashboard
                </a>

            <?php else: ?>

                <a href="installation_status.php" class="btn-primary">
                    <i class="bi bi-clipboard2-check"></i> Lihat Status Pemasangan
                </a>

            <?php endif; ?>

        <?php endif; ?>

        </div>
    </main>

</div>

</body>
</html>