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

function formatAngka($value, int $decimals = 1): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function formatTanggal($tanggal): string
{
    if (!$tanggal || $tanggal === '-') {
        return '-';
    }

    $time = strtotime((string) $tanggal);

    return $time ? date('d M Y', $time) : e($tanggal);
}

function formatWaktu($tanggal): string
{
    $time = $tanggal ? strtotime((string) $tanggal) : false;

    return $time ? date('d M Y, H:i', $time) . ' WIB' : 'Belum ada data';
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
| DEFAULT DATA
|--------------------------------------------------------------------------
*/

$customer = null;
$customerId = 0;

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Customer';

$statusLangganan = 'belum_berlangganan';

$paketNama = 'Belum Memilih Paket';
$paketSpeedMbps = null;
$paketHarga = 0;

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
    error_log("Usage YESNET: kolom customers.user_id tidak ditemukan.");
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
    'harga'      => 'harga_paket',
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
        "Usage YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data pemakaian gagal dimuat. Periksa struktur tabel customers dan paket_wifi atau error log hosting.");
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
    $paketSpeedMbps = (float) $customer['speed_mbps'];
}

if (isset($customer['harga_paket']) && is_numeric($customer['harga_paket'])) {
    $paketHarga = (float) $customer['harga_paket'];
}

$paketSpeedLabel = $paketSpeedMbps !== null && $paketSpeedMbps > 0
    ? formatAngka($paketSpeedMbps, 0) . ' Mbps'
    : '-';


/*
|--------------------------------------------------------------------------
| RENTANG WAKTU
|--------------------------------------------------------------------------
*/

$allowedRanges = [7, 14, 30];
$range = (int) ($_GET['range'] ?? 7);

if (!in_array($range, $allowedRanges, true)) {
    $range = 7;
}

$startDate = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';


/*
|--------------------------------------------------------------------------
| TELEMETRI usage_data
|--------------------------------------------------------------------------
| Hanya data asli dari tabel usage_data. Tidak ada nilai contoh.
*/

$usageColumns = ['customer_id', 'download_mbps', 'upload_mbps', 'ping_ms', 'recorded_at'];

$hasUsageSchema = tableExists($conn, 'usage_data');

foreach ($usageColumns as $usageColumn) {
    $hasUsageSchema = $hasUsageSchema && columnExists($conn, 'usage_data', $usageColumn);
}

$avgDownload = null;
$avgUpload = null;
$avgPing = null;
$maxDownload = null;
$maxUpload = null;
$minPing = null;
$totalRecords = 0;

$dailyRows = [];
$usageHistory = [];
$lastReadingAt = null;

if ($hasUsageSchema) {
    try {
        // Ringkasan
        $stmt = $conn->prepare("
            SELECT
                AVG(download_mbps) AS avg_download,
                AVG(upload_mbps)   AS avg_upload,
                AVG(ping_ms)       AS avg_ping,
                MAX(download_mbps) AS max_download,
                MAX(upload_mbps)   AS max_upload,
                MIN(ping_ms)       AS min_ping,
                COUNT(*)           AS total_records
            FROM usage_data
            WHERE customer_id = ?
              AND recorded_at >= ?
              AND recorded_at < ?
        ");
        $stmt->bind_param("iss", $customerId, $startDateTime, $endDateTime);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();

        $summary = $rows[0] ?? [];
        $totalRecords = (int) ($summary['total_records'] ?? 0);

        if ($totalRecords > 0) {
            $avgDownload = is_numeric($summary['avg_download'] ?? null) ? (float) $summary['avg_download'] : null;
            $avgUpload = is_numeric($summary['avg_upload'] ?? null) ? (float) $summary['avg_upload'] : null;
            $avgPing = is_numeric($summary['avg_ping'] ?? null) ? (float) $summary['avg_ping'] : null;
            $maxDownload = is_numeric($summary['max_download'] ?? null) ? (float) $summary['max_download'] : null;
            $maxUpload = is_numeric($summary['max_upload'] ?? null) ? (float) $summary['max_upload'] : null;
            $minPing = is_numeric($summary['min_ping'] ?? null) ? (float) $summary['min_ping'] : null;
        }

        // Rata-rata harian untuk grafik
        $stmt = $conn->prepare("
            SELECT
                DATE(recorded_at)  AS tanggal,
                AVG(download_mbps) AS download_mbps,
                AVG(upload_mbps)   AS upload_mbps,
                COUNT(*)           AS total_data
            FROM usage_data
            WHERE customer_id = ?
              AND recorded_at >= ?
              AND recorded_at < ?
            GROUP BY DATE(recorded_at)
            ORDER BY tanggal ASC
        ");
        $stmt->bind_param("iss", $customerId, $startDateTime, $endDateTime);
        $stmt->execute();
        foreach (statementFetchAll($stmt) as $row) {
            $dailyRows[(string) $row['tanggal']] = $row;
        }
        $stmt->close();

        // Riwayat pengukuran terbaru
        $stmt = $conn->prepare("
            SELECT download_mbps, upload_mbps, ping_ms, recorded_at
            FROM usage_data
            WHERE customer_id = ?
              AND recorded_at >= ?
              AND recorded_at < ?
            ORDER BY recorded_at DESC
            LIMIT 15
        ");
        $stmt->bind_param("iss", $customerId, $startDateTime, $endDateTime);
        $stmt->execute();
        $usageHistory = statementFetchAll($stmt);
        $stmt->close();

        // Pembacaan terakhir (di luar rentang sekalipun)
        $stmt = $conn->prepare("
            SELECT MAX(recorded_at) AS last_at
            FROM usage_data
            WHERE customer_id = ?
        ");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();

        $lastReadingAt = $rows[0]['last_at'] ?? null;
    } catch (Throwable $e) {
        error_log('Usage YESNET telemetry query failed: ' . $e->getMessage());
    }
}

$hasUsageData = $totalRecords > 0;

$lastReadingTs = $lastReadingAt ? strtotime((string) $lastReadingAt) : false;
$isLive = $lastReadingTs !== false
    && $lastReadingTs <= time()
    && $lastReadingTs >= time() - 300;

$telemetryState = $isLive ? 'is-live' : ($lastReadingTs !== false ? 'is-historical' : 'is-unavailable');
$telemetryLabel = $isLive ? 'LIVE' : ($lastReadingTs !== false ? 'HISTORIS' : 'BELUM TERSEDIA');


/*
|--------------------------------------------------------------------------
| DATA GRAFIK (hari tanpa data = null, bukan nilai karangan)
|--------------------------------------------------------------------------
*/

$chartLabels = [];
$chartDownload = [];
$chartUpload = [];
$chartPackage = [];

for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d M', strtotime($day));

    $dayRow = $dailyRows[$day] ?? null;

    $chartDownload[] = $dayRow && is_numeric($dayRow['download_mbps'])
        ? round((float) $dayRow['download_mbps'], 2)
        : null;
    $chartUpload[] = $dayRow && is_numeric($dayRow['upload_mbps'])
        ? round((float) $dayRow['upload_mbps'], 2)
        : null;
    $chartPackage[] = $paketSpeedMbps !== null && $paketSpeedMbps > 0 ? $paketSpeedMbps : null;
}

$daysWithData = count($dailyRows);

$downloadVsPackage = $hasUsageData
    && $avgDownload !== null
    && $paketSpeedMbps !== null
    && $paketSpeedMbps > 0
    ? ($avgDownload / $paketSpeedMbps) * 100
    : null;


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
        error_log('Usage YESNET complaint count failed: ' . $e->getMessage());
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
        error_log('Usage YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| STATUS DISPLAY
|--------------------------------------------------------------------------
*/

$statusLabels = [
    'active'             => 'Layanan Aktif',
    'pending'            => 'Menunggu Aktivasi',
    'suspended'          => 'Layanan Ditangguhkan',
    'terminated'         => 'Layanan Berakhir',
    'belum_berlangganan' => 'Belum Berlangganan',
];

$statusLabel = $statusLabels[$statusLangganan] ?? 'Belum Berlangganan';


/*
|--------------------------------------------------------------------------
| AVATAR & CURRENT PAGE
|--------------------------------------------------------------------------
*/

$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));

$currentPage = basename($_SERVER['PHP_SELF']);

$chartPayload = [
    'labels'   => $chartLabels,
    'download' => $chartDownload,
    'upload'   => $chartUpload,
    'package'  => $chartPackage,
];

?>
<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#0f4cdb">

    <title>Internet Saya - YESNET</title>

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

    <!-- Usage CSS (khusus halaman ini) -->
    <link rel="stylesheet" href="assets/css/usage.css?v=6">

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

                <a href="usage.php" class="menu-item <?= $currentPage === 'usage.php' ? 'active' : ''; ?>" aria-current="page">
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

                <a href="speedtest.php" class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>">
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
                        Internet Saya
                    </div>

                    <h1>Internet Saya</h1>
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


            <!-- =================================================
                 HEADER HALAMAN + FILTER RENTANG
            ================================================== -->

            <div class="section-title-row usage-head">

                <div>
                    <span class="section-kicker">MONITORING</span>
                    <h2>Pemakaian Internet</h2>
                </div>

                <nav class="range-tabs" aria-label="Rentang waktu">
                    <?php foreach ($allowedRanges as $option): ?>
                        <a
                            href="usage.php?range=<?= $option; ?>"
                            class="range-tab <?= $range === $option ? 'active' : ''; ?>"
                            <?= $range === $option ? 'aria-current="true"' : ''; ?>
                        ><?= $option; ?> hari</a>
                    <?php endforeach; ?>
                </nav>

            </div>


            <!-- =================================================
                 STATISTIK
            ================================================== -->

            <div class="stats-grid">

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-arrow-down-circle-fill"></i></div>
                        <span class="stat-tag">RATA-RATA</span>
                    </div>
                    <div class="stat-value">
                        <?= $avgDownload !== null ? formatAngka($avgDownload, 1) : '—'; ?>
                        <small>Mbps</small>
                    </div>
                    <div class="stat-label">Kecepatan download</div>
                    <div class="stat-bottom">
                        <span>
                            <i class="bi bi-arrow-up-short"></i>
                            Puncak <?= $maxDownload !== null ? formatAngka($maxDownload, 1) . ' Mbps' : '—'; ?>
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-arrow-up-circle-fill"></i></div>
                        <span class="stat-tag">RATA-RATA</span>
                    </div>
                    <div class="stat-value">
                        <?= $avgUpload !== null ? formatAngka($avgUpload, 1) : '—'; ?>
                        <small>Mbps</small>
                    </div>
                    <div class="stat-label">Kecepatan upload</div>
                    <div class="stat-bottom">
                        <span>
                            <i class="bi bi-arrow-up-short"></i>
                            Puncak <?= $maxUpload !== null ? formatAngka($maxUpload, 1) . ' Mbps' : '—'; ?>
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-activity"></i></div>
                        <span class="stat-tag green">RATA-RATA</span>
                    </div>
                    <div class="stat-value">
                        <?= $avgPing !== null ? formatAngka($avgPing, 0) : '—'; ?>
                        <small>ms</small>
                    </div>
                    <div class="stat-label">Latensi (ping)</div>
                    <div class="stat-bottom">
                        <span>
                            <i class="bi bi-arrow-down-short"></i>
                            Terbaik <?= $minPing !== null ? formatAngka($minPing, 0) . ' ms' : '—'; ?>
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon orange"><i class="bi bi-clipboard-data"></i></div>
                        <span class="stat-tag"><?= (int) $range; ?> HARI</span>
                    </div>
                    <div class="stat-value"><?= (int) $totalRecords; ?></div>
                    <div class="stat-label">Pengukuran tercatat</div>
                    <div class="stat-bottom">
                        <span>
                            <i class="bi bi-calendar-check"></i>
                            <?= (int) $daysWithData; ?> dari <?= (int) $range; ?> hari ada data
                        </span>
                    </div>
                </div>

            </div>


            <!-- =================================================
                 GRID UTAMA
            ================================================== -->

            <div class="main-grid">

                <!-- KIRI -->
                <div class="main-column">

                    <div class="dashboard-card bandwidth-card">

                        <div class="card-heading">

                            <div>
                                <span class="card-kicker">NETWORK MONITORING</span>
                                <h3>Performa Koneksi</h3>
                                <p>Rata-rata kecepatan per hari selama <?= (int) $range; ?> hari terakhir.</p>
                            </div>

                            <div class="live-status <?= e($telemetryState); ?>">
                                <span></span>
                                <span><?= e($telemetryLabel); ?></span>
                            </div>

                        </div>


                        <div class="chart-summary">

                            <div>
                                <span>Download</span>
                                <strong>
                                    <?= $avgDownload !== null ? formatAngka($avgDownload, 1) : '—'; ?>
                                    <small>Mbps</small>
                                </strong>
                            </div>

                            <div>
                                <span>Upload</span>
                                <strong>
                                    <?= $avgUpload !== null ? formatAngka($avgUpload, 1) : '—'; ?>
                                    <small>Mbps</small>
                                </strong>
                            </div>

                            <div>
                                <span>Ping</span>
                                <strong>
                                    <?= $avgPing !== null ? formatAngka($avgPing, 0) : '—'; ?>
                                    <small>ms</small>
                                </strong>
                            </div>

                            <div>
                                <span>Paket</span>
                                <strong>
                                    <?= $paketSpeedMbps !== null && $paketSpeedMbps > 0 ? formatAngka($paketSpeedMbps, 0) : '—'; ?>
                                    <small>Mbps</small>
                                </strong>
                            </div>

                        </div>


                        <div class="chart-container" <?= $hasUsageData ? '' : 'hidden'; ?>>
                            <canvas id="usageChart"></canvas>
                        </div>

                        <div class="telemetry-empty" <?= $hasUsageData ? 'hidden' : ''; ?>>
                            <i class="bi bi-router"></i>
                            <strong>Belum ada data pemakaian</strong>
                            <span>
                                <?php if (!$hasUsageSchema): ?>
                                    Tabel telemetri belum tersedia. Grafik akan terisi setelah integrasi
                                    monitoring jaringan menyimpan data pengukuran.
                                <?php elseif ($lastReadingTs !== false): ?>
                                    Tidak ada pengukuran dalam <?= (int) $range; ?> hari terakhir.
                                    Pengukuran terakhir tercatat pada <?= e(formatWaktu($lastReadingAt)); ?>.
                                    Coba pilih rentang waktu yang lebih panjang.
                                <?php else: ?>
                                    Grafik akan terisi setelah integrasi monitoring jaringan
                                    menyimpan data telemetri yang sebenarnya.
                                <?php endif; ?>
                            </span>
                            <a href="speedtest.php">Jalankan speed test</a>
                        </div>

                        <p class="telemetry-caption">
                            Hari tanpa pengukuran dibiarkan kosong pada grafik.
                            Pengukuran terakhir: <?= e(formatWaktu($lastReadingAt)); ?>.
                        </p>

                    </div>

                </div>


                <!-- KANAN -->
                <div class="side-column">

                    <div class="dashboard-card package-card usage-package">

                        <div class="card-heading compact">

                            <div>
                                <span class="card-kicker">SUBSCRIPTION</span>
                                <h3>Paket Internet</h3>
                            </div>

                            <?php if ($statusLangganan === 'active'): ?>
                                <span class="active-pill">
                                    <i class="bi bi-check-circle-fill"></i>
                                    AKTIF
                                </span>
                            <?php else: ?>
                                <span class="status-pill"><?= e($statusLabel); ?></span>
                            <?php endif; ?>

                        </div>

                        <div class="package-info usage-package-info">

                            <span>PAKET SAAT INI</span>

                            <h4><?= e($paketNama); ?></h4>

                            <div class="package-speed">
                                <strong><?= e($paketSpeedLabel); ?></strong>
                                <span>Kecepatan paket</span>
                            </div>

                            <?php if ($paketHarga > 0): ?>
                                <div class="package-price">
                                    <strong>Rp <?= number_format($paketHarga, 0, ',', '.'); ?></strong>
                                    <span>/ bulan</span>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="usage-meter">

                            <div class="usage-meter-head">
                                <span>Rata-rata download dibanding paket</span>
                                <strong>
                                    <?= $downloadVsPackage !== null ? formatAngka($downloadVsPackage, 0) . '%' : '—'; ?>
                                </strong>
                            </div>

                            <div
                                class="usage-meter-track"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="<?= $downloadVsPackage !== null ? (int) min(100, round($downloadVsPackage)) : 0; ?>"
                            >
                                <span style="width: <?= $downloadVsPackage !== null ? (float) min(100, max(0, $downloadVsPackage)) : 0; ?>%"></span>
                            </div>

                            <small>
                                <?= $downloadVsPackage !== null
                                    ? 'Berdasarkan ' . (int) $totalRecords . ' pengukuran dalam ' . (int) $range . ' hari.'
                                    : 'Perbandingan tersedia setelah ada data pengukuran dan kecepatan paket.'; ?>
                            </small>

                        </div>

                        <a href="upgrade.php" class="full-outline-button">
                            <i class="bi bi-arrow-up-circle"></i>
                            Upgrade Paket
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>


                    <div class="dashboard-card usage-status-card">

                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">TELEMETRI</span>
                                <h3>Status Data</h3>
                            </div>
                        </div>

                        <ul class="usage-facts">

                            <li>
                                <i class="bi bi-broadcast"></i>
                                <span>Status</span>
                                <strong><?= e($telemetryLabel); ?></strong>
                            </li>

                            <li>
                                <i class="bi bi-clock-history"></i>
                                <span>Pembaruan terakhir</span>
                                <strong><?= e(formatWaktu($lastReadingAt)); ?></strong>
                            </li>

                            <li>
                                <i class="bi bi-shield-check"></i>
                                <span>Status layanan</span>
                                <strong><?= e($statusLabel); ?></strong>
                            </li>

                        </ul>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 RIWAYAT PENGUKURAN
            ================================================== -->

            <div class="dashboard-card usage-history">

                <div class="card-heading compact">

                    <div>
                        <span class="card-kicker">DATA PENGUKURAN</span>
                        <h3>Riwayat Pengukuran</h3>
                        <p>Hingga 15 pengukuran terbaru dalam <?= (int) $range; ?> hari terakhir.</p>
                    </div>

                    <a href="speedtest.php" class="view-all">
                        Speed Test <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

                <div class="usage-table-wrap">

                    <table class="usage-table">

                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Download</th>
                                <th>Upload</th>
                                <th>Ping</th>
                                <th>Dari paket</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($usageHistory)): ?>

                            <tr>
                                <td colspan="5" class="usage-empty">
                                    <i class="bi bi-graph-up"></i>
                                    <strong>Belum ada pengukuran</strong>
                                    <span>Hasil pengukuran koneksi akan muncul di sini.</span>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($usageHistory as $history): ?>

                                <?php
                                $dl = is_numeric($history['download_mbps'] ?? null) ? (float) $history['download_mbps'] : null;
                                $ul = is_numeric($history['upload_mbps'] ?? null) ? (float) $history['upload_mbps'] : null;
                                $pg = is_numeric($history['ping_ms'] ?? null) ? (float) $history['ping_ms'] : null;
                                $pct = $dl !== null && $paketSpeedMbps !== null && $paketSpeedMbps > 0
                                    ? ($dl / $paketSpeedMbps) * 100
                                    : null;
                                ?>

                                <tr>
                                    <td data-label="Waktu"><?= e(formatWaktu($history['recorded_at'] ?? null)); ?></td>

                                    <td data-label="Download">
                                        <span class="usage-download">
                                            <i class="bi bi-arrow-down"></i>
                                            <?= $dl !== null ? formatAngka($dl, 2) . ' Mbps' : '—'; ?>
                                        </span>
                                    </td>

                                    <td data-label="Upload">
                                        <span class="usage-upload">
                                            <i class="bi bi-arrow-up"></i>
                                            <?= $ul !== null ? formatAngka($ul, 2) . ' Mbps' : '—'; ?>
                                        </span>
                                    </td>

                                    <td data-label="Ping">
                                        <strong><?= $pg !== null ? formatAngka($pg, 0) . ' ms' : '—'; ?></strong>
                                    </td>

                                    <td data-label="Dari paket">
                                        <?= $pct !== null ? formatAngka($pct, 0) . '%' : '—'; ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <div class="usage-note">
                <i class="bi bi-info-circle"></i>
                <span>
                    Data berasal dari hasil pengukuran koneksi yang tersimpan di sistem.
                    Volume pemakaian harian atau bulanan (GB) belum tersedia, sehingga halaman ini
                    hanya menampilkan kecepatan dan latensi.
                </span>
            </div>


            <footer class="dashboard-footer">
                <span>© <?= date('Y'); ?> YESNET</span>
                <span>Customer Portal</span>
            </footer>

        </div>

    </main>

</div>


<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |----------------------------------------------------------------------
    | USAGE CHART
    |----------------------------------------------------------------------
    */

    const chartElement = document.getElementById('usageChart');
    const usageSeries = <?= json_encode(
        $chartPayload,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ); ?>;

    if (chartElement && typeof Chart !== 'undefined') {

        const ctx = chartElement.getContext('2d');

        const gradientDownload = ctx.createLinearGradient(0, 0, 0, 260);
        gradientDownload.addColorStop(0, 'rgba(37, 99, 235, 0.20)');
        gradientDownload.addColorStop(1, 'rgba(37, 99, 235, 0)');

        const gradientUpload = ctx.createLinearGradient(0, 0, 0, 260);
        gradientUpload.addColorStop(0, 'rgba(139, 92, 246, 0.12)');
        gradientUpload.addColorStop(1, 'rgba(139, 92, 246, 0)');

        const datasets = [
            {
                label: 'Download',
                data: usageSeries.download,
                borderColor: '#2563eb',
                backgroundColor: gradientDownload,
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: 0.35,
                spanGaps: true,
                fill: true
            },
            {
                label: 'Upload',
                data: usageSeries.upload,
                borderColor: '#8b5cf6',
                backgroundColor: gradientUpload,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: 0.35,
                spanGaps: true,
                fill: true
            }
        ];

        if (usageSeries.package.some(function (value) { return value !== null; })) {
            datasets.push({
                label: 'Kecepatan paket',
                data: usageSeries.package,
                borderColor: '#94a3b8',
                borderWidth: 1.5,
                borderDash: [6, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false
            });
        }

        new Chart(ctx, {
            type: 'line',
            data: { labels: usageSeries.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 18,
                            boxWidth: 7,
                            font: { family: 'Inter', size: 10, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#172033',
                        titleColor: '#ffffff',
                        bodyColor: '#dbe5f4',
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        callbacks: {
                            label: function (context) {
                                if (context.parsed.y === null) {
                                    return context.dataset.label + ': tidak ada data';
                                }
                                return context.dataset.label + ': ' +
                                    context.parsed.y.toLocaleString('id-ID') + ' Mbps';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: '#94a3b8',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 10,
                            font: { family: 'Inter', size: 9 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,.12)', drawTicks: false },
                        border: { display: false },
                        ticks: {
                            color: '#94a3b8',
                            padding: 8,
                            font: { family: 'Inter', size: 9 },
                            callback: function (value) {
                                return value + ' Mbps';
                            }
                        }
                    }
                }
            }
        });
    }


    /*
    |----------------------------------------------------------------------
    | MOBILE SIDEBAR
    |----------------------------------------------------------------------
    */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');

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

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

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