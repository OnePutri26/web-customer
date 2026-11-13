<?php

session_start();

require_once "../config/auth.php";

requireLogin();

require_once "../config/database.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");

    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result && $result->num_rows > 0;
}

/**
 * Mengambil seluruh hasil prepared statement.
 * Mendukung hosting dengan maupun tanpa mysqlnd.
 */
function statementFetchAll(mysqli_stmt $stmt): array
{
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    $metadata = $stmt->result_metadata();

    if (!$metadata) {
        return [];
    }

    $row = [];
    $bind = [];

    foreach ($metadata->fetch_fields() as $field) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    $rows = [];

    while ($stmt->fetch()) {
        $copy = [];

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        $rows[] = $copy;
    }

    $metadata->free();

    return $rows;
}

function normalizeSubscriptionStatus($status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['active', 'aktif'], true)) {
        return 'active';
    }

    if (in_array($status, ['pending', 'proses', 'menunggu_pemasangan', 'menunggu pemasangan'], true)) {
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


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN (dipakai endpoint speedtest-*.php)
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['speedtest_csrf']) ||
    !is_string($_SESSION['speedtest_csrf'])
) {
    $_SESSION['speedtest_csrf'] = bin2hex(random_bytes(32));
}

$speedtestCsrf = $_SESSION['speedtest_csrf'];


/*
|--------------------------------------------------------------------------
| DEFAULT DATA
|--------------------------------------------------------------------------
*/

$customer = null;
$customerId = 0;

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Customer';

$statusLangganan = 'belum_berlangganan';

$paketNama = 'Belum Memilih Paket';
$paketSpeedLabel = '-';

$totalGangguan = 0;
$unreadNotifications = 0;


/*
|--------------------------------------------------------------------------
| CEK TABEL CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Speedtest YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER + PAKET
|--------------------------------------------------------------------------
*/

$paketColumns = [
    'nama_paket' => 'nama_paket',
    'speed_mbps' => 'speed_mbps',
];

$selectPaket = [];
$joinPaket = "";

$paketTableAvailable =
    tableExists($conn, 'paket_wifi') &&
    columnExists($conn, 'paket_wifi', 'id') &&
    columnExists($conn, 'customers', 'paket_id');

if ($paketTableAvailable) {
    $joinPaket = "LEFT JOIN paket_wifi p ON p.id = c.paket_id";
}

foreach ($paketColumns as $column => $alias) {
    if ($paketTableAvailable && columnExists($conn, 'paket_wifi', $column)) {
        $selectPaket[] = "p.`{$column}` AS `{$alias}`";
    } else {
        $selectPaket[] = "NULL AS `{$alias}`";
    }
}

$sqlCustomer = "
    SELECT
        c.*,
        " . implode(",\n        ", $selectPaket) . "
    FROM customers c
    {$joinPaket}
    WHERE c.user_id = ?
    LIMIT 1
";

try {
    $stmt = $conn->prepare($sqlCustomer);
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $customerRows = statementFetchAll($stmt);
    $stmt->close();

    if (empty($customerRows)) {
        http_response_code(404);
        die("Data customer tidak ditemukan untuk akun ini.");
    }

    $customer = $customerRows[0];
} catch (Throwable $e) {
    error_log(
        "Speedtest YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data speed test gagal dimuat. Periksa struktur tabel customers dan paket_wifi atau error log hosting.");
}

$customerId = (int) ($customer['id'] ?? 0);

$nama = trim((string) (
    $customer['nama']
    ?? $customer['name']
    ?? $_SESSION['nama']
    ?? $_SESSION['username']
    ?? 'Customer'
));

if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = normalizeSubscriptionStatus($customer['status_langganan'] ?? '');

if (!empty($customer['nama_paket'])) {
    $paketNama = $customer['nama_paket'];
}

if (isset($customer['speed_mbps']) && is_numeric($customer['speed_mbps'])) {
    $paketSpeedLabel = number_format((float) $customer['speed_mbps'], 0, ',', '.') . ' Mbps';
}


/*
|--------------------------------------------------------------------------
| TOTAL GANGGUAN (badge menu)
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists($conn, 'complaint') &&
    columnExists($conn, 'complaint', 'id_customer')
) {
    $sqlComplaint = "SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?";

    if (columnExists($conn, 'complaint', 'status')) {
        $sqlComplaint .= " AND LOWER(status) NOT IN ('closed', 'resolved')";
    }

    try {
        $stmt = $conn->prepare($sqlComplaint);
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Speedtest YESNET complaint count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| NOTIFIKASI BELUM DIBACA
|--------------------------------------------------------------------------
*/

if (
    tableExists($conn, 'notifications') &&
    columnExists($conn, 'notifications', 'id_user') &&
    columnExists($conn, 'notifications', 'dibaca')
) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE (id_user = ? OR id_user IS NULL)
              AND dibaca = 0
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $unreadNotifications = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Speedtest YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| TAMPILAN
|--------------------------------------------------------------------------
*/

$serverName = $_SERVER['SERVER_NAME'] ?? 'Server aplikasi YESNET';

$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#0f4cdb">

    <title>Speed Test - YESNET</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Dashboard CSS (dasar) -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <!-- Speedtest CSS (khusus halaman ini) -->
    <link rel="stylesheet" href="assets/css/speedtest.css?v=6">

</head>


<body class="dashboard-page">

<div class="customer-layout">


    <div class="sidebar-overlay" id="sidebarOverlay"></div>


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar" id="sidebar">

        <div class="sidebar-brand">

            <div class="brand-logo">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="brand-text">
                <strong>YESNET</strong>
                <span>Customer Portal</span>
            </div>

            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Tutup menu">
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <div class="sidebar-scroll">

            <div class="menu-section">

                <div class="menu-label">UTAMA</div>

                <a href="dashboard.php" class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a href="usage.php" class="menu-item <?= $currentPage === 'usage.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-router-fill"></i></span>
                    <span class="menu-text">Internet Saya</span>
                </a>

                <a href="billing.php" class="menu-item <?= $currentPage === 'billing.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a href="billing.php#riwayat-pembayaran" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="menu-text">Riwayat Pembayaran</span>
                </a>

                <a href="speedtest.php" class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>" aria-current="page">
                    <span class="menu-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="menu-text">Speed Test</span>
                </a>

                <a href="complaint.php" class="menu-item <?= $currentPage === 'complaint.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-tools"></i></span>
                    <span class="menu-text">Laporan Gangguan</span>
                    <?php if ($totalGangguan > 0): ?>
                        <span class="menu-badge"><?= $totalGangguan; ?></span>
                    <?php endif; ?>
                </a>

                <a href="network_status.php" class="menu-item <?= $currentPage === 'network_status.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-globe2"></i></span>
                    <span class="menu-text">Status Jaringan</span>
                </a>

                <a href="upgrade.php" class="menu-item <?= $currentPage === 'upgrade.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                    <span class="menu-text">Upgrade Paket</span>
                </a>

                <a href="chat.php" class="menu-item <?= $currentPage === 'chat.php' ? 'active' : ''; ?>">
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

            <div class="sidebar-user-avatar"><?= e($avatar); ?></div>

            <div class="sidebar-user-info">
                <strong><?= e($nama); ?></strong>
                <span>ID pelanggan #<?= (int) $customerId; ?></span>
            </div>

            <i class="bi bi-three-dots"></i>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">

        <header class="topbar">

            <div class="topbar-left">

                <button type="button" class="mobile-menu-button" id="mobileMenuButton" aria-label="Buka menu">
                    <i class="bi bi-list"></i>
                </button>

                <div>
                    <div class="breadcrumb-text">
                        Customer Portal
                        <span>/</span>
                        Speed Test
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

                    <div class="top-profile-avatar"><?= e($avatar); ?></div>

                    <div class="top-profile-info">
                        <strong><?= e($nama); ?></strong>
                        <span>ID pelanggan #<?= (int) $customerId; ?></span>
                    </div>

                    <i class="bi bi-chevron-down"></i>

                </div>

            </div>

        </header>


        <div class="dashboard-content">


            <div class="section-title-row st-head">
                <div>
                    <span class="section-kicker">INTERNET TOOLS</span>
                    <h2>Uji Koneksi</h2>
                </div>
            </div>


            <!-- Catatan cakupan pengujian -->
            <div class="st-scope" role="note">
                <i class="bi bi-info-circle"></i>
                <span>
                    Speed test ini mengukur koneksi dari browser kamu ke server aplikasi YESNET.
                    Hasilnya bukan pembacaan langsung dari router dan tidak mewakili seluruh jaringan ISP,
                    sehingga bisa berbeda dari kecepatan paket.
                </span>
            </div>


            <!-- =================================================
                 PENGUJIAN
            ================================================== -->

            <section class="dashboard-card st-test" aria-labelledby="stTestTitle">

                <h3 id="stTestTitle" class="st-sr-only">Pengujian kecepatan</h3>

                <div class="st-gauge" id="stGauge" role="img" aria-label="Indikator kecepatan">

                    <div class="st-gauge-inner">

                        <span class="st-state" id="stState" role="status" aria-live="polite">SIAP</span>

                        <div class="st-speed">
                            <strong id="stSpeed">0</strong>
                            <span>Mbps</span>
                        </div>

                        <small id="stHint">Tekan tombol untuk memulai</small>

                    </div>

                </div>

                <p class="st-scale" id="stScale">Skala 0–100 Mbps</p>

                <button type="button" id="stStart" class="st-start">
                    <i class="bi bi-lightning-charge-fill" id="stStartIcon"></i>
                    <span id="stStartText">Mulai Speed Test</span>
                </button>

                <div class="st-progress">

                    <div
                        class="st-progress-track"
                        role="progressbar"
                        aria-label="Progres pengujian"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                        id="stProgressTrack"
                    >
                        <span id="stProgressBar"></span>
                    </div>

                    <div class="st-progress-info">
                        <span id="stProgressText">0%</span>
                        <span id="stPhase">Menunggu</span>
                    </div>

                </div>

            </section>


            <!-- =================================================
                 HASIL
            ================================================== -->

            <div class="st-results">

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-broadcast-pin"></i></div>
                        <span class="stat-tag">PING</span>
                    </div>
                    <div class="stat-value">
                        <span id="stPing">—</span>
                        <small>ms</small>
                    </div>
                    <div class="stat-label" id="stPingNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-download"></i></div>
                        <span class="stat-tag green">DOWNLOAD</span>
                    </div>
                    <div class="stat-value">
                        <span id="stDownload">—</span>
                        <small>Mbps</small>
                    </div>
                    <div class="stat-label" id="stDownloadNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-upload"></i></div>
                        <span class="stat-tag">UPLOAD</span>
                    </div>
                    <div class="stat-value">
                        <span id="stUpload">—</span>
                        <small>Mbps</small>
                    </div>
                    <div class="stat-label" id="stUploadNote">Belum diuji</div>
                </div>

                <div class="stat-card st-result">
                    <div class="stat-top">
                        <div class="stat-icon orange"><i class="bi bi-activity"></i></div>
                        <span class="stat-tag">JITTER</span>
                    </div>
                    <div class="stat-value">
                        <span id="stJitter">—</span>
                        <small>ms</small>
                    </div>
                    <div class="stat-label" id="stJitterNote">Belum diuji</div>
                </div>

            </div>


            <!-- =================================================
                 INFORMASI KONEKSI
            ================================================== -->

            <div class="dashboard-card st-info">

                <div class="card-heading compact">

                    <div>
                        <span class="card-kicker">DETAIL</span>
                        <h3>Informasi Pengujian</h3>
                    </div>

                    <span class="st-server-pill">
                        <span></span>
                        Server uji aplikasi
                    </span>

                </div>

                <dl class="st-info-grid">

                    <div>
                        <dt><i class="bi bi-person"></i> Pelanggan</dt>
                        <dd><?= e($nama); ?></dd>
                    </div>

                    <div>
                        <dt><i class="bi bi-router"></i> Paket</dt>
                        <dd><?= e($paketNama); ?></dd>
                    </div>

                    <div>
                        <dt><i class="bi bi-speedometer2"></i> Kecepatan paket</dt>
                        <dd><?= e($paketSpeedLabel); ?></dd>
                    </div>

                    <div>
                        <dt><i class="bi bi-hdd-network"></i> Server</dt>
                        <dd><?= e($serverName); ?></dd>
                    </div>

                    <div>
                        <dt><i class="bi bi-wifi"></i> Jenis koneksi browser</dt>
                        <dd id="stConnType">—</dd>
                    </div>

                    <div>
                        <dt><i class="bi bi-clock"></i> Waktu pengujian</dt>
                        <dd id="stTestTime">Belum ada pengujian</dd>
                    </div>

                </dl>

            </div>


            <!-- =================================================
                 RIWAYAT
            ================================================== -->

            <section class="dashboard-card st-history" aria-labelledby="stHistoryTitle">

                <div class="card-heading compact">

                    <div>
                        <span class="card-kicker">RIWAYAT</span>
                        <h3 id="stHistoryTitle">Riwayat Speed Test</h3>
                        <p>Hasil pengujian browser ke server aplikasi YESNET.</p>
                    </div>

                    <span class="st-history-status" id="stHistoryStatus" role="status" aria-live="polite"></span>

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


            <!-- =================================================
                 TIPS
            ================================================== -->

            <div class="st-tips">

                <div class="st-tips-icon"><i class="bi bi-lightbulb-fill"></i></div>

                <div>
                    <strong>Tips agar hasil lebih akurat</strong>
                    <p>
                        Hentikan unduhan, streaming, atau aktivitas berat di perangkat lain,
                        lalu ulangi pengujian beberapa kali. Menggunakan kabel LAN biasanya
                        memberi hasil yang lebih stabil daripada WiFi.
                    </p>
                </div>

            </div>


            <footer class="dashboard-footer">
                <span>© <?= date('Y'); ?> YESNET</span>
                <span>Customer Portal</span>
            </footer>

        </div>

    </main>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const speedtestCsrf = <?= json_encode(
        $speedtestCsrf,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ); ?>;


    /*
    |----------------------------------------------------------------------
    | ELEMEN
    |----------------------------------------------------------------------
    */

    const $ = function (id) {
        return document.getElementById(id);
    };

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
        ping: $('stPing'),
        pingNote: $('stPingNote'),
        download: $('stDownload'),
        downloadNote: $('stDownloadNote'),
        upload: $('stUpload'),
        uploadNote: $('stUploadNote'),
        jitter: $('stJitter'),
        jitterNote: $('stJitterNote'),
        testTime: $('stTestTime'),
        connType: $('stConnType'),
        historyList: $('stHistoryList'),
        historyStatus: $('stHistoryStatus')
    };

    const REQUEST_TIMEOUT_MS = 60000;
    const PING_SAMPLES = 5;


    /*
    |----------------------------------------------------------------------
    | JENIS KONEKSI BROWSER (jika didukung)
    |----------------------------------------------------------------------
    */

    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

    if (connection && connection.effectiveType) {
        el.connType.textContent = String(connection.effectiveType).toUpperCase();
    }


    /*
    |----------------------------------------------------------------------
    | UTIL
    |----------------------------------------------------------------------
    */

    function setProgress(percent, phase) {
        const value = Math.max(0, Math.min(100, percent));

        el.progressBar.style.width = value + '%';
        el.progressTrack.setAttribute('aria-valuenow', String(Math.round(value)));
        el.progressText.textContent = Math.round(value) + '%';
        el.phase.textContent = phase;
    }

    function setGauge(speed) {
        const value = Math.max(0, Number(speed) || 0);

        // Skala dasar 100 Mbps; naik per 100 Mbps bila hasil lebih tinggi.
        const max = value > 100 ? Math.ceil(value / 100) * 100 : 100;
        const degree = Math.min(value / max, 1) * 360;

        el.speed.textContent = value.toFixed(2);
        el.scale.textContent = 'Skala 0–' + max + ' Mbps';
        el.gauge.style.setProperty('--st-deg', degree + 'deg');
    }

    function setText(element, text) {
        element.textContent = text;
    }

    async function fetchWithTimeout(url, options) {
        const controller = new AbortController();
        const timer = window.setTimeout(function () {
            controller.abort();
        }, REQUEST_TIMEOUT_MS);

        try {
            return await fetch(url, Object.assign({}, options, { signal: controller.signal }));
        } finally {
            window.clearTimeout(timer);
        }
    }

    function createIcon(name) {
        const icon = document.createElement('i');
        icon.className = 'bi ' + name;
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function formatHistoryDate(value) {
        const date = new Date(String(value).replace(' ', 'T') + '+07:00');

        if (Number.isNaN(date.getTime())) {
            return 'Waktu tidak tersedia';
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Jakarta'
        }).format(date) + ' WIB';
    }

    function numberOrNull(value, digits, suffix) {
        const number = Number(value);

        if (value === null || value === undefined || !Number.isFinite(number)) {
            return '—';
        }

        return number.toFixed(digits) + suffix;
    }


    /*
    |----------------------------------------------------------------------
    | PENGUKURAN
    |----------------------------------------------------------------------
    */

    async function testPing() {
        const url = 'speedtest-download.php?_=' + Date.now();
        const options = {
            method: 'HEAD',
            cache: 'no-store',
            headers: { 'X-YESNET-CSRF': speedtestCsrf }
        };

        // Permintaan pertama dibuang karena memuat setup koneksi (DNS/TLS).
        try {
            await fetchWithTimeout(url + '&warmup=1', options);
        } catch (error) {
            console.warn('Warm-up ping gagal:', error);
        }

        const samples = [];

        for (let i = 0; i < PING_SAMPLES; i++) {
            const start = performance.now();

            try {
                const response = await fetchWithTimeout(url + '&n=' + i, options);

                if (response.ok) {
                    samples.push(performance.now() - start);
                }
            } catch (error) {
                console.error(error);
            }
        }

        if (samples.length === 0) {
            throw new Error('Ping gagal: server uji tidak merespons.');
        }

        const average = samples.reduce(function (a, b) {
            return a + b;
        }, 0) / samples.length;

        const jitter = samples.length > 1
            ? samples.slice(1).reduce(function (total, sample, index) {
                return total + Math.abs(sample - samples[index]);
            }, 0) / (samples.length - 1)
            : null;

        return { ping: average, jitter: jitter };
    }

    async function testDownload() {
        const start = performance.now();

        const response = await fetchWithTimeout('speedtest-download.php?_=' + Date.now(), {
            cache: 'no-store',
            headers: { 'X-YESNET-CSRF': speedtestCsrf }
        });

        if (!response.ok) {
            throw new Error('Download test gagal (HTTP ' + response.status + ').');
        }

        const blob = await response.blob();
        const seconds = (performance.now() - start) / 1000;

        if (blob.size === 0 || seconds <= 0) {
            throw new Error('Download test tidak mengembalikan data.');
        }

        return (blob.size * 8) / seconds / 1000000;
    }

    async function testUpload() {
        const size = 5 * 1024 * 1024; // 5 MB
        const data = new Uint8Array(size);

        const start = performance.now();

        const response = await fetchWithTimeout('speedtest-upload.php?_=' + Date.now(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/octet-stream',
                'X-YESNET-CSRF': speedtestCsrf
            },
            body: data,
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error('Upload test gagal (HTTP ' + response.status + ').');
        }

        await response.text();

        const seconds = (performance.now() - start) / 1000;

        if (seconds <= 0) {
            throw new Error('Upload test tidak valid.');
        }

        return (size * 8) / seconds / 1000000;
    }


    /*
    |----------------------------------------------------------------------
    | LABEL KUALITAS
    |----------------------------------------------------------------------
    */

    function pingLabel(ms) {
        if (ms < 30) {
            return 'Sangat baik';
        }
        if (ms < 60) {
            return 'Baik';
        }
        if (ms < 100) {
            return 'Cukup';
        }
        return 'Tinggi';
    }

    function downloadLabel(mbps) {
        if (mbps >= 50) {
            return 'Sangat cepat';
        }
        if (mbps >= 20) {
            return 'Cepat';
        }
        if (mbps >= 10) {
            return 'Cukup';
        }
        return 'Lambat';
    }

    function uploadLabel(mbps) {
        if (mbps >= 20) {
            return 'Sangat baik';
        }
        if (mbps >= 10) {
            return 'Baik';
        }
        if (mbps >= 5) {
            return 'Cukup';
        }
        return 'Lambat';
    }


    /*
    |----------------------------------------------------------------------
    | RIWAYAT
    |----------------------------------------------------------------------
    */

    function showHistoryMessage(message) {
        el.historyList.textContent = '';

        const paragraph = document.createElement('p');
        paragraph.className = 'st-history-empty';
        paragraph.textContent = message;

        el.historyList.appendChild(paragraph);
    }

    function renderHistory(history) {
        if (!Array.isArray(history) || history.length === 0) {
            showHistoryMessage('Belum ada hasil speed test tersimpan.');
            return;
        }

        el.historyList.textContent = '';

        history.forEach(function (item) {
            const row = document.createElement('article');
            row.className = 'st-history-row';

            const time = document.createElement('time');
            time.textContent = formatHistoryDate(item.tested_at);

            const cells = [
                ['bi-download', 'st-cell-download', numberOrNull(item.download_mbps, 2, ' Mbps')],
                ['bi-upload', 'st-cell-upload', numberOrNull(item.upload_mbps, 2, ' Mbps')],
                ['bi-broadcast-pin', '', numberOrNull(item.ping_ms, 0, ' ms')],
                ['bi-activity', '', numberOrNull(item.jitter_ms, 1, ' ms')]
            ];

            row.appendChild(time);

            cells.forEach(function (cell) {
                const span = document.createElement('span');
                span.className = cell[1];
                span.appendChild(createIcon(cell[0]));
                span.appendChild(document.createTextNode(' ' + cell[2]));
                row.appendChild(span);
            });

            el.historyList.appendChild(row);
        });
    }

    async function readJson(response) {
        try {
            return await response.json();
        } catch (error) {
            return {};
        }
    }

    async function loadHistory() {
        try {
            const response = await fetch('speedtest_history.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const payload = await readJson(response);

            if (!response.ok || !Array.isArray(payload.history)) {
                throw new Error(payload.error || 'Riwayat speed test tidak dapat dimuat.');
            }

            renderHistory(payload.history);
            el.historyStatus.textContent = '';
        } catch (error) {
            console.error('Gagal memuat riwayat speed test:', error);
            el.historyStatus.textContent = 'Riwayat gagal dimuat.';
            showHistoryMessage('Riwayat belum dapat dimuat. Coba muat ulang halaman.');
        }
    }

    async function saveHistory(result) {
        el.historyStatus.textContent = 'Menyimpan hasil...';

        const response = await fetch('speedtest_history.php', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-YESNET-CSRF': speedtestCsrf
            },
            credentials: 'same-origin',
            body: JSON.stringify(result)
        });
        const payload = await readJson(response);

        if (!response.ok || !payload.saved) {
            throw new Error(payload.error || 'Hasil speed test gagal disimpan.');
        }

        el.historyStatus.textContent = 'Hasil berhasil disimpan.';
        await loadHistory();
    }

    loadHistory();


    /*
    |----------------------------------------------------------------------
    | MULAI PENGUJIAN
    |----------------------------------------------------------------------
    */

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
        setProgress(0, 'Memulai pengujian');
    }

    el.start.addEventListener('click', async function () {

        if (el.start.disabled) {
            return;
        }

        el.start.disabled = true;
        el.start.setAttribute('aria-busy', 'true');
        el.startText.textContent = 'Sedang menguji...';
        el.startIcon.className = 'bi bi-hourglass-split';

        el.gauge.classList.remove('is-error', 'is-done');
        el.gauge.classList.add('is-running');

        el.state.textContent = 'MENGUJI';
        el.hint.textContent = 'Mohon tunggu, jangan tutup halaman';

        el.testTime.textContent = new Date().toLocaleString('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'medium',
            timeZone: 'Asia/Jakarta'
        }) + ' WIB';

        resetResults();

        try {

            // PING
            setProgress(10, 'Mengukur ping');

            const pingResult = await testPing();

            setText(el.ping, pingResult.ping.toFixed(0));
            setText(el.pingNote, pingLabel(pingResult.ping));

            if (pingResult.jitter === null) {
                setText(el.jitter, '—');
                setText(el.jitterNote, 'Sampel tidak cukup');
            } else {
                setText(el.jitter, pingResult.jitter.toFixed(1));
                setText(el.jitterNote, pingResult.jitter < 10 ? 'Stabil' : 'Fluktuatif');
            }

            setProgress(30, 'Ping selesai');

            // DOWNLOAD
            setText(el.downloadNote, 'Sedang menguji...');
            setProgress(35, 'Mengukur download');

            const download = await testDownload();

            setText(el.download, download.toFixed(2));
            setText(el.downloadNote, downloadLabel(download));
            setGauge(download);

            setProgress(65, 'Download selesai');

            // UPLOAD
            setText(el.uploadNote, 'Sedang menguji...');
            setProgress(70, 'Mengukur upload');

            const upload = await testUpload();

            setText(el.upload, upload.toFixed(2));
            setText(el.uploadNote, uploadLabel(upload));
            setGauge(upload);

            setProgress(100, 'Pengujian selesai');

            el.gauge.classList.remove('is-running');
            el.gauge.classList.add('is-done');
            el.state.textContent = 'SELESAI';
            el.hint.textContent = 'Uji browser ke server aplikasi selesai';

            try {
                await saveHistory({
                    download_mbps: download,
                    upload_mbps: upload,
                    ping_ms: pingResult.ping,
                    jitter_ms: pingResult.jitter
                });
            } catch (saveError) {
                console.error('Gagal menyimpan hasil speed test:', saveError);
                el.historyStatus.textContent = 'Hasil diuji, tetapi riwayat gagal disimpan.';
            }

            el.startText.textContent = 'Uji Lagi';

        } catch (error) {

            console.error(error);

            const timedOut = error && error.name === 'AbortError';

            el.gauge.classList.remove('is-running');
            el.gauge.classList.add('is-error');
            el.state.textContent = 'GAGAL';
            el.hint.textContent = timedOut
                ? 'Pengujian melebihi batas waktu'
                : (error && error.message ? error.message : 'Speed test gagal');

            setText(el.pingNote, 'Belum diuji');
            setText(el.downloadNote, 'Belum diuji');
            setText(el.uploadNote, 'Belum diuji');
            setText(el.jitterNote, 'Belum diuji');

            el.phase.textContent = 'Terjadi kesalahan';
            el.startText.textContent = 'Coba Lagi';

        } finally {

            el.startIcon.className = 'bi bi-lightning-charge-fill';
            el.start.disabled = false;
            el.start.removeAttribute('aria-busy');

        }

    });


    /*
    |----------------------------------------------------------------------
    | MOBILE SIDEBAR
    |----------------------------------------------------------------------
    */

    const sidebar = $('sidebar');
    const overlay = $('sidebarOverlay');
    const mobileButton = $('mobileMenuButton');
    const closeButton = $('sidebarClose');

    function openSidebar() {
        if (!sidebar) {
            return;
        }
        sidebar.classList.add('show');
        if (overlay) {
            overlay.classList.add('show');
        }
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (!sidebar) {
            return;
        }
        sidebar.classList.remove('show');
        if (overlay) {
            overlay.classList.remove('show');
        }
        document.body.classList.remove('sidebar-open');
    }

    if (mobileButton) {
        mobileButton.addEventListener('click', openSidebar);
    }
    if (closeButton) {
        closeButton.addEventListener('click', closeSidebar);
    }
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }


    /*
    |----------------------------------------------------------------------
    | THEME
    |----------------------------------------------------------------------
    */

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
            themeToggleIcon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
        }
    }

    try {
        applyTheme(localStorage.getItem('yesnet-customer-theme') || 'light');
    } catch (error) {
        console.warn('Preferensi tema tidak dapat dibaca.', error);
        applyTheme('light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const nextTheme = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);

            try {
                localStorage.setItem('yesnet-customer-theme', nextTheme);
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });
    }

});

</script>

</body>

</html>