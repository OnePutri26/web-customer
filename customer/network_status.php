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
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function tableExists(
    mysqli $conn,
    string $table
): bool {

    $table = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$table}'"
    );

    return $result &&
           $result->num_rows > 0;
}


function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {

    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result &&
           $result->num_rows > 0;
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

    $fields = $metadata->fetch_fields();
    $row = [];
    $bind = [];

    foreach ($fields as $field) {
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


function serviceClass(?bool $status): string
{
    if ($status === null) {
        return 'service-unknown';
    }

    return $status
        ? 'service-online'
        : 'service-offline';
}


function serviceText(?bool $status): string
{
    if ($status === null) {
        return 'Belum tersedia';
    }

    return $status
        ? 'Online'
        : 'Offline';
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) (
    $_SESSION['user_id'] ?? 0
);

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

$nama =
    $_SESSION['nama']
    ??
    $_SESSION['username']
    ??
    'Customer';

$statusLangganan = 'belum_berlangganan';
$totalGangguan = 0;
$unreadNotifications = 0;


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    http_response_code(500);
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Status Jaringan YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}

try {
    $stmt = $conn->prepare("
        SELECT *
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");
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
        "Status Jaringan YESNET customer query error: " .
        $e->getMessage() .
        " | file: " . $e->getFile() .
        " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data halaman gagal dimuat. Periksa struktur tabel customers atau error log hosting.");
}

$customerId = (int) ($customer['id'] ?? 0);

$nama = trim(
    (string) (
        $customer['nama']
        ??
        $customer['name']
        ??
        $_SESSION['nama']
        ??
        $_SESSION['username']
        ??
        'Customer'
    )
);

if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = normalizeSubscriptionStatus(
    $customer['status_langganan'] ?? ''
);

$avatar = strtoupper(
    substr(
        trim($nama) ?: 'C',
        0,
        1
    )
);


/*
|--------------------------------------------------------------------------
| BADGE SIDEBAR: LAPORAN GANGGUAN AKTIF
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists($conn, 'complaint') &&
    columnExists($conn, 'complaint', 'id_customer')
) {
    try {
        $sqlComplaint = "
            SELECT COUNT(*) AS total
            FROM complaint
            WHERE id_customer = ?
        ";

        if (columnExists($conn, 'complaint', 'status')) {
            $sqlComplaint .= "
                AND LOWER(status) NOT IN ('closed', 'resolved')
            ";
        }

        $stmt = $conn->prepare($sqlComplaint);
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Status Jaringan YESNET complaint count failed: ' . $e->getMessage());
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
        error_log('Status Jaringan YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| STATUS SISTEM PORTAL
|--------------------------------------------------------------------------
| Ini hanya status sistem portal, BUKAN status jaringan pelanggan.
| Status jaringan area diambil dari dashboard_data.php (data monitoring).
*/

$databaseStatus = false;

try {
    $conn->query("SELECT 1");
    $databaseStatus = true;
} catch (Throwable $e) {
    $databaseStatus = false;
}

$portalStatus = true;
$internetTelemetryStatus = null;


/*
|--------------------------------------------------------------------------
| CURRENT PAGE
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#0f4cdb"
    >

    <title>
        Status Jaringan - YESNET
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


    <!-- Dashboard CSS (shell bersama) -->

    <link
        rel="stylesheet"
        href="assets/css/dashboard.css"
    >


    <!-- Halaman Status Jaringan -->

    <link
        rel="stylesheet"
        href="assets/css/network_status.css"
    >

</head>


<body class="dashboard-page">

<div class="customer-layout">


    <!-- =====================================================
         MOBILE OVERLAY
    ====================================================== -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

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
                    href="billing.php#riwayat-pembayaran"
                    class="menu-item"
                >
                    <span class="menu-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="menu-text">Riwayat Pembayaran</span>
                </a>

                <a
                    href="speedtest.php"
                    class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>"
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


        <!-- SIDEBAR USER -->

        <div class="sidebar-user">

            <div class="sidebar-user-avatar">
                <?= e($avatar); ?>
            </div>

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


        <!-- TOPBAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="mobileMenuButton"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>

                    <div class="breadcrumb-text">
                        Customer Portal
                        <span>/</span>
                        Status Jaringan
                    </div>

                    <h1>Status Jaringan</h1>

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

                <a
                    href="notifikasi.php"
                    class="notification-button"
                    title="Notifikasi"
                >
                    <i class="bi bi-bell"></i>

                    <span
                        class="notification-count"
                        id="dashboardUnreadCount"
                        <?= $unreadNotifications > 0 ? '' : 'hidden'; ?>
                    ><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                </a>

                <div class="top-profile">

                    <div class="top-profile-avatar">
                        <?= e($avatar); ?>
                    </div>

                    <div class="top-profile-info">
                        <strong><?= e($nama); ?></strong>
                        <span>ID pelanggan #<?= (int) $customerId; ?></span>
                    </div>

                    <i class="bi bi-chevron-down"></i>

                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <div class="dashboard-content">


            <!-- =================================================
                 PAGE HEADING
            ================================================== -->

            <div class="section-title-row net-heading">

                <div>

                    <span class="section-kicker">
                        NETWORK MONITORING
                    </span>

                    <h2>
                        Kondisi Jaringan Area Kamu
                    </h2>

                </div>

                <div class="net-heading-actions">

                    <span class="section-date">
                        <i class="bi bi-calendar3"></i>
                        <span id="networkRefreshStatus">Memuat data...</span>
                    </span>

                    <button
                        type="button"
                        class="net-refresh-button"
                        id="refreshButton"
                    >
                        <i class="bi bi-arrow-clockwise" id="refreshIcon"></i>
                        Refresh Status
                    </button>

                </div>

            </div>


            <!-- =================================================
                 HERO STATUS
            ================================================== -->

            <section
                class="net-hero"
                id="networkHero"
                data-state="unknown"
            >

                <div class="hero-decoration hero-decoration-one"></div>

                <div class="hero-decoration hero-decoration-two"></div>

                <div class="net-hero-left">

                    <div class="net-hero-icon">
                        <i class="bi bi-question-lg" id="networkHeroGlyph"></i>
                    </div>

                    <div class="net-hero-info">

                        <span>STATUS JARINGAN</span>

                        <h2 id="networkTitle">
                            Status area belum tersedia
                        </h2>

                        <p id="networkDescription">
                            Informasi gangguan dan pemeliharaan area belum
                            terhubung ke dashboard.
                        </p>

                    </div>

                </div>

                <div class="net-hero-badge" id="networkBadge">
                    <span class="net-hero-dot"></span>
                    <span id="networkBadgeLabel">BELUM TERSEDIA</span>
                </div>

            </section>


            <!-- =================================================
                 LAYANAN + RINGKASAN
            ================================================== -->

            <div class="main-grid net-grid">

                <div class="main-column">

                    <!-- STATUS LAYANAN -->

                    <div class="dashboard-card">

                        <div class="card-heading compact">

                            <div>
                                <span class="card-kicker">KONEKTIVITAS</span>
                                <h3>Status Layanan</h3>
                            </div>

                            <span class="net-pill" id="integrationPill">
                                STATUS INTEGRASI
                            </span>

                        </div>

                        <div class="service-list">

                            <!-- INTERNET -->

                            <div class="service-item">

                                <div
                                    class="service-icon icon-unknown"
                                    id="internetTelemetryIcon"
                                >
                                    <i class="bi bi-globe2"></i>
                                </div>

                                <div class="service-info">
                                    <strong>Internet</strong>
                                    <span>Telemetri koneksi pelanggan</span>
                                </div>

                                <div
                                    class="<?= e(serviceClass($internetTelemetryStatus)); ?>"
                                    id="internetTelemetryStatus"
                                >
                                    <span></span>
                                    <em><?= e(serviceText($internetTelemetryStatus)); ?></em>
                                </div>

                            </div>

                            <!-- DATABASE -->

                            <div class="service-item">

                                <div class="service-icon <?= $databaseStatus ? 'icon-online' : 'icon-offline'; ?>">
                                    <i class="bi bi-database-check"></i>
                                </div>

                                <div class="service-info">
                                    <strong>Database Portal</strong>
                                    <span>Koneksi database sistem</span>
                                </div>

                                <div class="<?= e(serviceClass($databaseStatus)); ?>">
                                    <span></span>
                                    <em><?= e(serviceText($databaseStatus)); ?></em>
                                </div>

                            </div>

                            <!-- PORTAL -->

                            <div class="service-item">

                                <div class="service-icon icon-online">
                                    <i class="bi bi-hdd-rack"></i>
                                </div>

                                <div class="service-info">
                                    <strong>Portal Customer</strong>
                                    <span>Server aplikasi customer</span>
                                </div>

                                <div class="<?= e(serviceClass($portalStatus)); ?>">
                                    <span></span>
                                    <em><?= e(serviceText($portalStatus)); ?></em>
                                </div>

                            </div>

                        </div>

                        <p class="net-note">
                            <i class="bi bi-info-circle"></i>
                            Database dan Portal hanya menunjukkan kondisi sistem
                            portal, bukan kondisi jaringan internet pelanggan.
                        </p>

                    </div>


                    <!-- RIWAYAT -->

                    <div class="dashboard-card">

                        <div class="card-heading compact">

                            <div>
                                <span class="card-kicker">RIWAYAT</span>
                                <h3>Riwayat Gangguan Area</h3>
                            </div>

                        </div>

                        <div
                            class="net-history-list"
                            id="networkHistory"
                            aria-live="polite"
                        >
                            <p class="net-history-empty">
                                Memuat riwayat area...
                            </p>
                        </div>

                    </div>

                </div>


                <div class="side-column">

                    <!-- DETAIL AREA -->

                    <div class="dashboard-card">

                        <div class="card-heading compact">

                            <div>
                                <span class="card-kicker">DETAIL</span>
                                <h3>Informasi Area</h3>
                            </div>

                        </div>

                        <div class="net-info-list">

                            <div class="net-info-row">
                                <span class="net-info-label">
                                    <i class="bi bi-broadcast-pin"></i>
                                    Wilayah
                                </span>
                                <strong id="infoArea">Belum terhubung</strong>
                            </div>

                            <div class="net-info-row">
                                <span class="net-info-label">
                                    <i class="bi bi-activity"></i>
                                    Status
                                </span>
                                <strong id="infoStatus">Belum tersedia</strong>
                            </div>

                            <div class="net-info-row">
                                <span class="net-info-label">
                                    <i class="bi bi-wrench-adjustable"></i>
                                    Estimasi pemulihan
                                </span>
                                <strong id="infoEstimate">—</strong>
                            </div>

                            <div class="net-info-row">
                                <span class="net-info-label">
                                    <i class="bi bi-speedometer2"></i>
                                    Telemetri terakhir
                                </span>
                                <strong id="infoTelemetry">Belum ada data</strong>
                            </div>

                            <div class="net-info-row">
                                <span class="net-info-label">
                                    <i class="bi bi-clock"></i>
                                    Terakhir diperiksa
                                </span>
                                <strong id="infoChecked">—</strong>
                            </div>

                        </div>

                    </div>


                    <!-- HELP -->

                    <div class="dashboard-card help-card">

                        <div class="help-background"></div>

                        <div class="help-content">

                            <div class="help-icon">
                                <i class="bi bi-headset"></i>
                            </div>

                            <span class="card-kicker">
                                CUSTOMER SERVICE
                            </span>

                            <h3>Internet bermasalah?</h3>

                            <p>
                                Jika kondisi area normal tetapi koneksi kamu
                                tetap terganggu, laporkan atau hubungi tim kami.
                            </p>

                            <a
                                href="chat.php"
                                class="help-button"
                            >
                                <i class="bi bi-chat-dots-fill"></i>
                                Hubungi CS
                            </a>

                        </div>

                    </div>

                </div>

            </div>


            <!-- FOOTER NOTE -->

            <p class="net-footer-note" id="networkUpdatedAt">
                <i class="bi bi-info-circle"></i>
                Status diperbarui otomatis setiap 30 detik.
            </p>


            <!-- =================================================
                 FOOTER
            ================================================== -->

            <footer class="dashboard-footer">

                <span>
                    © <?= date('Y'); ?>
                    YESNET
                </span>

                <span>Customer Portal</span>

            </footer>

        </div>

    </main>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | MOBILE SIDEBAR
    |--------------------------------------------------------------------------
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
    |--------------------------------------------------------------------------
    | THEME
    |--------------------------------------------------------------------------
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
            themeToggleIcon.className = isDark
                ? 'bi bi-sun'
                : 'bi bi-moon-stars';
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
            const nextTheme = document.body.dataset.theme === 'dark'
                ? 'light'
                : 'dark';
            applyTheme(nextTheme);

            try {
                localStorage.setItem('yesnet-customer-theme', nextTheme);
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });
    }


    /*
    |--------------------------------------------------------------------------
    | HELPER
    |--------------------------------------------------------------------------
    */

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value;
        }
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }

        const normalized = String(value).replace(' ', 'T');
        const date = new Date(/[zZ]|[+-]\d\d:\d\d$/.test(normalized)
            ? normalized
            : normalized + '+07:00');

        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDate(value, fallback) {
        const date = parseDate(value);

        if (!date) {
            return fallback || 'Belum ada data';
        }

        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Asia/Jakarta'
        }).format(date) + ' WIB';
    }


    /*
    |--------------------------------------------------------------------------
    | RENDER STATUS
    |--------------------------------------------------------------------------
    */

    const STATE_LABEL = {
        normal: 'NORMAL',
        outage: 'GANGGUAN',
        maintenance: 'MAINTENANCE',
        unknown: 'BELUM TERSEDIA'
    };

    const STATE_TEXT = {
        normal: 'Normal',
        outage: 'Gangguan',
        maintenance: 'Pemeliharaan',
        unknown: 'Belum tersedia'
    };

    const STATE_ICON = {
        normal: 'bi-check-lg',
        outage: 'bi-exclamation-lg',
        maintenance: 'bi-wrench-adjustable',
        unknown: 'bi-question-lg'
    };

    function renderNetwork(network) {
        const available = Boolean(network && network.available);
        let state = available ? String(network.status || 'unknown') : 'unknown';

        if (!STATE_LABEL[state]) {
            state = 'unknown';
        }

        const hero = document.getElementById('networkHero');
        const glyph = document.getElementById('networkHeroGlyph');
        const pill = document.getElementById('integrationPill');

        if (hero) {
            hero.dataset.state = state;
        }

        if (glyph) {
            glyph.className = 'bi ' + STATE_ICON[state];
        }

        setText(
            'networkTitle',
            (available && network.title) || 'Status area belum tersedia'
        );
        setText(
            'networkDescription',
            (available && network.description)
                || 'Informasi gangguan dan pemeliharaan area belum terhubung ke dashboard.'
        );
        setText('networkBadgeLabel', STATE_LABEL[state]);

        if (pill) {
            pill.textContent = available ? 'TERHUBUNG' : 'BELUM TERHUBUNG';
            pill.classList.toggle('is-connected', available);
        }

        setText(
            'infoArea',
            available && network.area ? network.area : 'Belum terhubung'
        );
        setText('infoStatus', STATE_TEXT[state]);
        setText(
            'infoEstimate',
            available && network.estimated_resolution
                ? formatDate(network.estimated_resolution)
                : '—'
        );

        renderHistory(
            Array.isArray(network && network.history) ? network.history : [],
            available
        );
    }

    function renderHistory(history, available) {
        const list = document.getElementById('networkHistory');

        if (!list) {
            return;
        }

        list.textContent = '';

        if (history.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'net-history-empty';
            empty.textContent = available
                ? 'Belum ada riwayat insiden yang tercatat untuk area ini.'
                : 'Riwayat belum tersedia karena area pelanggan belum terhubung.';
            list.appendChild(empty);
            return;
        }

        history.forEach(function (incident) {
            const item = document.createElement('article');
            item.className = 'net-history-item';

            const title = document.createElement('strong');
            title.textContent = String(incident.title || 'Gangguan jaringan');

            const description = document.createElement('p');
            description.textContent = String(
                incident.description || 'Tidak ada keterangan tambahan.'
            );

            const meta = document.createElement('small');
            let metaText = 'Mulai: ' + formatDate(incident.started_at, 'Waktu tidak tersedia');

            if (incident.estimated_resolution) {
                metaText += ' · Estimasi: ' + formatDate(incident.estimated_resolution);
            }

            meta.textContent = metaText;

            item.append(title, description, meta);
            list.appendChild(item);
        });
    }

    function renderTelemetry(telemetry) {
        const available = Boolean(telemetry && telemetry.available);
        const live = available && Boolean(telemetry.is_live);

        const icon = document.getElementById('internetTelemetryIcon');
        const status = document.getElementById('internetTelemetryStatus');

        if (icon) {
            icon.className = 'service-icon ' + (live ? 'icon-online' : 'icon-unknown');
        }

        if (status) {
            status.className = live ? 'service-online' : 'service-unknown';

            const label = status.querySelector('em');

            if (label) {
                label.textContent = live
                    ? 'Data terbaru'
                    : (available ? 'Historis' : 'Belum tersedia');
            }
        }

        setText(
            'infoTelemetry',
            available ? formatDate(telemetry.updated_at) : 'Belum ada data'
        );
    }

    function renderUnread(count) {
        const badge = document.getElementById('dashboardUnreadCount');

        if (!badge) {
            return;
        }

        const total = Number(count) || 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.hidden = total === 0;
    }


    /*
    |--------------------------------------------------------------------------
    | POLLING dashboard_data.php
    |--------------------------------------------------------------------------
    */

    const refreshButton = document.getElementById('refreshButton');
    const refreshIcon = document.getElementById('refreshIcon');
    let requestPending = false;

    async function loadNetworkData() {
        if (requestPending) {
            return;
        }

        requestPending = true;

        if (refreshButton) {
            refreshButton.disabled = true;
        }

        if (refreshIcon) {
            refreshIcon.classList.add('is-spinning');
        }

        try {
            const response = await fetch('dashboard_data.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });

            const payload = await response.json();

            if (!response.ok || !payload.data) {
                throw new Error(payload.error || 'Data status jaringan gagal dimuat.');
            }

            renderNetwork(payload.data.network || {});
            renderTelemetry(payload.data.telemetry || {});
            renderUnread(payload.data.unread_notifications || 0);

            const checkedAt = formatDate(payload.data.checked_at, '—');

            setText('infoChecked', checkedAt);
            setText('networkRefreshStatus', 'Diperbarui ' + checkedAt);
            setText('networkUpdatedAt', '');

            const footerNote = document.getElementById('networkUpdatedAt');

            if (footerNote) {
                const icon = document.createElement('i');
                icon.className = 'bi bi-info-circle';
                footerNote.textContent = '';
                footerNote.append(
                    icon,
                    document.createTextNode(
                        ' Status diperbarui ' + checkedAt + '. Pembaruan otomatis setiap 30 detik.'
                    )
                );
            }
        } catch (error) {
            console.error('Pembaruan status jaringan gagal:', error);
            setText('networkRefreshStatus', 'Gagal memperbarui data');

            const list = document.getElementById('networkHistory');

            if (list && list.querySelector('.net-history-empty')) {
                list.querySelector('.net-history-empty').textContent =
                    'Data status jaringan gagal dimuat. Silakan coba refresh.';
            }
        } finally {
            requestPending = false;

            if (refreshButton) {
                refreshButton.disabled = false;
            }

            if (refreshIcon) {
                refreshIcon.classList.remove('is-spinning');
            }
        }
    }

    if (refreshButton) {
        refreshButton.addEventListener('click', loadNetworkData);
    }

    loadNetworkData();
    window.setInterval(loadNetworkData, 30000);

});

</script>

</body>

</html>