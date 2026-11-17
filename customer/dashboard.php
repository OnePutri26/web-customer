<?php
session_start();
require_once "../config/auth.php";
requireLogin();
require_once "../config/database.php";
requireRole('customer');
date_default_timezone_set('Asia/Jakarta');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ======================== HELPER ======================== */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function formatTanggal($tanggal): string
{
    if (!$tanggal || $tanggal === '-') {
        return '-';
    }
    $time = strtotime((string) $tanggal);
    return $time ? date('d M Y', $time) : e($tanggal);
}

function tableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $t = $conn->real_escape_string($table);
        $r = $conn->query("SHOW TABLES LIKE '{$t}'");
        $cache[$table] = $r && $r->num_rows > 0;
    }
    return $cache[$table];
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        if (!tableExists($conn, $table)) {
            return $cache[$key] = false;
        }
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $r = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
        $cache[$key] = $r && $r->num_rows > 0;
    }
    return $cache[$key];
}

function allColumnsExist(mysqli $conn, string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!columnExists($conn, $table, $column)) {
            return false;
        }
    }
    return tableExists($conn, $table);
}

/** Ambil semua hasil prepared statement (dengan/tanpa mysqlnd). */
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

/** Prepared query singkat: kembalikan array baris. */
function queryRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();
    return $rows;
}

/** Nilai pertama yang terisi dari daftar kolom. */
function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function normalizeSubscriptionStatus($status): string
{
    $status = strtolower(trim((string) $status));

    $map = [
        'active'     => ['active', 'aktif'],
        'pending'    => ['pending', 'proses', 'menunggu_pemasangan', 'menunggu pemasangan'],
        'suspended'  => ['suspended', 'ditangguhkan'],
        'terminated' => ['terminated', 'berakhir', 'nonaktif'],
    ];

    foreach ($map as $normalized => $aliases) {
        if (in_array($status, $aliases, true)) {
            return $normalized;
        }
    }
    return 'belum_berlangganan';
}

/* ======================== CEK LOGIN ======================== */

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/* ======================== DEFAULT DATA ======================== */

$customer = null;
$customerId = 0;
$paketNama = 'Belum Memilih Paket';
$paketSpeed = '-';
$paketHarga = 0;
$totalInstalasi = 0;
$totalGangguan = 0;
$tagihan = null;
$tagihanDataAvailable = false;
$jumlahTagihan = 0;
$jatuhTempo = '-';
$tagihanId = 0;
$riwayatPembayaran = [];
$unreadNotifications = 0;
$latestNotifications = [];
$downloadMbps = $uploadMbps = $pingMs = null;
$bandwidthReadings = [];
$bandwidthTelemetryAvailable = false;
$bandwidthIsLive = false;
$bandwidthDataUpdatedAt = null;

/* ======================== CUSTOMER + PAKET ======================== */

if (!tableExists($conn, 'customers')) {
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Dashboard YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}

$paketColumns = [
    'nama_paket' => 'nama_paket',
    'speed_mbps' => 'speed_mbps',
    'harga'      => 'harga_paket',
    'deskripsi'  => 'deskripsi_paket',
];

$paketTableAvailable = tableExists($conn, 'paket_wifi')
    && columnExists($conn, 'paket_wifi', 'id')
    && columnExists($conn, 'customers', 'paket_id');

$joinPaket = $paketTableAvailable ? "LEFT JOIN paket_wifi p ON p.id = c.paket_id" : "";
$selectPaket = [];
foreach ($paketColumns as $column => $alias) {
    $selectPaket[] = ($paketTableAvailable && columnExists($conn, 'paket_wifi', $column))
        ? "p.`{$column}` AS `{$alias}`"
        : "NULL AS `{$alias}`";
}

try {
    $rows = queryRows(
        $conn,
        "SELECT c.*, " . implode(', ', $selectPaket) . "
         FROM customers c {$joinPaket}
         WHERE c.user_id = ? LIMIT 1",
        'i',
        [$userId]
    );
} catch (Throwable $e) {
    error_log("Dashboard YESNET customer query error: " . $e->getMessage()
        . " | file: " . $e->getFile() . " | line: " . $e->getLine());
    http_response_code(500);
    die("Data dashboard gagal dimuat. Periksa struktur tabel customers dan paket_wifi atau error log hosting.");
}

if ($rows === []) {
    http_response_code(404);
    die("Data customer tidak ditemukan untuk akun ini.");
}

$customer = $rows[0];
$customerId = (int) ($customer['id'] ?? 0);

$nama = trim((string) pick($customer, ['nama', 'name'], $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Customer'));
if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = normalizeSubscriptionStatus($customer['status_langganan'] ?? '');

if (!empty($customer['nama_paket'])) {
    $paketNama = $customer['nama_paket'];
}
if (isset($customer['speed_mbps'])) {
    $paketSpeed = $customer['speed_mbps'] . ' Mbps';
}
if (isset($customer['harga_paket'])) {
    $paketHarga = (float) $customer['harga_paket'];
}

/* ======================== TELEMETRI BANDWIDTH ======================== */
/* Hanya data nyata dari tabel usage_data. Tidak ada nilai contoh. */

if (allColumnsExist($conn, 'usage_data', ['customer_id', 'download_mbps', 'upload_mbps', 'ping_ms', 'recorded_at'])) {
    try {
        $bandwidthReadings = queryRows(
            $conn,
            "SELECT download_mbps, upload_mbps, ping_ms, recorded_at
             FROM usage_data WHERE customer_id = ?
             ORDER BY recorded_at DESC LIMIT 20",
            'i',
            [$customerId]
        );

        if ($bandwidthReadings !== []) {
            $latest = $bandwidthReadings[0];
            $num = static fn ($v) => is_numeric($v) ? (float) $v : null;

            $downloadMbps = $num($latest['download_mbps'] ?? null);
            $uploadMbps = $num($latest['upload_mbps'] ?? null);
            $pingMs = $num($latest['ping_ms'] ?? null);
            $bandwidthDataUpdatedAt = $latest['recorded_at'] ?? null;

            $ts = strtotime((string) $bandwidthDataUpdatedAt);
            $bandwidthIsLive = $ts !== false && $ts <= time() && $ts >= time() - 300;
            $bandwidthTelemetryAvailable = $downloadMbps !== null || $uploadMbps !== null || $pingMs !== null;
            $bandwidthReadings = array_reverse($bandwidthReadings);
        }
    } catch (Throwable $e) {
        error_log('Dashboard YESNET telemetry query failed: ' . $e->getMessage());
    }
}

/* ======================== STATUS DISPLAY ======================== */

$statusMap = [
    'active'             => ['Layanan Aktif', 'active', 'bi-check-circle-fill'],
    'pending'            => ['Menunggu Aktivasi', 'pending', 'bi-hourglass-split'],
    'suspended'          => ['Layanan Ditangguhkan', 'suspended', 'bi-pause-circle-fill'],
    'terminated'         => ['Layanan Berakhir', 'terminated', 'bi-x-circle-fill'],
    'belum_berlangganan' => ['Belum Berlangganan', 'pending', 'bi-cart3'],
];
[$statusLabel, $statusClass, $statusIcon] = $statusMap[$statusLangganan];

/* ======================== TOTAL INSTALASI ======================== */

if ($customerId > 0 && allColumnsExist($conn, 'instalasi', ['id_customer'])) {
    try {
        $rows = queryRows($conn, "SELECT COUNT(*) AS total FROM instalasi WHERE id_customer = ?", 'i', [$customerId]);
        $totalInstalasi = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('Dashboard YESNET instalasi failed: ' . $e->getMessage());
    }
}

/* ======================== NOTIFIKASI ======================== */

if (allColumnsExist($conn, 'notifications', ['id_user', 'dibaca'])) {
    try {
        $rows = queryRows(
            $conn,
            "SELECT COUNT(*) AS total FROM notifications
             WHERE (id_user = ? OR id_user IS NULL) AND dibaca = 0",
            'i',
            [$userId]
        );
        $unreadNotifications = (int) ($rows[0]['total'] ?? 0);

        if (allColumnsExist($conn, 'notifications', ['judul', 'message', 'type', 'created_at'])) {
            $latestNotifications = queryRows(
                $conn,
                "SELECT id, judul, message, type, dibaca, created_at
                 FROM notifications
                 WHERE id_user = ? OR id_user IS NULL
                 ORDER BY created_at DESC LIMIT 4",
                'i',
                [$userId]
            );
        }
    } catch (Throwable $e) {
        error_log('Dashboard YESNET notification failed: ' . $e->getMessage());
    }
}

/* ======================== TOTAL GANGGUAN ======================== */

if ($customerId > 0 && allColumnsExist($conn, 'complaint', ['id_customer'])) {
    try {
        $sql = "SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?";
        if (columnExists($conn, 'complaint', 'status')) {
            $sql .= " AND LOWER(status) NOT IN ('closed', 'resolved')";
        }
        $rows = queryRows($conn, $sql, 'i', [$customerId]);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('Dashboard YESNET complaint failed: ' . $e->getMessage());
    }
}

/* ======================== TAGIHAN AKTIF ======================== */

if ($customerId > 0 && allColumnsExist($conn, 'tagihan', ['id_customer'])) {
    $tagihanDataAvailable = true;

    $sql = "SELECT * FROM tagihan WHERE id_customer = ?";
    if (columnExists($conn, 'tagihan', 'status')) {
        $sql .= " AND LOWER(status) IN ('unpaid', 'pending', 'belum bayar', 'belum dibayar')";
    }
    if (columnExists($conn, 'tagihan', 'jatuh_tempo')) {
        $sql .= " ORDER BY jatuh_tempo ASC";
    }
    $sql .= " LIMIT 1";

    try {
        $rows = queryRows($conn, $sql, 'i', [$customerId]);
        if ($rows !== []) {
            $tagihan = $rows[0];
            $tagihanId = (int) ($tagihan['id'] ?? 0);
            $jumlahTagihan = pick($tagihan, ['jumlah', 'total', 'nominal', 'amount'], 0);
            $jatuhTempo = pick($tagihan, ['jatuh_tempo', 'tanggal_jatuh_tempo'], '-');
        }
    } catch (Throwable $e) {
        error_log('Dashboard YESNET tagihan failed: ' . $e->getMessage());
    }
}

/* ======================== RIWAYAT PEMBAYARAN ======================== */

if ($customerId > 0 && allColumnsExist($conn, 'pembayaran', ['id_customer'])) {
    $order = '';
    if (columnExists($conn, 'pembayaran', 'created_at')) {
        $order = ' ORDER BY created_at DESC';
    } elseif (columnExists($conn, 'pembayaran', 'tanggal_bayar')) {
        $order = ' ORDER BY tanggal_bayar DESC';
    }

    try {
        $riwayatPembayaran = queryRows(
            $conn,
            "SELECT * FROM pembayaran WHERE id_customer = ?{$order} LIMIT 5",
            'i',
            [$customerId]
        );
    } catch (Throwable $e) {
        error_log('Dashboard YESNET pembayaran failed: ' . $e->getMessage());
    }
}

/* ======================== TAMPILAN ======================== */

$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);

$menuMain = [
    ['dashboard.php',                 'bi-grid-fill',            'Dashboard'],
    ['usage.php',                     'bi-router-fill',          'Internet Saya'],
    ['billing.php',                   'bi-receipt-cutoff',       'Tagihan & Pembayaran'],
    ['billing.php#riwayat-pembayaran', 'bi-clock-history',       'Riwayat Pembayaran'],
    ['speedtest.php',                 'bi-speedometer2',         'Speed Test'],
    ['complaint.php',                 'bi-tools',                'Laporan Gangguan'],
    ['network_status.php',            'bi-globe2',               'Status Jaringan'],
    ['upgrade.php',                   'bi-arrow-up-circle-fill', 'Upgrade Paket'],
    ['chat.php',                      'bi-chat-dots-fill',       'Bantuan / Live Chat'],
];

$menuService = match ($statusLangganan) {
    'belum_berlangganan' => [['../paket.php?mode=direct', 'bi-box-seam-fill', 'Pilih Paket']],
    'pending'            => [['installation.php', 'bi-hammer', 'Status Pemasangan']],
    'active'             => [
        ['upgrade.php',         'bi-arrow-up-circle-fill', 'Upgrade Paket'],
        ['service_request.php', 'bi-plus-circle-fill',     'Layanan Tambahan'],
    ],
    default => [],
};

$heroText = match ($statusLangganan) {
    'active'     => 'Semua layanan internet kamu siap dipantau dari satu dashboard.',
    'pending'    => 'Pengajuan pemasangan kamu sedang diproses oleh tim kami.',
    'suspended'  => 'Layanan internet kamu sedang ditangguhkan. Silakan hubungi Customer Service.',
    'terminated' => 'Layanan internet kamu sudah berakhir. Hubungi Customer Service untuk informasi lebih lanjut.',
    default      => 'Belum berlangganan? Pilih paket internet yang sesuai dan mulai perjalanan internet kamu.',
};

[$heroLink, $heroIcon, $heroButton] = match ($statusLangganan) {
    'active'             => ['billing.php', 'bi-receipt', 'Lihat Tagihan'],
    'pending'            => ['installation.php', 'bi-geo-alt', 'Lihat Pemasangan'],
    'belum_berlangganan' => ['../paket.php?mode=direct', 'bi-box-seam', 'Pilih Paket Internet'],
    default              => ['chat.php', 'bi-headset', 'Hubungi CS'],
};

$telemetryLabel = $bandwidthIsLive ? 'LIVE' : ($bandwidthTelemetryAvailable ? 'HISTORIS' : 'BELUM TERSEDIA');
$telemetryClass = $bandwidthIsLive ? 'is-live' : ($bandwidthTelemetryAvailable ? 'is-historical' : 'is-unavailable');

$chartSeries = [
    'labels' => array_map(static function (array $r): string {
        $t = strtotime((string) ($r['recorded_at'] ?? ''));
        return $t ? date('d/m H:i', $t) : '';
    }, $bandwidthReadings),
    'download' => array_map(static fn ($r) => is_numeric($r['download_mbps'] ?? null) ? (float) $r['download_mbps'] : null, $bandwidthReadings),
    'upload' => array_map(static fn ($r) => is_numeric($r['upload_mbps'] ?? null) ? (float) $r['upload_mbps'] : null, $bandwidthReadings),
];

$quickServices = [
    ['billing.php',         'blue',   'bi-receipt-cutoff',       'Tagihan',          'Bayar & cek tagihan'],
    ['speedtest.php',       'purple', 'bi-speedometer2',         'Speed Test',       'Cek kecepatan internet'],
    ['complaint.php',       'orange', 'bi-tools',                'Lapor Gangguan',   'Laporkan masalah jaringan'],
    ['chat.php',            'green',  'bi-chat-dots-fill',       'Chat CS',          'Hubungi customer service'],
    ['upgrade.php',         'blue',   'bi-arrow-up-circle-fill', 'Upgrade Paket',    'Lihat paket yang tersedia'],
    ['service_request.php', 'green',  'bi-plus-circle-fill',     'Layanan Tambahan', 'Ajukan layanan lain'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">
    <title>Dashboard Pelanggan - YESNET</title>

<<<<<<< HEAD
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#0f4cdb"
    >

    <title>
        Dashboard Pelanggan - YESNET
    </title>


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


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="assets/css/dashboard.css"
    >

    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <script defer src="assets/js/portal-ui.js"></script>

=======
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
>>>>>>> 087063f454993fe1c671edb6d2cef597f3d93c20
</head>

<body class="dashboard-page">
<div class="customer-layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo"><i class="bi bi-wifi"></i></div>
            <div class="brand-text">
                <strong>YESNET</strong>
                <span>Customer Portal</span>
            </div>
            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Tutup menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <nav class="sidebar-scroll">
            <div class="menu-section">
                <div class="menu-label">Utama</div>
                <?php foreach ($menuMain as [$href, $icon, $label]): ?>
                    <a href="<?= e($href); ?>"
                       class="menu-item <?= $currentPage === $href ? 'active' : ''; ?>">
                        <span class="menu-icon"><i class="bi <?= e($icon); ?>"></i></span>
                        <span class="menu-text"><?= e($label); ?></span>
                        <?php if ($href === 'complaint.php' && $totalGangguan > 0): ?>
                            <span class="menu-badge"><?= $totalGangguan; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($menuService !== []): ?>
                <div class="menu-section">
                    <div class="menu-label">Layanan</div>
                    <?php foreach ($menuService as [$href, $icon, $label]): ?>
                        <a href="<?= e($href); ?>" class="menu-item">
                            <span class="menu-icon"><i class="bi <?= e($icon); ?>"></i></span>
                            <span class="menu-text"><?= e($label); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="menu-section">
                <div class="menu-label">Akun</div>
                <a href="profile.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-person-circle"></i></span>
                    <span class="menu-text">Pengaturan Akun</span>
                </a>
                <a href="notifikasi.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-bell-fill"></i></span>
                    <span class="menu-text">Notifikasi</span>
                </a>
                <a href="../logout.php" class="menu-item logout-item">
                    <span class="menu-icon"><i class="bi bi-box-arrow-right"></i></span>
                    <span class="menu-text">Keluar</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= e($avatar); ?></div>
            <div class="sidebar-user-info">
                <strong><?= e($nama); ?></strong>
                <span>ID pelanggan #<?= $customerId; ?></span>
            </div>
        </div>
    </aside>

    <!-- ===================== MAIN ===================== -->
    <main class="main-content">

        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-button" id="mobileMenuButton" aria-label="Buka menu">
                    <i class="bi bi-list"></i>
                </button>
                <div>
                    <div class="breadcrumb-text">Customer Portal <span>/</span> Dashboard</div>
                    <h1>Dashboard</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" class="theme-toggle" id="themeToggle"
                        aria-label="Aktifkan tema gelap" aria-pressed="false" title="Ganti tema">
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <a href="notifikasi.php" class="notification-button" title="Notifikasi">
                    <i class="bi bi-bell"></i>
                    <span class="notification-count" id="dashboardUnreadCount"
                        <?= $unreadNotifications > 0 ? '' : 'hidden'; ?>><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                </a>

                <a href="profile.php" class="top-profile" aria-label="Buka pengaturan akun">
                    <div class="top-profile-avatar"><?= e($avatar); ?></div>
                    <div class="top-profile-info">
                        <strong><?= e($nama); ?></strong>
                        <span>ID pelanggan #<?= $customerId; ?></span>
                    </div>
                </a>
            </div>
        </header>

        <div class="dashboard-content">

            <!-- ---------- HERO ---------- -->
            <section class="welcome-hero">
                <div class="hero-decoration hero-decoration-one"></div>
                <div class="hero-decoration hero-decoration-two"></div>

                <div class="hero-content">
                    <div class="hero-label"><span class="hero-label-dot"></span> Manajemen WiFi</div>
                    <h2>Halo, <?= e($nama); ?> 👋</h2>
                    <p><?= e($heroText); ?></p>
                    <div class="hero-actions">
                        <a href="<?= e($heroLink); ?>" class="hero-button">
                            <i class="bi <?= e($heroIcon); ?>"></i> <?= e($heroButton); ?>
                        </a>
                    </div>
                </div>

                <div class="hero-visual" aria-hidden="true">
                    <div class="wifi-orbit orbit-one"></div>
                    <div class="wifi-orbit orbit-two"></div>
                    <div class="wifi-core"><i class="bi bi-wifi"></i></div>
                    <span class="signal-dot dot-one"></span>
                    <span class="signal-dot dot-two"></span>
                    <span class="signal-dot dot-three"></span>
                </div>
            </section>

            <!-- ---------- STATUS STRIP ---------- -->
            <section class="status-strip">
                <div class="status-strip-item">
                    <div class="status-strip-icon blue"><i class="bi bi-shield-check"></i></div>
                    <div><span>Status layanan</span><strong><?= e($statusLabel); ?></strong></div>
                </div>
                <div class="status-divider"></div>
                <div class="status-strip-item">
                    <div class="status-strip-icon green"><i class="bi bi-router"></i></div>
                    <div><span>Paket saat ini</span><strong><?= e($paketNama); ?></strong></div>
                </div>
                <div class="status-divider"></div>
                <div class="status-strip-item">
                    <div class="status-strip-icon purple"><i class="bi bi-lightning-charge"></i></div>
                    <div><span>Kecepatan</span><strong><?= e($paketSpeed); ?></strong></div>
                </div>
            </section>

<?php if ($statusLangganan === 'active'): ?>

            <!-- ---------- RINGKASAN ---------- -->
            <div class="section-title-row">
                <div>
                    <span class="section-kicker">Ringkasan</span>
                    <h2>Ringkasan layanan</h2>
                </div>
                <span class="section-date">
                    <i class="bi bi-calendar3"></i>
                    <span id="dashboardRefreshStatus">Data pelanggan</span>
                </span>
            </div>

            <div class="stats-grid">

                <!-- Tagihan -->
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-receipt"></i></div>
                        <span class="stat-tag">Bulan ini</span>
                    </div>

                    <div class="stat-value">
                        <span id="dashboardInvoiceAmount"><?= !empty($tagihan)
                            ? rupiah($jumlahTagihan)
                            : ($tagihanDataAvailable ? 'Tidak ada tagihan' : 'Tagihan belum tersedia'); ?></span>
                    </div>

                    <div class="stat-label" id="dashboardInvoiceStatus">
                        <?= !empty($tagihan)
                            ? 'Tagihan belum dibayar'
                            : ($tagihanDataAvailable ? 'Tidak ada tagihan tertunda' : 'Status tagihan belum tersedia'); ?>
                    </div>

                    <a id="dashboardPayButton" class="pay-button"
                       href="billing.php?bayar=<?= $tagihanId; ?>"
                       <?= ($tagihanId > 0 && !empty($tagihan)) ? '' : 'hidden'; ?>>
                        <i class="bi bi-credit-card-2-front-fill"></i> Bayar online
                    </a>

                    <div class="stat-bottom">
                        <span>
                            <i class="bi bi-calendar-event"></i>
                            Jatuh tempo:
                            <span id="dashboardInvoiceDueDate"><?= !empty($tagihan) ? formatTanggal($jatuhTempo) : '—'; ?></span>
                        </span>
                        <a href="billing.php">Detail <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                </div>

                <!-- Kecepatan -->
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-speedometer2"></i></div>
                        <span id="dashboardSpeedStatus" class="stat-tag <?= $bandwidthIsLive ? 'green' : ''; ?>">
                            <?= e($telemetryLabel); ?>
                        </span>
                    </div>

                    <div class="stat-value">
                        <span id="dashboardDownloadSummary"><?= $downloadMbps !== null ? number_format($downloadMbps, 1) : '—'; ?></span>
                        <small>Mbps</small>
                    </div>

                    <div class="stat-label">Kecepatan download</div>

                    <div class="stat-bottom">
                        <span id="dashboardSpeedUpdatedAt">
                            <?= $bandwidthTelemetryAvailable
                                ? 'Terakhir diperbarui ' . formatTanggal($bandwidthDataUpdatedAt)
                                : 'Telemetri belum terhubung'; ?>
                        </span>
                    </div>
                </div>

                <!-- Gangguan -->
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon orange"><i class="bi bi-tools"></i></div>
                        <span class="stat-tag">Aktif</span>
                    </div>
                    <div class="stat-value"><?= $totalGangguan; ?></div>
                    <div class="stat-label">Laporan gangguan</div>
                    <div class="stat-bottom">
                        <span><i class="bi bi-activity"></i> Perlu ditangani</span>
                        <a href="complaint.php">Detail <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                </div>

                <!-- Instalasi -->
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-hammer"></i></div>
                        <span class="stat-tag green">Total</span>
                    </div>
                    <div class="stat-value"><?= $totalInstalasi; ?></div>
                    <div class="stat-label">Riwayat instalasi</div>
                    <div class="stat-bottom">
                        <span><i class="bi bi-check2-circle"></i> Tercatat</span>
                        <a href="installation.php">Detail <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                </div>
            </div>

            <!-- ---------- GRID UTAMA ---------- -->
            <div class="main-grid">

                <div class="main-column">

                    <!-- Bandwidth -->
                    <div class="dashboard-card bandwidth-card">
                        <div class="card-heading">
                            <div>
                                <span class="card-kicker">Monitoring jaringan</span>
                                <h3>Aktivitas bandwidth</h3>
                                <p>Pantau performa koneksi internet secara berkala.</p>
                            </div>
                            <div id="bandwidthLiveStatus" class="live-status <?= $telemetryClass; ?>">
                                <span></span>
                                <span id="bandwidthLiveLabel"><?= e($telemetryLabel); ?></span>
                            </div>
                        </div>

                        <div class="chart-summary">
                            <div>
                                <span>Download</span>
                                <strong>
                                    <span id="dashboardDownloadValue"><?= $downloadMbps !== null ? number_format($downloadMbps, 1) : '—'; ?></span>
                                    <small>Mbps</small>
                                </strong>
                            </div>
                            <div>
                                <span>Upload</span>
                                <strong>
                                    <span id="dashboardUploadValue"><?= $uploadMbps !== null ? number_format($uploadMbps, 1) : '—'; ?></span>
                                    <small>Mbps</small>
                                </strong>
                            </div>
                            <div>
                                <span>Ping</span>
                                <strong>
                                    <span id="dashboardPingValue"><?= $pingMs !== null ? number_format($pingMs, 0) : '—'; ?></span>
                                    <small>ms</small>
                                </strong>
                            </div>
                        </div>

                        <div class="chart-container" id="bandwidthChartContainer"
                             <?= $bandwidthTelemetryAvailable ? '' : 'hidden'; ?>>
                            <canvas id="bandwidthChart"></canvas>
                        </div>

                        <div class="telemetry-empty" id="bandwidthTelemetryEmpty"
                             <?= $bandwidthTelemetryAvailable ? 'hidden' : ''; ?>>
                            <i class="bi bi-router"></i>
                            <strong>Data bandwidth belum tersedia</strong>
                            <span>Grafik akan terisi setelah integrasi monitoring jaringan menyimpan data telemetri yang sebenarnya.</span>
                            <a href="usage.php">Lihat riwayat pemakaian</a>
                        </div>

                        <p class="telemetry-caption" id="bandwidthTelemetryCaption">
                            <span id="bandwidthTelemetryMessage"><?= $bandwidthIsLive
                                ? 'Data telemetri diperbarui dalam 5 menit terakhir.'
                                : ($bandwidthTelemetryAvailable
                                    ? 'Menampilkan catatan telemetri, bukan pembacaan live.'
                                    : 'Volume penggunaan harian/bulanan belum tersedia; grafik hanya menampilkan kecepatan telemetri.'); ?></span>
                            Terakhir: <span id="bandwidthUpdatedAt"><?= $bandwidthDataUpdatedAt && strtotime((string) $bandwidthDataUpdatedAt) !== false
                                ? e(date('d M Y, H:i', strtotime((string) $bandwidthDataUpdatedAt))) . ' WIB'
                                : 'Belum ada data'; ?></span>.
                            <a href="usage.php">Lihat riwayat pemakaian</a>
                        </p>
                    </div>

                    <!-- Pembayaran -->
                    <div class="dashboard-card payment-card">
                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">Transaksi</span>
                                <h3>Riwayat pembayaran</h3>
                            </div>
                            <a href="billing.php#riwayat-pembayaran" class="view-all">
                                Lihat semua <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                        <div class="payment-list">
                            <div class="payment-list-head">
                                <span>Tanggal</span><span>Invoice</span><span>Jumlah</span><span>Status</span>
                            </div>

                            <?php if ($riwayatPembayaran === []): ?>
                                <div class="payment-empty">
                                    <div class="empty-icon"><i class="bi bi-receipt"></i></div>
                                    <strong>Belum ada pembayaran</strong>
                                    <span>Riwayat pembayaran kamu akan muncul di sini.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($riwayatPembayaran as $payment): ?>
                                    <?php
                                    $paymentDate = pick($payment, ['tanggal_bayar', 'created_at', 'tanggal'], '-');
                                    $invoice = pick($payment, ['nomor_tagihan', 'invoice', 'nomor_pembayaran', 'kode_pembayaran'], '-');
                                    $amount = pick($payment, ['jumlah', 'nominal', 'total', 'amount'], 0);
                                    $paymentStatus = (string) ($payment['status'] ?? 'Tidak diketahui');
                                    $isSuccess = in_array(strtolower($paymentStatus), ['paid', 'lunas', 'success', 'berhasil'], true);
                                    ?>
                                    <div class="payment-row">
                                        <span><?= formatTanggal($paymentDate); ?></span>
                                        <strong><?= e($invoice); ?></strong>
                                        <span><?= rupiah($amount); ?></span>
                                        <span>
                                            <span class="payment-badge <?= $isSuccess ? 'success' : 'warning'; ?>">
                                                <i class="bi <?= $isSuccess ? 'bi-check-circle-fill' : 'bi-clock-history'; ?>"></i>
                                                <?= e(ucfirst($paymentStatus)); ?>
                                            </span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="side-column">

                    <!-- Paket -->
                    <div class="dashboard-card package-card">
                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">Langganan</span>
                                <h3>Paket internet</h3>
                            </div>
                            <span class="active-pill"><i class="bi bi-check-circle-fill"></i> Aktif</span>
                        </div>

                        <div class="package-visual" aria-hidden="true">
                            <div class="router-icon"><i class="bi bi-router-fill"></i></div>
                            <div class="router-waves"><span></span><span></span><span></span></div>
                        </div>

                        <div class="package-info">
                            <span>Paket saat ini</span>
                            <h4><?= e($paketNama); ?></h4>
                            <div class="package-speed">
                                <strong><?= e($paketSpeed); ?></strong>
                                <span>Kecepatan internet</span>
                            </div>
                            <?php if ($paketHarga > 0): ?>
                                <div class="package-price">
                                    <strong><?= rupiah($paketHarga); ?></strong>
                                    <span>/ bulan</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="upgrade.php" class="full-outline-button">
                            <i class="bi bi-arrow-up-circle"></i> Upgrade paket <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <!-- Jaringan -->
                    <div class="dashboard-card network-card">
                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">Konektivitas</span>
                                <h3>Status jaringan</h3>
                            </div>
                            <span class="network-unavailable" id="areaStatusBadge">BELUM TERHUBUNG</span>
                        </div>

                        <div class="network-main">
                            <div class="network-check network-unknown" id="areaStatusIcon">
                                <i class="bi bi-question-lg" id="areaStatusIconGlyph"></i>
                            </div>
                            <div>
                                <strong id="areaStatusTitle">Status wilayah belum tersedia</strong>
                                <span id="areaStatusDescription">Belum ada integrasi status gangguan atau pemeliharaan jaringan berdasarkan area pelanggan.</span>
                            </div>
                        </div>

                        <div class="network-info">
                            <div><i class="bi bi-broadcast-pin"></i><span id="areaNameLabel">Area pelanggan belum terhubung</span></div>
                            <div><i class="bi bi-info-circle"></i><span id="areaIncidentLabel">Data gangguan ditampilkan jika tersedia.</span></div>
                        </div>

                        <a href="network_status.php" class="network-link">
                            Lihat detail jaringan <i class="bi bi-arrow-up-right"></i>
                        </a>
                    </div>

                    <!-- Notifikasi -->
                    <div class="dashboard-card notification-card">
                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">Info terbaru</span>
                                <h3>Notifikasi terbaru</h3>
                            </div>
                            <a href="notifikasi.php" class="view-all">Semua <i class="bi bi-arrow-right"></i></a>
                        </div>

                        <div class="dashboard-notification-list" id="dashboardNotificationFeed">
                            <?php if ($latestNotifications === []): ?>
                                <div class="notification-empty" role="status">Belum ada notifikasi terbaru.</div>
                            <?php else: ?>
                                <?php foreach ($latestNotifications as $notification): ?>
                                    <?php
                                    $nTime = strtotime((string) ($notification['created_at'] ?? ''));
                                    $nIcon = match (strtolower((string) ($notification['type'] ?? ''))) {
                                        'gangguan'   => 'bi-exclamation-triangle-fill',
                                        'pembayaran' => 'bi-credit-card-fill',
                                        'promo'      => 'bi-gift-fill',
                                        default      => 'bi-info-circle-fill',
                                    };
                                    ?>
                                    <a class="dashboard-notification <?= (int) ($notification['dibaca'] ?? 1) === 0 ? 'is-unread' : ''; ?>"
                                       href="notifikasi.php">
                                        <span class="notification-icon"><i class="bi <?= e($nIcon); ?>"></i></span>
                                        <span class="notification-copy">
                                            <strong><?= e($notification['judul'] ?? 'Informasi YESNET'); ?></strong>
                                            <span><?= e($notification['message'] ?? ''); ?></span>
                                            <small><?= $nTime !== false ? e(date('d M Y, H:i', $nTime)) . ' WIB' : 'Waktu tidak tersedia'; ?></small>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bantuan -->
                    <div class="dashboard-card help-card">
                        <div class="help-background"></div>
                        <div class="help-content">
                            <div class="help-icon"><i class="bi bi-headset"></i></div>
                            <span class="card-kicker">Customer service</span>
                            <h3>Butuh bantuan?</h3>
                            <p>Chat dengan CS atau tanya chatbot untuk reset password WiFi dan jadwal instalasi.</p>
                            <a href="chat.php" class="help-button">
                                <i class="bi bi-chat-dots-fill"></i> Buka live chat
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---------- LAYANAN CEPAT ---------- -->
            <div class="section-title-row quick-title">
                <div>
                    <span class="section-kicker">Pintasan</span>
                    <h2>Layanan cepat</h2>
                </div>
            </div>

            <div class="quick-services">
                <?php foreach ($quickServices as [$href, $color, $icon, $title, $desc]): ?>
                    <a href="<?= e($href); ?>" class="quick-service">
                        <div class="quick-icon <?= e($color); ?>"><i class="bi <?= e($icon); ?>"></i></div>
                        <div><strong><?= e($title); ?></strong><span><?= e($desc); ?></span></div>
                        <i class="bi bi-arrow-up-right"></i>
                    </a>
                <?php endforeach; ?>
            </div>

<?php elseif ($statusLangganan === 'pending'): ?>

            <section class="state-card pending-state">
                <div class="state-icon"><i class="bi bi-hourglass-split"></i></div>
                <span class="state-label">Pengajuan pemasangan</span>
                <h2>Pengajuan kamu sedang diproses</h2>
                <p>Tim kami sedang memproses pengajuan pemasangan internet kamu. Pantau statusnya melalui halaman pemasangan.</p>

                <div class="state-steps">
                    <div class="state-step done">
                        <span><i class="bi bi-check-lg"></i></span>
                        <strong>Pengajuan diterima</strong>
                        <small>Data kamu sudah tercatat.</small>
                    </div>
                    <div class="state-step active">
                        <span><i class="bi bi-clock"></i></span>
                        <strong>Proses pemasangan</strong>
                        <small>Menunggu proses dari tim teknisi.</small>
                    </div>
                    <div class="state-step">
                        <span><i class="bi bi-wifi"></i></span>
                        <strong>Aktivasi layanan</strong>
                        <small>Layanan aktif setelah instalasi selesai.</small>
                    </div>
                </div>

                <a href="installation.php" class="state-button">
                    <i class="bi bi-geo-alt-fill"></i> Lihat status pemasangan
                </a>
            </section>

<?php elseif ($statusLangganan === 'suspended' || $statusLangganan === 'terminated'): ?>

            <?php $isSuspended = $statusLangganan === 'suspended'; ?>
            <section class="state-card <?= $isSuspended ? 'suspended-state' : 'terminated-state'; ?>">
                <div class="state-icon">
                    <i class="bi <?= $isSuspended ? 'bi-pause-circle-fill' : 'bi-x-circle-fill'; ?>"></i>
                </div>
                <span class="state-label"><?= $isSuspended ? 'Layanan ditangguhkan' : 'Layanan berakhir'; ?></span>
                <h2><?= $isSuspended ? 'Layanan internet sedang ditangguhkan' : 'Layanan internet sudah berakhir'; ?></h2>
                <p>Untuk informasi lebih lanjut mengenai layanan kamu, silakan hubungi Customer Service.</p>
                <a href="chat.php" class="state-button">
                    <i class="bi bi-headset"></i> Hubungi Customer Service
                </a>
            </section>

<?php else: ?>

            <section class="state-card subscription-state">
                <div class="state-visual" aria-hidden="true">
                    <div class="floating-icon icon-one"><i class="bi bi-wifi"></i></div>
                    <div class="floating-icon icon-two"><i class="bi bi-lightning-charge-fill"></i></div>
                    <div class="subscription-main-icon"><i class="bi bi-router-fill"></i></div>
                </div>
                <span class="state-label">Belum berlangganan</span>
                <h2>Siap menikmati internet yang lebih nyaman?</h2>
                <p>Pilih paket WiFi yang sesuai kebutuhan kamu. Setelah memilih paket, lanjutkan proses pemasangan melalui dashboard.</p>
                <a href="../paket.php?mode=direct" class="state-button">
                    <i class="bi bi-box-seam-fill"></i> Pilih paket internet <i class="bi bi-arrow-right"></i>
                </a>
            </section>

<?php endif; ?>

            <footer class="dashboard-footer">
                <span>© <?= date('Y'); ?> YESNET</span>
                <span>Customer Portal</span>
            </footer>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ---------------- GRAFIK BANDWIDTH ---------------- */

    const chartElement = document.getElementById('bandwidthChart');
    let bandwidthChart = null;

    const bandwidthSeries = <?= json_encode(
        $chartSeries,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ); ?>;

    if (chartElement && typeof Chart !== 'undefined') {
        const ctx = chartElement.getContext('2d');

        const gradient = function (rgb, alpha) {
            const g = ctx.createLinearGradient(0, 0, 0, 260);
            g.addColorStop(0, 'rgba(' + rgb + ',' + alpha + ')');
            g.addColorStop(1, 'rgba(' + rgb + ',0)');
            return g;
        };

        const dataset = function (label, data, color, rgb, alpha, width) {
            return {
                label: label,
                data: data,
                borderColor: color,
                backgroundColor: gradient(rgb, alpha),
                borderWidth: width,
                pointRadius: 0,
                pointHoverRadius: 5,
                tension: 0.4,
                fill: true
            };
        };

        bandwidthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: bandwidthSeries.labels,
                datasets: [
                    dataset('Download', bandwidthSeries.download, '#2563eb', '37,99,235', 0.2, 2.5),
                    dataset('Upload', bandwidthSeries.upload, '#8b5cf6', '139,92,246', 0.12, 2)
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 16,
                            boxWidth: 8,
                            font: { family: 'Inter', size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#172033',
                        titleColor: '#ffffff',
                        bodyColor: '#dbe5f4',
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (item) {
                                return item.dataset.label + ': ' + item.formattedValue + ' Mbps';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#94a3b8', font: { family: 'Inter', size: 11 }, maxTicksLimit: 8 }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 100,
                        grid: { color: 'rgba(148,163,184,.14)', drawTicks: false },
                        border: { display: false },
                        ticks: {
                            color: '#94a3b8',
                            padding: 8,
                            font: { family: 'Inter', size: 11 },
                            callback: function (value) { return value + ' Mbps'; }
                        }
                    }
                }
            }
        });
    }

    /* ---------------- SIDEBAR MOBILE ---------------- */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar(open) {
        if (!sidebar) { return; }
        sidebar.classList.toggle('show', open);
        if (overlay) { overlay.classList.toggle('show', open); }
        document.body.classList.toggle('sidebar-open', open);
    }

    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');
    if (mobileButton) { mobileButton.addEventListener('click', function () { toggleSidebar(true); }); }
    if (closeButton) { closeButton.addEventListener('click', function () { toggleSidebar(false); }); }
    if (overlay) { overlay.addEventListener('click', function () { toggleSidebar(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { toggleSidebar(false); }
    });

    /* ---------------- TEMA ---------------- */

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute('aria-label', isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap');
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
            const next = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try {
                localStorage.setItem('yesnet-customer-theme', next);
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });
    }

    /* ---------------- POLLING DATA DASHBOARD ---------------- */

    const dashboardRefreshStatus = document.getElementById('dashboardRefreshStatus');
    let dashboardRequestPending = false;

    function setText(id, value) {
        const element = document.getElementById(id);
        if (element) { element.textContent = value; }
    }

    function formatDate(value) {
        if (!value) { return 'Belum ada data'; }

        const normalized = String(value).replace(' ', 'T');
        const date = new Date(/[zZ]|[+-]\d\d:\d\d$/.test(normalized) ? normalized : normalized + '+07:00');
        if (Number.isNaN(date.getTime())) { return 'Belum ada data'; }

        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta'
        }).format(date) + ' WIB';
    }

    function formatRupiah(value) {
        const amount = Number(value);
        if (!Number.isFinite(amount)) { return 'Rp 0'; }
        return new Intl.NumberFormat('id-ID', {
            style: 'currency', currency: 'IDR', maximumFractionDigits: 0
        }).format(amount);
    }

    function metric(available, value, digits) {
        return available && value !== null && value !== undefined
            ? Number(value).toFixed(digits)
            : '—';
    }

    function updateTelemetry(telemetry) {
        const available = Boolean(telemetry && telemetry.available);
        const live = available && Boolean(telemetry.is_live);
        const label = live ? 'LIVE' : (available ? 'HISTORIS' : 'BELUM TERSEDIA');

        setText('dashboardDownloadValue', metric(available, telemetry.download_mbps, 1));
        setText('dashboardDownloadSummary', metric(available, telemetry.download_mbps, 1));
        setText('dashboardUploadValue', metric(available, telemetry.upload_mbps, 1));
        setText('dashboardPingValue', metric(available, telemetry.ping_ms, 0));
        setText('bandwidthLiveLabel', label);
        setText('dashboardSpeedUpdatedAt', available
            ? 'Terakhir diperbarui ' + formatDate(telemetry.updated_at)
            : 'Telemetri belum terhubung');

        const speedStatus = document.getElementById('dashboardSpeedStatus');
        if (speedStatus) {
            speedStatus.textContent = label;
            speedStatus.classList.toggle('green', live);
        }

        const status = document.getElementById('bandwidthLiveStatus');
        if (status) {
            status.classList.toggle('is-live', live);
            status.classList.toggle('is-historical', available && !live);
            status.classList.toggle('is-unavailable', !available);
        }

        const chartContainer = document.getElementById('bandwidthChartContainer');
        const emptyState = document.getElementById('bandwidthTelemetryEmpty');
        if (chartContainer) { chartContainer.hidden = !available; }
        if (emptyState) { emptyState.hidden = available; }

        const readings = Array.isArray(telemetry && telemetry.readings) ? telemetry.readings : [];
        if (bandwidthChart && available) {
            bandwidthChart.data.labels = readings.map(function (r) {
                return formatDate(r.recorded_at).replace(/ WIB$/, '');
            });
            bandwidthChart.data.datasets[0].data = readings.map(function (r) { return r.download_mbps; });
            bandwidthChart.data.datasets[1].data = readings.map(function (r) { return r.upload_mbps; });
            bandwidthChart.update('none');
        }

        setText('bandwidthTelemetryMessage', live
            ? 'Data telemetri diperbarui dalam 5 menit terakhir.'
            : (available
                ? 'Menampilkan catatan telemetri, bukan pembacaan live.'
                : 'Volume penggunaan harian/bulanan belum tersedia; grafik hanya menampilkan kecepatan telemetri.'));
        setText('bandwidthUpdatedAt', formatDate(telemetry && telemetry.updated_at));
    }

    function updateNetwork(network) {
        if (!network) { return; }

        const state = network.available ? network.status : 'unknown';
        const labels = { normal: 'NORMAL', outage: 'GANGGUAN', maintenance: 'MAINTENANCE', unknown: 'BELUM TERSEDIA' };
        const icons = {
            normal: 'bi-check-lg',
            outage: 'bi-exclamation-lg',
            maintenance: 'bi-wrench-adjustable',
            unknown: 'bi-question-lg'
        };

        setText('areaStatusTitle', network.title || 'Status wilayah belum tersedia');
        setText('areaStatusDescription', network.description || 'Informasi status jaringan belum tersedia.');
        setText('areaNameLabel', network.area ? 'Wilayah: ' + network.area : 'Area pelanggan belum terhubung');
        setText('areaIncidentLabel', network.estimated_resolution
            ? 'Estimasi pemulihan: ' + formatDate(network.estimated_resolution)
            : (state === 'normal'
                ? 'Tidak ada gangguan aktif menurut data area.'
                : 'Estimasi pemulihan belum tersedia.'));
        setText('areaStatusBadge', labels[state] || labels.unknown);

        const badge = document.getElementById('areaStatusBadge');
        const statusIcon = document.getElementById('areaStatusIcon');
        const glyph = document.getElementById('areaStatusIconGlyph');
        if (badge) { badge.className = 'network-unavailable network-status-' + state; }
        if (statusIcon) { statusIcon.className = 'network-check network-' + state; }
        if (glyph) { glyph.className = 'bi ' + (icons[state] || icons.unknown); }
    }

    function updateNotifications(notifications, unreadCount) {
        const feed = document.getElementById('dashboardNotificationFeed');
        const badge = document.getElementById('dashboardUnreadCount');

        if (badge) {
            const count = Number(unreadCount) || 0;
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count === 0;
        }
        if (!feed) { return; }

        feed.textContent = '';

        if (!Array.isArray(notifications) || notifications.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'notification-empty';
            empty.setAttribute('role', 'status');
            empty.textContent = 'Belum ada notifikasi terbaru.';
            feed.appendChild(empty);
            return;
        }

        const icons = {
            gangguan: 'bi-exclamation-triangle-fill',
            pembayaran: 'bi-credit-card-fill',
            promo: 'bi-gift-fill'
        };

        notifications.forEach(function (n) {
            const link = document.createElement('a');
            link.className = 'dashboard-notification' + (Number(n.dibaca) === 0 ? ' is-unread' : '');
            link.href = 'notifikasi.php';

            const icon = document.createElement('span');
            icon.className = 'notification-icon';
            const glyph = document.createElement('i');
            glyph.className = 'bi ' + (icons[String(n.type || '').toLowerCase()] || 'bi-info-circle-fill');
            icon.appendChild(glyph);

            const copy = document.createElement('span');
            copy.className = 'notification-copy';
            const title = document.createElement('strong');
            title.textContent = String(n.judul || 'Informasi YESNET');
            const message = document.createElement('span');
            message.textContent = String(n.message || '');
            const time = document.createElement('small');
            time.textContent = formatDate(n.created_at);

            copy.append(title, message, time);
            link.append(icon, copy);
            feed.appendChild(link);
        });
    }

    function updateInvoice(invoice) {
        setText('dashboardInvoiceAmount', invoice.has_open
            ? formatRupiah(invoice.amount)
            : (invoice.available ? 'Tidak ada tagihan' : 'Tagihan belum tersedia'));
        setText('dashboardInvoiceStatus', invoice.has_open
            ? 'Tagihan belum dibayar'
            : (invoice.available ? 'Tidak ada tagihan tertunda' : 'Status tagihan belum tersedia'));
        setText('dashboardInvoiceDueDate', invoice.has_open && invoice.due_date
            ? formatDate(invoice.due_date).replace(/, \d\d\.\d\d WIB$/, '')
            : '—');

        const payButton = document.getElementById('dashboardPayButton');
        if (payButton) {
            const canPay = Boolean(invoice.has_open && invoice.id > 0);
            payButton.hidden = !canPay;
            if (canPay) {
                payButton.href = 'billing.php?bayar=' + encodeURIComponent(invoice.id);
            }
        }
    }

    async function refreshDashboardData() {
        if (dashboardRequestPending) { return; }
        dashboardRequestPending = true;

        try {
            const response = await fetch('dashboard_data.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.data) {
                throw new Error(payload.error || 'Data dashboard gagal dimuat.');
            }

            updateTelemetry(payload.data.telemetry || {});
            updateNetwork(payload.data.network || {});
            updateNotifications(payload.data.notifications || [], payload.data.unread_notifications || 0);
            updateInvoice(payload.data.invoice || {});

            if (dashboardRefreshStatus) {
                dashboardRefreshStatus.textContent = 'Diperbarui ' + formatDate(payload.data.checked_at);
            }
        } catch (error) {
            console.error('Pembaruan dashboard gagal:', error);
            if (dashboardRefreshStatus) {
                dashboardRefreshStatus.textContent = 'Gagal memperbarui data';
            }
        } finally {
            dashboardRequestPending = false;
        }
    }

    refreshDashboardData();
    window.setInterval(refreshDashboardData, 30000);
});
</script>

</body>
</html>