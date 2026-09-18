<?php
session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Ambil data customer
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$customer = $result->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}

$customerId = $customer['id'];
$nama = $customer['nama'] ?? 'Customer';
$initial = strtoupper(substr(trim($nama), 0, 1));

/*
|--------------------------------------------------------------------------
| Data paket upgrade
|--------------------------------------------------------------------------
| Bisa kamu sesuaikan dengan paket yang tersedia.
|--------------------------------------------------------------------------
*/

$packages = [
    [
        'id' => 'basic',
        'name' => 'Basic',
        'speed' => '10 Mbps',
        'price' => 150000,
        'description' => 'Cocok untuk penggunaan ringan sehari-hari.',
        'features' => [
            'Kecepatan hingga 10 Mbps',
            'Cocok untuk 2-3 perangkat',
            'Unlimited Internet',
            'Customer Support'
        ]
    ],
    [
        'id' => 'standard',
        'name' => 'Standard',
        'speed' => '20 Mbps',
        'price' => 200000,
        'description' => 'Pilihan ideal untuk keluarga dan streaming.',
        'features' => [
            'Kecepatan hingga 20 Mbps',
            'Cocok untuk 4-6 perangkat',
            'Unlimited Internet',
            'Customer Support'
        ]
    ],
    [
        'id' => 'premium',
        'name' => 'Premium',
        'speed' => '30 Mbps',
        'price' => 275000,
        'description' => 'Lebih cepat untuk banyak perangkat.',
        'features' => [
            'Kecepatan hingga 30 Mbps',
            'Cocok untuk 6-10 perangkat',
            'Unlimited Internet',
            'Prioritas Customer Support'
        ]
    ],
    [
        'id' => 'ultimate',
        'name' => 'Ultimate',
        'speed' => '50 Mbps',
        'price' => 400000,
        'description' => 'Performa maksimal untuk kebutuhan berat.',
        'features' => [
            'Kecepatan hingga 50 Mbps',
            'Cocok untuk 10+ perangkat',
            'Unlimited Internet',
            'Prioritas Customer Support'
        ]
    ]
];

/*
|--------------------------------------------------------------------------
| Paket saat ini
|--------------------------------------------------------------------------
| Jika database memiliki kolom paket/speed, sesuaikan bagian ini.
|--------------------------------------------------------------------------
*/

$currentPackage = $customer['paket'] ?? $customer['package'] ?? 'Belum diketahui';
$currentSpeed = $customer['kecepatan'] ?? $customer['speed'] ?? 'Belum diketahui';

/*
|--------------------------------------------------------------------------
| Format rupiah
|--------------------------------------------------------------------------
*/
function rupiah($number)
{
    return 'Rp ' . number_format($number, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Upgrade WiFi - Customer</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- CSS Dashboard -->
    <link rel="stylesheet" href="assets/css/customer-dashboard.css">

    <!-- CSS Upgrade -->
    <link rel="stylesheet" href="assets/css/upgrade.css">
</head>

<body>

<div class="dashboard-wrapper">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->
    <aside class="sidebar">

        <div class="sidebar-logo">
            <div class="logo-icon">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="logo-text">
                <strong>WiFi Portal</strong>
                <span>Customer Area</span>
            </div>
        </div>

        <nav class="sidebar-menu">

            <div class="menu-title">
                MENU UTAMA
            </div>

            <a href="dashboard.php" class="menu-item">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <a href="billing.php" class="menu-item">
                <i class="bi bi-receipt"></i>
                <span>Tagihan</span>
            </a>

            <a href="usage.php" class="menu-item">
                <i class="bi bi-bar-chart-fill"></i>
                <span>Penggunaan</span>
            </a>

            <a href="speedtest.php" class="menu-item">
                <i class="bi bi-speedometer2"></i>
                <span>Speed Test</span>
            </a>

            <a href="network_status.php" class="menu-item">
                <i class="bi bi-router-fill"></i>
                <span>Status Jaringan</span>
            </a>

            <div class="menu-title">
                LAYANAN
            </div>

            <a href="upgrade.php" class="menu-item active">
                <i class="bi bi-rocket-takeoff-fill"></i>
                <span>Upgrade WiFi</span>
            </a>

            <a href="complaint.php" class="menu-item">
                <i class="bi bi-chat-left-text-fill"></i>
                <span>Keluhan</span>
            </a>

            <a href="service_request.php" class="menu-item">
                <i class="bi bi-tools"></i>
                <span>Permintaan Layanan</span>
            </a>

            <a href="chat.php" class="menu-item">
                <i class="bi bi-headset"></i>
                <span>Chat CS</span>
            </a>

            <div class="menu-title">
                AKUN
            </div>

            <a href="profile.php" class="menu-item">
                <i class="bi bi-person-fill"></i>
                <span>Profil Saya</span>
            </a>

            <a href="../logout.php" class="menu-item logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </nav>

        <div class="sidebar-user">

            <div class="user-avatar">
                <?= htmlspecialchars($initial) ?>
            </div>

            <div class="user-detail">
                <strong><?= htmlspecialchars($nama) ?></strong>
                <small>Customer</small>
            </div>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->
    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">

            <div class="topbar-left">
                <div>
                    <h5>Upgrade WiFi</h5>
                    <span>Pilih paket internet yang sesuai kebutuhan kamu</span>
                </div>
            </div>

            <div class="topbar-right">

                <a href="chat.php" class="notification">
                    <i class="bi bi-headset"></i>
                </a>

                <div class="top-profile">

                    <div class="top-avatar">
                        <?= htmlspecialchars($initial) ?>
                    </div>

                    <div class="top-user">
                        <strong><?= htmlspecialchars($nama) ?></strong>
                        <small>Customer</small>
                    </div>

                </div>

            </div>

        </header>


        <!-- CONTENT -->
        <div class="content upgrade-content">

            <!-- HERO -->
            <section class="upgrade-hero">

                <div class="upgrade-hero-content">

                    <div class="upgrade-hero-icon">
                        <i class="bi bi-rocket-takeoff-fill"></i>
                    </div>

                    <div>
                        <span class="hero-label">
                            UPGRADE INTERNET
                        </span>

                        <h1>
                            Tingkatkan Kecepatan WiFi Kamu
                        </h1>

                        <p>
                            Pilih paket yang lebih cepat untuk streaming,
                            gaming, bekerja, dan menikmati internet tanpa hambatan.
                        </p>
                    </div>

                </div>

                <div class="hero-decoration">
                    <i class="bi bi-wifi"></i>
                </div>

            </section>


            <!-- CURRENT PACKAGE -->
            <section class="current-package-card">

                <div class="current-package-icon">
                    <i class="bi bi-router-fill"></i>
                </div>

                <div class="current-package-info">

                    <span>Paket Saat Ini</span>

                    <h3>
                        <?= htmlspecialchars($currentPackage) ?>
                    </h3>

                    <small>
                        Kecepatan:
                        <strong>
                            <?= htmlspecialchars($currentSpeed) ?>
                        </strong>
                    </small>

                </div>

                <div class="current-status">
                    <span class="status-dot"></span>
                    Aktif
                </div>

            </section>


            <!-- SECTION TITLE -->
            <div class="upgrade-section-heading">

                <div>
                    <span>PILIH PAKET</span>
                    <h2>Paket Internet</h2>
                    <p>
                        Upgrade paket untuk mendapatkan kecepatan yang lebih tinggi.
                    </p>
                </div>

            </div>


            <!-- PACKAGE LIST -->
            <div class="row g-4">

                <?php foreach ($packages as $index => $package): ?>

                    <div class="col-xl-3 col-lg-6 col-md-6">

                        <div class="upgrade-package-card
                            <?= $package['id'] === 'premium' ? 'featured' : '' ?>">

                            <?php if ($package['id'] === 'premium'): ?>
                                <div class="popular-badge">
                                    <i class="bi bi-star-fill"></i>
                                    PALING POPULER
                                </div>
                            <?php endif; ?>

                            <div class="package-icon">
                                <i class="bi bi-wifi"></i>
                            </div>

                            <h3>
                                <?= htmlspecialchars($package['name']) ?>
                            </h3>

                            <div class="package-speed">
                                <?= htmlspecialchars($package['speed']) ?>
                            </div>

                            <p class="package-description">
                                <?= htmlspecialchars($package['description']) ?>
                            </p>

                            <div class="package-price">

                                <strong>
                                    <?= rupiah($package['price']) ?>
                                </strong>

                                <span>/bulan</span>

                            </div>

                            <div class="package-divider"></div>

                            <ul class="package-features">

                                <?php foreach ($package['features'] as $feature): ?>

                                    <li>
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            <?= htmlspecialchars($feature) ?>
                                        </span>
                                    </li>

                                <?php endforeach; ?>

                            </ul>

                            <button
                                type="button"
                                class="btn-upgrade"
                                data-package="<?= htmlspecialchars($package['name']) ?>"
                                data-speed="<?= htmlspecialchars($package['speed']) ?>"
                                data-price="<?= rupiah($package['price']) ?>"
                            >
                                <i class="bi bi-rocket-takeoff"></i>
                                Upgrade Sekarang
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- BENEFITS -->
            <section class="upgrade-benefits">

                <div class="benefits-header">
                    <span>KENAPA UPGRADE?</span>
                    <h2>Internet Lebih Nyaman</h2>
                </div>

                <div class="row g-4">

                    <div class="col-lg-4">

                        <div class="benefit-card">

                            <div class="benefit-icon">
                                <i class="bi bi-lightning-charge-fill"></i>
                            </div>

                            <div>
                                <h4>Lebih Cepat</h4>
                                <p>
                                    Nikmati koneksi lebih cepat untuk aktivitas
                                    online tanpa terasa lambat.
                                </p>
                            </div>

                        </div>

                    </div>


                    <div class="col-lg-4">

                        <div class="benefit-card">

                            <div class="benefit-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>

                            <div>
                                <h4>Lebih Banyak Perangkat</h4>
                                <p>
                                    Hubungkan lebih banyak perangkat tanpa
                                    mengorbankan kualitas koneksi.
                                </p>
                            </div>

                        </div>

                    </div>


                    <div class="col-lg-4">

                        <div class="benefit-card">

                            <div class="benefit-icon">
                                <i class="bi bi-play-btn-fill"></i>
                            </div>

                            <div>
                                <h4>Streaming Lancar</h4>
                                <p>
                                    Streaming film, video, dan musik dengan
                                    koneksi yang lebih stabil.
                                </p>
                            </div>

                        </div>

                    </div>

                </div>

            </section>


            <!-- INFORMATION -->
            <section class="upgrade-info">

                <div class="info-icon">
                    <i class="bi bi-info-circle-fill"></i>
                </div>

                <div>
                    <strong>Informasi Upgrade</strong>

                    <p>
                        Setelah mengajukan upgrade, tim customer service
                        akan menghubungi kamu untuk konfirmasi dan proses
                        perubahan paket.
                    </p>
                </div>

                <a href="chat.php" class="info-button">
                    <i class="bi bi-headset"></i>
                    Hubungi CS
                </a>

            </section>

        </div>

    </main>

</div>


<!-- =====================================================
     MODAL KONFIRMASI
====================================================== -->

<div class="modal fade" id="upgradeModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content upgrade-modal">

            <div class="modal-header">

                <div class="modal-title-wrapper">

                    <div class="modal-icon">
                        <i class="bi bi-rocket-takeoff-fill"></i>
                    </div>

                    <div>
                        <h5>Konfirmasi Upgrade</h5>
                        <span>Pilih paket baru kamu</span>
                    </div>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="selected-package">

                    <div>
                        <span>Paket Baru</span>
                        <strong id="selectedPackage">
                            -
                        </strong>
                    </div>

                    <div class="selected-speed">
                        <span id="selectedSpeed">
                            -
                        </span>

                        <strong id="selectedPrice">
                            -
                        </strong>
                    </div>

                </div>

                <div class="confirmation-note">

                    <i class="bi bi-info-circle-fill"></i>

                    <p>
                        Pengajuan upgrade akan diteruskan ke Customer Service
                        untuk diproses. Pastikan paket yang dipilih sudah sesuai.
                    </p>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-cancel"
                    data-bs-dismiss="modal">
                    Batal
                </button>

                <button
                    type="button"
                    class="btn-confirm"
                    id="confirmUpgrade">
                    <i class="bi bi-check-circle-fill"></i>
                    Ajukan Upgrade
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const modalElement = document.getElementById('upgradeModal');

    const modal = new bootstrap.Modal(modalElement);

    const selectedPackage = document.getElementById('selectedPackage');
    const selectedSpeed = document.getElementById('selectedSpeed');
    const selectedPrice = document.getElementById('selectedPrice');

    const confirmButton = document.getElementById('confirmUpgrade');

    let selectedData = {};

    /*
    |--------------------------------------------------------------------------
    | Tombol Upgrade
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.btn-upgrade').forEach(function (button) {

        button.addEventListener('click', function () {

            selectedData = {
                package: this.dataset.package,
                speed: this.dataset.speed,
                price: this.dataset.price
            };

            selectedPackage.textContent = selectedData.package;
            selectedSpeed.textContent = selectedData.speed;
            selectedPrice.textContent = selectedData.price;

            modal.show();

        });

    });


    /*
    |--------------------------------------------------------------------------
    | Konfirmasi Upgrade
    |--------------------------------------------------------------------------
    */

    confirmButton.addEventListener('click', function () {

        confirmButton.disabled = true;

        confirmButton.innerHTML = `
            <span class="spinner-border spinner-border-sm"></span>
            Memproses...
        `;

        setTimeout(function () {

            modal.hide();

            confirmButton.disabled = false;

            confirmButton.innerHTML = `
                <i class="bi bi-check-circle-fill"></i>
                Ajukan Upgrade
            `;

            alert(
                'Pengajuan upgrade paket ' +
                selectedData.package +
                ' berhasil dikirim. Customer Service akan menghubungi kamu.'
            );

        }, 1200);

    });

});
</script>

</body>
</html>