<?php
declare(strict_types=1);

/**
 * YESNET Customer Portal - Speed Test
 *
 * Engine pengujian milik YESNET (bukan Ookla):
 * - assets/js/yesnet-speedtest-engine.js  -> window.YESNET_SPEEDTEST
 * - speedtest-download.php, speedtest-upload.php
 * - speedtest-history.php
 */

/* Ubah ke false setelah error ditemukan dan diperbaiki. */
const SPEEDTEST_DEBUG = true;

if (SPEEDTEST_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_start();

require_once __DIR__ . '/../config/auth.php';
requireLogin();

require_once __DIR__ . '/../config/database.php';
requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('YESNET Speedtest: variabel $conn (mysqli) tidak ditemukan.');
    http_response_code(500);
    exit('Koneksi database tidak tersedia. Periksa config/database.php (variabel $conn).');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* --------------------------------------------------------------------------
 | HELPER (tanpa get_result, sehingga tidak butuh mysqlnd)
 * -------------------------------------------------------------------------- */

function speedtestEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function speedtestQuery(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);

    if (!($result instanceof mysqli_result)) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    return $rows;
}

function speedtestTableExists(mysqli $conn, string $table): bool
{
    $allowed = ['customers', 'paket_wifi', 'complaint', 'notifications'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }

    $safe = $conn->real_escape_string($table);

    return speedtestQuery($conn, "SHOW TABLES LIKE '{$safe}'") !== [];
}

function speedtestColumnExists(mysqli $conn, string $table, string $column): bool
{
    $allowedTables = ['customers', 'paket_wifi', 'complaint', 'notifications'];
    $allowedColumns = [
        'id', 'user_id', 'paket_id', 'nama', 'name', 'status_langganan',
        'nama_paket', 'speed_mbps', 'id_customer', 'status', 'id_user',
        'dibaca'
    ];

    if (!in_array($table, $allowedTables, true) ||
        !in_array($column, $allowedColumns, true)) {
        return false;
    }

    $safe = $conn->real_escape_string($column);

    return speedtestQuery($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'") !== [];
}

function speedtestSubscriptionStatus(mixed $status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['active', 'aktif'], true)) {
        return 'active';
    }

    if (in_array($status, [
        'pending', 'proses', 'menunggu_pemasangan', 'menunggu pemasangan'
    ], true)) {
        return 'pending';
    }

    if (in_array($status, ['suspended', 'ditangguhkan'], true)) {
        return 'suspended';
    }

    if (in_array($status, ['terminated', 'berakhir', 'nonaktif'], true)) {
        return 'terminated';
    }

    return 'belum_berlangganan';
}

/* --------------------------------------------------------------------------
 | LOGIN DAN CSRF
 * -------------------------------------------------------------------------- */

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}

if (
    !isset($_SESSION['speedtest_csrf']) ||
    !is_string($_SESSION['speedtest_csrf']) ||
    strlen($_SESSION['speedtest_csrf']) !== 64
) {
    $_SESSION['speedtest_csrf'] = bin2hex(random_bytes(32));
}

$speedtestCsrf = $_SESSION['speedtest_csrf'];

/* --------------------------------------------------------------------------
 | DATA DEFAULT PELANGGAN
 * -------------------------------------------------------------------------- */

$customer = [];
$customerId = 0;
$nama = trim((string) (
    $_SESSION['nama'] ??
    $_SESSION['username'] ??
    'Customer'
));
$statusLangganan = 'belum_berlangganan';
$paketNama = 'Belum Memilih Paket';
$paketSpeedLabel = '-';
$totalGangguan = 0;
$unreadNotifications = 0;

if (!speedtestTableExists($conn, 'customers')) {
    http_response_code(500);
    exit('Tabel customers tidak ditemukan.');
}

if (!speedtestColumnExists($conn, 'customers', 'user_id')) {
    error_log('YESNET Speedtest: customers.user_id tidak ditemukan.');
    http_response_code(500);
    exit('Struktur tabel customers belum sesuai.');
}

/* --------------------------------------------------------------------------
 | AMBIL DATA CUSTOMER DAN PAKET
 * -------------------------------------------------------------------------- */

$hasPackageTable =
    speedtestTableExists($conn, 'paket_wifi') &&
    speedtestColumnExists($conn, 'paket_wifi', 'id') &&
    speedtestColumnExists($conn, 'customers', 'paket_id');

$joinPackage = $hasPackageTable
    ? 'LEFT JOIN paket_wifi p ON p.id = c.paket_id'
    : '';

$packageNameSelect = (
    $hasPackageTable &&
    speedtestColumnExists($conn, 'paket_wifi', 'nama_paket')
) ? 'p.nama_paket AS nama_paket' : 'NULL AS nama_paket';

$packageSpeedSelect = (
    $hasPackageTable &&
    speedtestColumnExists($conn, 'paket_wifi', 'speed_mbps')
) ? 'p.speed_mbps AS speed_mbps' : 'NULL AS speed_mbps';

/* $userId sudah di-cast ke int, aman dimasukkan langsung ke query. */
$sqlCustomer = "
    SELECT
        c.*,
        {$packageNameSelect},
        {$packageSpeedSelect}
    FROM customers c
    {$joinPackage}
    WHERE c.user_id = {$userId}
    LIMIT 1
";

try {
    $rows = speedtestQuery($conn, $sqlCustomer);

    if (!$rows) {
        http_response_code(404);
        exit('Data customer tidak ditemukan untuk akun ini.');
    }

    $customer = $rows[0];
} catch (Throwable $error) {
    error_log('YESNET Speedtest customer query: ' . $error->getMessage());
    http_response_code(500);
    exit('Data pelanggan gagal dimuat. Periksa error log hosting.');
}

$customerId = (int) ($customer['id'] ?? 0);

$nama = trim((string) (
    $customer['nama'] ??
    $customer['name'] ??
    $_SESSION['nama'] ??
    $_SESSION['username'] ??
    'Customer'
));

if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = speedtestSubscriptionStatus(
    $customer['status_langganan'] ?? ''
);

if (!empty($customer['nama_paket'])) {
    $paketNama = (string) $customer['nama_paket'];
}

if (
    isset($customer['speed_mbps']) &&
    is_numeric($customer['speed_mbps'])
) {
    $paketSpeedLabel = number_format(
        (float) $customer['speed_mbps'],
        0,
        ',',
        '.'
    ) . ' Mbps';
}

/* --------------------------------------------------------------------------
 | JUMLAH GANGGUAN
 * -------------------------------------------------------------------------- */

if (
    $customerId > 0 &&
    speedtestTableExists($conn, 'complaint') &&
    speedtestColumnExists($conn, 'complaint', 'id_customer')
) {
    try {
        $sql = "SELECT COUNT(*) AS total FROM complaint WHERE id_customer = {$customerId}";

        if (speedtestColumnExists($conn, 'complaint', 'status')) {
            $sql .= " AND LOWER(status) NOT IN ('closed', 'resolved')";
        }

        $rows = speedtestQuery($conn, $sql);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $error) {
        error_log('YESNET Speedtest complaint count: ' . $error->getMessage());
    }
}

/* --------------------------------------------------------------------------
 | NOTIFIKASI BELUM DIBACA
 * -------------------------------------------------------------------------- */

if (
    speedtestTableExists($conn, 'notifications') &&
    speedtestColumnExists($conn, 'notifications', 'id_user') &&
    speedtestColumnExists($conn, 'notifications', 'dibaca')
) {
    try {
        $rows = speedtestQuery(
            $conn,
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE (id_user = {$userId} OR id_user IS NULL)
               AND dibaca = 0"
        );
        $unreadNotifications = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $error) {
        error_log('YESNET Speedtest notification count: ' . $error->getMessage());
    }
}

/* --------------------------------------------------------------------------
 | KONFIGURASI TAMPILAN
 * -------------------------------------------------------------------------- */

$avatar = strtoupper(substr($nama !== '' ? $nama : 'C', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'speedtest.php');
$year = date('Y');

$engineFile = 'assets/js/yesnet-speedtest-engine.js';
$enginePath = __DIR__ . '/' . $engineFile;
$engineAvailable = is_file($enginePath);
$engineVersion = $engineAvailable ? (int) filemtime($enginePath) : 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">
    <meta name="description" content="Uji kecepatan koneksi internet pelanggan YESNET.">
    <meta name="speedtest-csrf" content="<?= speedtestEscape($speedtestCsrf); ?>">
    <title>Speed Test - YESNET</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <link rel="stylesheet" href="assets/css/speedtest.css?v=9">

    <script defer src="assets/js/portal-ui.js"></script>
</head>

<body class="dashboard-page">
<div class="customer-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="brand-text">
                <strong>YESNET</strong>
                <span>Customer Portal</span>
            </div>

            <button
                type="button"
                class="sidebar-close"
                id="sidebarClose"
                aria-label="Tutup menu"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="sidebar-scroll">
            <div class="menu-section">
                <div class="menu-label">UTAMA</div>

                <a
                    href="dashboard.php"
                    class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a
                    href="usage.php"
                    class="menu-item <?= $currentPage === 'usage.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-router-fill"></i></span>
                    <span class="menu-text">Internet Saya</span>
                </a>

                <a
                    href="billing.php"
                    class="menu-item <?= $currentPage === 'billing.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a
                    href="speedtest.php"
                    class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>"
                    aria-current="page"
                >
                    <span class="menu-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="menu-text">Speed Test</span>
                </a>

                <a
                    href="complaint.php"
                    class="menu-item <?= $currentPage === 'complaint.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-tools"></i></span>
                    <span class="menu-text">Laporan Gangguan</span>
                    <?php if ($totalGangguan > 0): ?>
                        <span class="menu-badge"><?= $totalGangguan; ?></span>
                    <?php endif; ?>
                </a>

                <a
                    href="network_status.php"
                    class="menu-item <?= $currentPage === 'network_status.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-globe2"></i></span>
                    <span class="menu-text">Status Jaringan</span>
                </a>

                <a
                    href="upgrade.php"
                    class="menu-item <?= $currentPage === 'upgrade.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                    <span class="menu-text">Upgrade Paket</span>
                </a>

                <a
                    href="chat.php"
                    class="menu-item <?= $currentPage === 'chat.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-chat-dots-fill"></i></span>
                    <span class="menu-text">Bantuan / Live Chat</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">LAYANAN</div>

                <?php if ($statusLangganan === 'belum_berlangganan'): ?>
                    <a href="../paket.php?mode=direct" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-box-seam-fill"></i></span>
                        <span class="menu-text">Pilih Paket</span>
                    </a>
                <?php elseif ($statusLangganan === 'pending'): ?>
                    <a href="installation.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-hammer"></i></span>
                        <span class="menu-text">Status Pemasangan</span>
                    </a>
                <?php elseif ($statusLangganan === 'active'): ?>
                    <a href="upgrade.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                        <span class="menu-text">Upgrade Paket</span>
                    </a>

                    <a href="service_request.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-plus-circle-fill"></i></span>
                        <span class="menu-text">Layanan Tambahan</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="menu-section">
                <div class="menu-label">AKUN</div>

                <a href="profile.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-person-circle"></i></span>
                    <span class="menu-text">Profile Saya</span>
                </a>

                <a href="notifikasi.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-bell-fill"></i></span>
                    <span class="menu-text">Notifikasi</span>
                </a>

                <a href="../logout.php" class="menu-item logout-item">
                    <span class="menu-icon"><i class="bi bi-box-arrow-right"></i></span>
                    <span class="menu-text">Logout</span>
                </a>
            </div>
        </div>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= speedtestEscape($avatar); ?></div>
            <div class="sidebar-user-info">
                <strong><?= speedtestEscape($nama); ?></strong>
                <span>ID pelanggan #<?= $customerId; ?></span>
            </div>
            <i class="bi bi-three-dots"></i>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button
                    type="button"
                    class="mobile-menu-button"
                    id="mobileMenuButton"
                    aria-label="Buka menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>
                    <div class="breadcrumb-text">
                        Customer Portal <span>/</span> Speed Test
                    </div>
                    <h1>Speed Test</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button
                    type="button"
                    class="theme-toggle"
                    id="themeToggle"
                    aria-label="Aktifkan tema gelap"
                    aria-pressed="false"
                    title="Ganti tema"
                >
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <a href="notifikasi.php" class="notification-button" title="Notifikasi">
                    <i class="bi bi-bell"></i>
                    <span
                        class="notification-count"
                        <?= $unreadNotifications > 0 ? '' : 'hidden'; ?>
                    ><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                </a>

                <div class="top-profile">
                    <div class="top-profile-avatar"><?= speedtestEscape($avatar); ?></div>
                    <div class="top-profile-info">
                        <strong><?= speedtestEscape($nama); ?></strong>
                        <span>ID pelanggan #<?= $customerId; ?></span>
                    </div>
                    <i class="bi bi-chevron-down"></i>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="section-title-row st-head">
                <div>
                    <span class="section-kicker">INTERNET TOOLS</span>
                    <h2>Uji Koneksi Internet</h2>
                </div>
            </div>

            <div class="st-scope" role="note">
                <i class="bi bi-info-circle"></i>
                <span>
                    Pengujian mengukur kecepatan antara perangkat Anda dan server YESNET.
                    Hasil dapat dipengaruhi perangkat, WiFi, jaringan lokal, dan kondisi
                    jaringan saat pengujian.
                </span>
            </div>

            <?php if (!$engineAvailable): ?>
                <div class="st-scope st-sdk-notice" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>
                        <strong>Mesin speed test belum terpasang.</strong>
                        File <code><?= speedtestEscape($engineFile); ?></code>
                        tidak ditemukan, sehingga pengujian belum dapat dijalankan.
                    </span>
                </div>
            <?php endif; ?>

            <!-- PANEL SPEED TEST -->
            <section class="dashboard-card st-test" aria-labelledby="stTestTitle">
                <h3 id="stTestTitle" class="st-sr-only">Pengujian kecepatan internet</h3>

                <div class="st-test-topline">
                    <span class="st-live-indicator">
                        <span></span> YESNET SPEEDTEST
                    </span>
                    <span class="st-test-status" id="stSdkStatus">
                        <?= $engineAvailable ? 'Memeriksa mesin uji...' : 'Mesin uji belum tersedia'; ?>
                    </span>
                </div>

                <div
                    class="st-gauge"
                    id="stGauge"
                    role="img"
                    aria-label="Indikator kecepatan internet"
                >
                    <div class="st-gauge-inner">
                        <span class="st-state" id="stState" role="status" aria-live="polite">
                            SIAP
                        </span>
                        <div class="st-speed">
                            <strong id="stSpeed">0.00</strong>
                            <span>Mbps</span>
                        </div>
                        <small id="stHint">Tekan tombol untuk memulai pengujian</small>
                    </div>
                </div>

                <p class="st-scale" id="stScale">Hasil pengujian YESNET</p>

                <button
                    type="button"
                    id="stStart"
                    class="st-start"
                    <?= !$engineAvailable ? 'disabled' : ''; ?>
                >
                    <i class="bi bi-lightning-charge-fill" id="stStartIcon"></i>
                    <span id="stStartText">Mulai Speed Test</span>
                </button>

                <div class="st-progress">
                    <div
                        class="st-progress-track"
                        id="stProgressTrack"
                        role="progressbar"
                        aria-label="Progres pengujian"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                    >
                        <span id="stProgressBar"></span>
                    </div>
                    <div class="st-progress-info">
                        <span id="stProgressText">0%</span>
                        <span id="stPhase">Menunggu</span>
                    </div>
                </div>
            </section>

            <!-- HASIL -->
            <div class="st-results">
                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-broadcast-pin"></i></div>
                        <span class="stat-tag">PING</span>
                    </div>
                    <div class="stat-value">
                        <span id="stPing">—</span><small>ms</small>
                    </div>
                    <div class="stat-label" id="stPingNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-download"></i></div>
                        <span class="stat-tag green">DOWNLOAD</span>
                    </div>
                    <div class="stat-value">
                        <span id="stDownload">—</span><small>Mbps</small>
                    </div>
                    <div class="stat-label" id="stDownloadNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-upload"></i></div>
                        <span class="stat-tag">UPLOAD</span>
                    </div>
                    <div class="stat-value">
                        <span id="stUpload">—</span><small>Mbps</small>
                    </div>
                    <div class="stat-label" id="stUploadNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon orange"><i class="bi bi-activity"></i></div>
                        <span class="stat-tag">JITTER</span>
                    </div>
                    <div class="stat-value">
                        <span id="stJitter">—</span><small>ms</small>
                    </div>
                    <div class="stat-label" id="stJitterNote">Belum diuji</div>
                </div>
            </div>

            <!-- DETAIL PENGUJIAN -->
            <section class="dashboard-card st-info">
                <div class="card-heading compact">
                    <div>
                        <span class="card-kicker">DETAIL</span>
                        <h3>Informasi Pengujian</h3>
                    </div>
                    <span class="st-server-pill" id="stServerPill">
                        <span></span> Menunggu server YESNET
                    </span>
                </div>

                <dl class="st-info-grid">
                    <div>
                        <dt><i class="bi bi-person"></i> Pelanggan</dt>
                        <dd><?= speedtestEscape($nama); ?></dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-router"></i> Paket</dt>
                        <dd><?= speedtestEscape($paketNama); ?></dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-speedometer2"></i> Kecepatan paket</dt>
                        <dd><?= speedtestEscape($paketSpeedLabel); ?></dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-hdd-network"></i> Server pengujian</dt>
                        <dd id="stServerName">Belum dipilih</dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-hash"></i> Server ID</dt>
                        <dd id="stServerId">—</dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-wifi"></i> Jenis koneksi browser</dt>
                        <dd id="stConnType">Tidak tersedia</dd>
                    </div>
                    <div>
                        <dt><i class="bi bi-clock"></i> Waktu pengujian</dt>
                        <dd id="stTestTime">Belum ada pengujian</dd>
                    </div>
                </dl>
            </section>

            <!-- RIWAYAT -->
            <section class="dashboard-card st-history" aria-labelledby="stHistoryTitle">
                <div class="card-heading compact">
                    <div>
                        <span class="card-kicker">RIWAYAT</span>
                        <h3 id="stHistoryTitle">Riwayat Speed Test</h3>
                        <p>Riwayat pengujian yang tersimpan pada akun pelanggan ini.</p>
                    </div>
                    <span
                        class="st-history-status"
                        id="stHistoryStatus"
                        role="status"
                        aria-live="polite"
                    ></span>
                </div>

                <div class="st-history-head" aria-hidden="true">
                    <span>Waktu</span>
                    <span>Download</span>
                    <span>Upload</span>
                    <span>Ping</span>
                    <span>Jitter</span>
                </div>

                <div class="st-history-list" id="stHistoryList">
                    <p class="st-history-empty">Memuat riwayat...</p>
                </div>
            </section>

            <!-- TIPS -->
            <div class="st-tips">
                <div class="st-tips-icon"><i class="bi bi-lightbulb-fill"></i></div>
                <div>
                    <strong>Tips agar hasil lebih akurat</strong>
                    <p>
                        Hentikan unduhan, streaming, dan aktivitas berat pada perangkat
                        lain. Jika memungkinkan, gunakan kabel LAN. Untuk membandingkan
                        hasil, lakukan pengujian pada kondisi dan perangkat yang sama.
                    </p>
                </div>
            </div>

            <footer class="dashboard-footer">
                <span>© <?= $year; ?> YESNET</span>
                <span>Customer Portal</span>
            </footer>
        </div>
    </main>
</div>

<script>
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const engineAvailable = <?= $engineAvailable ? 'true' : 'false'; ?>;
    const HISTORY_URL = 'speedtest-history.php';
    const csrfToken = document.querySelector('meta[name="speedtest-csrf"]')?.content || '';

    const $ = (id) => document.getElementById(id);

    const el = {
        start: $('stStart'),
        startText: $('stStartText'),
        startIcon: $('stStartIcon'),
        gauge: $('stGauge'),
        speed: $('stSpeed'),
        scale: $('stScale'),
        state: $('stState'),
        hint: $('stHint'),
        progressTrack: $('stProgressTrack'),
        progressBar: $('stProgressBar'),
        progressText: $('stProgressText'),
        phase: $('stPhase'),
        sdkStatus: $('stSdkStatus'),

        ping: $('stPing'),
        pingNote: $('stPingNote'),
        download: $('stDownload'),
        downloadNote: $('stDownloadNote'),
        upload: $('stUpload'),
        uploadNote: $('stUploadNote'),
        jitter: $('stJitter'),
        jitterNote: $('stJitterNote'),

        serverPill: $('stServerPill'),
        serverName: $('stServerName'),
        serverId: $('stServerId'),
        testTime: $('stTestTime'),
        connType: $('stConnType'),

        historyList: $('stHistoryList'),
        historyStatus: $('stHistoryStatus')
    };

    const connection =
        navigator.connection ||
        navigator.mozConnection ||
        navigator.webkitConnection;

    if (connection && connection.effectiveType) {
        el.connType.textContent = String(connection.effectiveType).toUpperCase();
    }

    let isRunning = false;
    let engine = null;

    function setText(element, value) {
        if (element) element.textContent = value;
    }

    function setProgress(percent, phase) {
        const value = Math.max(0, Math.min(100, Number(percent) || 0));
        el.progressBar.style.width = `${value}%`;
        el.progressTrack.setAttribute('aria-valuenow', String(Math.round(value)));
        el.progressText.textContent = `${Math.round(value)}%`;
        el.phase.textContent = phase || 'Berjalan';
    }

    function setGauge(speed) {
        const value = Number(speed);
        if (!Number.isFinite(value) || value < 0) return;

        const max = value > 100 ? Math.ceil(value / 100) * 100 : 100;
        const degree = Math.min(value / max, 1) * 360;

        el.speed.textContent = value.toFixed(2);
        el.scale.textContent = `Skala 0–${max} Mbps`;
        el.gauge.style.setProperty('--st-deg', `${degree}deg`);
    }

    function displayNumber(value, digits = 2) {
        if (value === null || value === undefined || value === '') return '—';
        const number = Number(value);
        return Number.isFinite(number) && number >= 0
            ? number.toFixed(digits)
            : '—';
    }

    function formatDate(value) {
        if (!value) return 'Waktu tidak tersedia';

        let date;
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) {
            date = new Date(value.replace(' ', 'T') + '+07:00');
        } else {
            date = new Date(value);
        }

        if (Number.isNaN(date.getTime())) return 'Waktu tidak tersedia';

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Jakarta'
        }).format(date) + ' WIB';
    }

    function qualityPing(value) {
        if (!Number.isFinite(Number(value))) return 'Tidak tersedia';
        if (value < 20) return 'Sangat baik';
        if (value < 50) return 'Baik';
        if (value < 100) return 'Cukup';
        return 'Tinggi';
    }

    function qualitySpeed(value) {
        if (!Number.isFinite(Number(value))) return 'Tidak tersedia';
        if (value >= 100) return 'Sangat cepat';
        if (value >= 50) return 'Cepat';
        if (value >= 20) return 'Cukup';
        return 'Rendah';
    }

    function setBusy(busy) {
        isRunning = busy;
        el.start.disabled = busy || !engine;
        el.start.setAttribute('aria-busy', busy ? 'true' : 'false');
        el.startText.textContent = busy ? 'Sedang menguji...' : 'Mulai Speed Test';
        el.startIcon.className = busy
            ? 'bi bi-hourglass-split'
            : 'bi bi-lightning-charge-fill';
    }

    function resetResults() {
        setText(el.ping, '—');
        setText(el.download, '—');
        setText(el.upload, '—');
        setText(el.jitter, '—');

        setText(el.pingNote, 'Sedang menguji...');
        setText(el.downloadNote, 'Menunggu...');
        setText(el.uploadNote, 'Menunggu...');
        setText(el.jitterNote, 'Menunggu...');

        setGauge(0);
        setProgress(0, 'Menyiapkan pengujian');
    }

    /* ---------------- RIWAYAT ---------------- */

    function showHistoryMessage(message) {
        el.historyList.replaceChildren();
        const paragraph = document.createElement('p');
        paragraph.className = 'st-history-empty';
        paragraph.textContent = message;
        el.historyList.appendChild(paragraph);
    }

    function createIcon(className) {
        const icon = document.createElement('i');
        icon.className = `bi ${className}`;
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function renderHistory(history) {
        if (!Array.isArray(history) || history.length === 0) {
            showHistoryMessage('Belum ada hasil speed test tersimpan.');
            return;
        }

        el.historyList.replaceChildren();

        history.forEach((item) => {
            const row = document.createElement('article');
            row.className = 'st-history-row';

            const time = document.createElement('time');
            time.textContent = formatDate(item.tested_at);
            row.appendChild(time);

            const cells = [
                ['bi-download', 'st-cell-download', displayNumber(item.download_mbps) + ' Mbps'],
                ['bi-upload', 'st-cell-upload', displayNumber(item.upload_mbps) + ' Mbps'],
                ['bi-broadcast-pin', '', displayNumber(item.ping_ms, 1) + ' ms'],
                ['bi-activity', '', displayNumber(item.jitter_ms, 1) + ' ms']
            ];

            cells.forEach(([iconName, className, value]) => {
                const span = document.createElement('span');
                span.className = className;
                span.appendChild(createIcon(iconName));
                span.appendChild(document.createTextNode(` ${value}`));
                row.appendChild(span);
            });

            el.historyList.appendChild(row);
        });
    }

    async function readJson(response) {
        try {
            return await response.json();
        } catch {
            return {};
        }
    }

    async function loadHistory() {
        try {
            const response = await fetch(HISTORY_URL, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });

            const payload = await readJson(response);

            if (!response.ok || !Array.isArray(payload.history)) {
                throw new Error(payload.error || 'Riwayat tidak dapat dimuat.');
            }

            renderHistory(payload.history);
            setText(el.historyStatus, '');
        } catch (error) {
            console.error('YESNET speedtest history:', error);
            setText(el.historyStatus, 'Riwayat gagal dimuat.');
            showHistoryMessage('Riwayat belum dapat dimuat. Periksa endpoint speedtest-history.php.');
        }
    }

    async function saveHistory(result) {
        setText(el.historyStatus, 'Menyimpan hasil...');

        const response = await fetch(HISTORY_URL, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-YESNET-CSRF': csrfToken
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify({
                download_mbps: result.download_mbps,
                upload_mbps: result.upload_mbps,
                ping_ms: result.ping_ms,
                jitter_ms: result.jitter_ms
            })
        });

        const payload = await readJson(response);

        if (!response.ok || payload.saved !== true) {
            throw new Error(payload.error || 'Hasil gagal disimpan.');
        }

        setText(el.historyStatus, 'Hasil berhasil disimpan.');
        await loadHistory();
    }

    /* ---------------- HASIL PENGUJIAN ---------------- */

    function normalizeResult(raw) {
        if (!raw || typeof raw !== 'object') {
            throw new Error('Hasil pengujian tidak valid.');
        }

        const result = {
            download_mbps: Number(raw.download_mbps),
            upload_mbps: Number(raw.upload_mbps),
            ping_ms: Number(raw.ping_ms),
            jitter_ms: raw.jitter_ms == null ? null : Number(raw.jitter_ms),
            packet_loss: raw.packet_loss == null ? null : Number(raw.packet_loss),
            server_name: String(raw.server_name || ''),
            server_id: raw.server_id == null ? '' : String(raw.server_id)
        };

        for (const key of ['download_mbps', 'upload_mbps', 'ping_ms']) {
            if (!Number.isFinite(result[key]) || result[key] < 0) {
                throw new Error(`Nilai ${key} tidak valid.`);
            }
        }

        if (result.jitter_ms !== null &&
            (!Number.isFinite(result.jitter_ms) || result.jitter_ms < 0)) {
            result.jitter_ms = null;
        }

        if (result.packet_loss !== null &&
            (!Number.isFinite(result.packet_loss) || result.packet_loss < 0)) {
            result.packet_loss = null;
        }

        return result;
    }

    function renderResult(result) {
        setText(el.ping, displayNumber(result.ping_ms, 1));
        setText(el.download, displayNumber(result.download_mbps, 2));
        setText(el.upload, displayNumber(result.upload_mbps, 2));
        setText(el.jitter, displayNumber(result.jitter_ms, 1));

        setText(el.pingNote, qualityPing(result.ping_ms));
        setText(el.downloadNote, qualitySpeed(result.download_mbps));
        setText(el.uploadNote, qualitySpeed(result.upload_mbps));
        setText(
            el.jitterNote,
            result.jitter_ms === null
                ? 'Tidak tersedia'
                : (result.jitter_ms < 10 ? 'Stabil' : 'Fluktuatif')
        );

        setGauge(result.download_mbps);
        setText(el.serverName, result.server_name || 'Tidak tersedia');
        setText(el.serverId, result.server_id || 'Tidak tersedia');
        setText(el.serverPill, result.server_name || 'Server YESNET');
    }

    /* ---------------- CALLBACK ENGINE ---------------- */

    function handleProgress(event) {
        if (!event || typeof event !== 'object') return;

        if (Number.isFinite(Number(event.percent))) {
            setProgress(Number(event.percent), String(event.phase || 'Pengujian'));
        }

        if (Number.isFinite(Number(event.download_mbps))) {
            setGauge(Number(event.download_mbps));
        } else if (Number.isFinite(Number(event.upload_mbps))) {
            setGauge(Number(event.upload_mbps));
        }

        if (event.phase) {
            setText(el.state, String(event.phase).toUpperCase());
        }
    }

    function handlePhase(phase) {
        if (typeof phase === 'string' && phase.trim()) {
            setText(el.phase, phase);
        }
    }

    function handleServer(server) {
        if (!server || typeof server !== 'object') return;
        setText(el.serverName, String(server.name || 'Server dipilih'));
        setText(el.serverId, server.id == null ? '—' : String(server.id));
        setText(el.serverPill, String(server.name || 'Server YESNET'));
    }

    async function initializeEngine() {
        if (!engineAvailable) {
            setText(el.sdkStatus, 'Mesin uji belum tersedia');
            el.start.disabled = true;
            return;
        }

        if (!window.YESNET_SPEEDTEST ||
            typeof window.YESNET_SPEEDTEST.initialize !== 'function' ||
            typeof window.YESNET_SPEEDTEST.start !== 'function') {
            setText(el.sdkStatus, 'Mesin uji tidak valid');
            el.start.disabled = true;
            setText(el.hint, 'Mesin uji belum lengkap');
            return;
        }

        try {
            engine = window.YESNET_SPEEDTEST;

            await engine.initialize({
                onProgress: handleProgress,
                onPhase: handlePhase,
                onServer: handleServer
            });

            setText(el.sdkStatus, 'Mesin uji siap');
            el.start.disabled = false;
            setText(el.hint, 'Koneksi siap diuji');
        } catch (error) {
            console.error('YESNET speedtest initialization:', error);
            engine = null;
            setText(el.sdkStatus, 'Mesin uji gagal dimuat');
            el.start.disabled = true;
            setText(el.hint, error.message || 'Mesin uji gagal dimuat');
        }
    }

    async function runSpeedTest() {
        if (isRunning || !engine) return;

        setBusy(true);
        el.gauge.classList.remove('is-error', 'is-done');
        el.gauge.classList.add('is-running');
        setText(el.state, 'MENGUJI');
        setText(el.hint, 'Pengujian sedang berlangsung. Mohon tunggu.');
        setText(
            el.testTime,
            new Intl.DateTimeFormat('id-ID', {
                dateStyle: 'medium',
                timeStyle: 'medium',
                timeZone: 'Asia/Jakarta'
            }).format(new Date()) + ' WIB'
        );

        resetResults();

        let failed = false;

        try {
            const result = normalizeResult(await engine.start());

            renderResult(result);
            setProgress(100, 'Pengujian selesai');

            el.gauge.classList.remove('is-running');
            el.gauge.classList.add('is-done');
            setText(el.state, 'SELESAI');
            setText(el.hint, 'Pengujian berhasil diselesaikan');

            try {
                await saveHistory(result);
            } catch (saveError) {
                console.error('YESNET save speedtest result:', saveError);
                setText(el.historyStatus, 'Pengujian selesai, tetapi gagal disimpan.');
            }
        } catch (error) {
            failed = true;
            console.error('YESNET speedtest:', error);

            el.gauge.classList.remove('is-running');
            el.gauge.classList.add('is-error');
            setText(el.state, 'GAGAL');
            setText(
                el.hint,
                error && error.message
                    ? error.message
                    : 'Pengujian gagal. Silakan coba kembali.'
            );
            setText(el.pingNote, 'Gagal');
            setText(el.downloadNote, 'Gagal');
            setText(el.uploadNote, 'Gagal');
            setText(el.jitterNote, 'Gagal');
            setProgress(0, 'Pengujian gagal');
        } finally {
            el.gauge.classList.remove('is-running');
            setBusy(false);
            el.startText.textContent = failed ? 'Coba Lagi' : 'Uji Lagi';
        }
    }

    el.start.addEventListener('click', runSpeedTest);

    /* Riwayat tetap dimuat meskipun mesin uji belum terpasang. */
    loadHistory();
    initializeEngine();

    /* MOBILE SIDEBAR */
    const sidebar = $('sidebar');
    const overlay = $('sidebarOverlay');
    const mobileButton = $('mobileMenuButton');
    const closeButton = $('sidebarClose');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('show');
        overlay?.classList.add('show');
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('show');
        overlay?.classList.remove('show');
        document.body.classList.remove('sidebar-open');
    }

    mobileButton?.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);

    /* THEME */
    const themeToggle = $('themeToggle');
    const themeToggleIcon = $('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute(
                'aria-label',
                isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap'
            );
        }

        if (themeToggleIcon) {
            themeToggleIcon.className = isDark
                ? 'bi bi-sun'
                : 'bi bi-moon-stars';
        }
    }

    let savedTheme = 'light';
    try {
        savedTheme = localStorage.getItem('yesnet-customer-theme') || 'light';
    } catch (error) {
        console.warn('Preferensi tema tidak dapat dibaca.', error);
    }

    applyTheme(savedTheme);

    themeToggle?.addEventListener('click', () => {
        const nextTheme =
            document.body.dataset.theme === 'dark' ? 'light' : 'dark';

        applyTheme(nextTheme);

        try {
            localStorage.setItem('yesnet-customer-theme', nextTheme);
        } catch (error) {
            console.warn('Preferensi tema tidak dapat disimpan.', error);
        }
    });

    window.addEventListener('beforeunload', () => {
        try {
            if (engine && typeof engine.destroy === 'function') {
                engine.destroy();
            }
        } catch (error) {
            console.warn('Speedtest engine cleanup:', error);
        }
    });
});
</script>

<?php if ($engineAvailable): ?>
<script src="<?= speedtestEscape($engineFile); ?>?v=<?= $engineVersion; ?>" defer></script>
<?php endif; ?>

</body>
</html>