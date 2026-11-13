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
 * Mengembalikan nama kolom pertama yang tersedia dari daftar kandidat.
 */
function firstColumn(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (columnExists($conn, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
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

/**
 * Menyeragamkan status tagihan/pembayaran: paid, pending, unpaid, overdue, expired, failed, unknown.
 */
function normalizeBillStatus($status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['paid', 'lunas', 'success', 'berhasil', 'settlement', 'capture'], true)) {
        return 'paid';
    }

    if (in_array($status, ['pending', 'menunggu', 'menunggu pembayaran', 'waiting'], true)) {
        return 'pending';
    }

    if (in_array($status, ['unpaid', 'belum bayar', 'belum dibayar'], true)) {
        return 'unpaid';
    }

    if (in_array($status, ['overdue', 'terlambat', 'jatuh tempo'], true)) {
        return 'overdue';
    }

    if (in_array($status, ['expired', 'kedaluwarsa', 'kadaluarsa'], true)) {
        return 'expired';
    }

    if (in_array($status, ['failed', 'gagal', 'cancel', 'canceled', 'cancelled', 'dibatalkan'], true)) {
        return 'failed';
    }

    return 'unknown';
}

$billStatusMeta = [
    'paid'    => ['Lunas', 'paid', 'bi-check-circle-fill'],
    'pending' => ['Menunggu Pembayaran', 'pending', 'bi-hourglass-split'],
    'unpaid'  => ['Belum Dibayar', 'unpaid', 'bi-exclamation-circle-fill'],
    'overdue' => ['Terlambat', 'overdue', 'bi-exclamation-triangle-fill'],
    'expired' => ['Kedaluwarsa', 'overdue', 'bi-x-circle-fill'],
    'failed'  => ['Gagal', 'overdue', 'bi-x-circle-fill'],
    'unknown' => ['Tidak diketahui', 'pending', 'bi-question-circle-fill'],
];


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
$email = $_SESSION['email'] ?? '';
$telephone = '';
$alamat = '';
$kodePelanggan = '';

$statusLangganan = 'belum_berlangganan';

$paketNama = 'Belum Memilih Paket';
$paketSpeedLabel = '-';
$paketHarga = 0;

$totalGangguan = 0;
$unreadNotifications = 0;

$tagihan = null;
$tagihanTableAvailable = false;

$jumlahTagihan = 0;
$jatuhTempo = null;
$nomorTagihan = '-';
$statusTagihan = 'unpaid';
$sisaHari = null;

$payments = [];
$paymentTableAvailable = false;
$totalDibayar = null;
$totalTransaksi = 0;


/*
|--------------------------------------------------------------------------
| CEK TABEL CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Billing YESNET: kolom customers.user_id tidak ditemukan.");
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
        "Billing YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data tagihan gagal dimuat. Periksa struktur tabel customers dan paket_wifi atau error log hosting.");
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

$email = $customer['email'] ?? $_SESSION['email'] ?? '';
$telephone = $customer['telephone'] ?? $customer['no_hp'] ?? $customer['phone'] ?? '';
$alamat = $customer['alamat'] ?? $customer['address'] ?? '';
$kodePelanggan = $customer['kode_pelanggan'] ?? '';

$statusLangganan = normalizeSubscriptionStatus($customer['status_langganan'] ?? '');

if (!empty($customer['nama_paket'])) {
    $paketNama = $customer['nama_paket'];
}

if (isset($customer['speed_mbps']) && is_numeric($customer['speed_mbps'])) {
    $paketSpeedLabel = number_format((float) $customer['speed_mbps'], 0, ',', '.') . ' Mbps';
}

if (isset($customer['harga_paket']) && is_numeric($customer['harga_paket'])) {
    $paketHarga = (float) $customer['harga_paket'];
}


/*
|--------------------------------------------------------------------------
| TAGIHAN AKTIF
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists($conn, 'tagihan') &&
    columnExists($conn, 'tagihan', 'id_customer')
) {
    $tagihanTableAvailable = true;

    $statusCondition = '';

    if (columnExists($conn, 'tagihan', 'status')) {
        $statusCondition = "
            AND LOWER(status) IN (
                'unpaid', 'pending', 'overdue',
                'belum bayar', 'belum dibayar', 'terlambat'
            )
        ";
    }

    $dueColumn = firstColumn($conn, 'tagihan', ['jatuh_tempo', 'tanggal_jatuh_tempo']);
    $order = $dueColumn ? "ORDER BY `{$dueColumn}` ASC" : '';

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM tagihan
            WHERE id_customer = ?
            {$statusCondition}
            {$order}
            LIMIT 1
        ");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();

        if (!empty($rows)) {
            $tagihan = $rows[0];

            $jumlahTagihan = $tagihan['jumlah']
                ?? $tagihan['total']
                ?? $tagihan['nominal']
                ?? $tagihan['amount']
                ?? 0;

            $jatuhTempo = $tagihan['jatuh_tempo']
                ?? $tagihan['tanggal_jatuh_tempo']
                ?? null;

            $nomorTagihan = $tagihan['nomor_tagihan']
                ?? $tagihan['invoice']
                ?? $tagihan['invoice_number']
                ?? $tagihan['kode_tagihan']
                ?? '-';

            $statusTagihan = normalizeBillStatus($tagihan['status'] ?? 'unpaid');

            // Status "unknown" pada tagihan terbuka diperlakukan sebagai belum dibayar.
            if ($statusTagihan === 'unknown') {
                $statusTagihan = 'unpaid';
            }

            $dueTs = $jatuhTempo ? strtotime((string) $jatuhTempo) : false;

            if ($dueTs !== false) {
                $todayTs = strtotime(date('Y-m-d'));
                $dueDayTs = strtotime(date('Y-m-d', $dueTs));
                $sisaHari = (int) round(($dueDayTs - $todayTs) / 86400);

                if ($sisaHari < 0 && in_array($statusTagihan, ['unpaid', 'pending'], true)) {
                    $statusTagihan = 'overdue';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Billing YESNET invoice query failed: ' . $e->getMessage());
        $tagihan = null;
    }
}


/*
|--------------------------------------------------------------------------
| RIWAYAT PEMBAYARAN + RINGKASAN
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists($conn, 'pembayaran') &&
    columnExists($conn, 'pembayaran', 'id_customer')
) {
    $paymentTableAvailable = true;

    $orderColumn = firstColumn($conn, 'pembayaran', ['created_at', 'tanggal_bayar']);
    $order = $orderColumn ? "ORDER BY `{$orderColumn}` DESC" : '';

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM pembayaran
            WHERE id_customer = ?
            {$order}
            LIMIT 10
        ");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $payments = statementFetchAll($stmt);
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM pembayaran WHERE id_customer = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();
        $totalTransaksi = (int) ($rows[0]['total'] ?? 0);

        $amountColumn = firstColumn($conn, 'pembayaran', ['jumlah', 'nominal', 'total', 'amount']);

        if ($amountColumn && columnExists($conn, 'pembayaran', 'status')) {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(`{$amountColumn}`), 0) AS total
                FROM pembayaran
                WHERE id_customer = ?
                  AND LOWER(status) IN ('paid', 'lunas', 'success', 'berhasil', 'settlement', 'capture')
            ");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $rows = statementFetchAll($stmt);
            $stmt->close();
            $totalDibayar = (float) ($rows[0]['total'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('Billing YESNET payment query failed: ' . $e->getMessage());
        $payments = [];
    }
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
        error_log('Billing YESNET complaint count failed: ' . $e->getMessage());
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
        error_log('Billing YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| TAMPILAN
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

[$tagihanLabel, $tagihanClass, $tagihanIcon] =
    $billStatusMeta[$statusTagihan] ?? $billStatusMeta['unknown'];

$canPay = $tagihan !== null
    && isset($tagihan['id'])
    && in_array($statusTagihan, ['unpaid', 'pending', 'overdue'], true);

$dueNote = null;

if ($tagihan !== null && $sisaHari !== null) {
    if ($sisaHari < 0) {
        $dueNote = 'Terlambat ' . abs($sisaHari) . ' hari';
    } elseif ($sisaHari === 0) {
        $dueNote = 'Jatuh tempo hari ini';
    } else {
        $dueNote = $sisaHari . ' hari lagi';
    }
}

$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#0f4cdb">

    <title>Tagihan Saya - YESNET</title>

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

    <!-- Billing CSS (khusus halaman ini) -->
    <link rel="stylesheet" href="assets/css/billing.css?v=6">

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

                <a href="billing.php" class="menu-item <?= $currentPage === 'billing.php' ? 'active' : ''; ?>" aria-current="page">
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a href="#riwayat-pembayaran" class="menu-item">
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
                        Tagihan Saya
                    </div>

                    <h1>Tagihan Saya</h1>
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


            <div class="section-title-row bill-head">
                <div>
                    <span class="section-kicker">BILLING</span>
                    <h2>Tagihan &amp; Pembayaran</h2>
                </div>
            </div>


            <!-- =================================================
                 RINGKASAN
            ================================================== -->

            <div class="stats-grid bill-stats">

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-receipt"></i></div>
                        <span class="stat-tag">TAGIHAN AKTIF</span>
                    </div>
                    <div class="stat-value">
                        <?php if ($tagihan): ?>
                            <?= e(rupiah($jumlahTagihan)); ?>
                        <?php else: ?>
                            <?= $tagihanTableAvailable ? 'Rp 0' : '—'; ?>
                        <?php endif; ?>
                    </div>
                    <div class="stat-label">
                        <?= $tagihan
                            ? e($tagihanLabel)
                            : ($tagihanTableAvailable ? 'Tidak ada tagihan tertunda' : 'Data tagihan belum tersedia'); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
                        <span class="stat-tag green">LUNAS</span>
                    </div>
                    <div class="stat-value">
                        <?= $totalDibayar !== null ? e(rupiah($totalDibayar)) : '—'; ?>
                    </div>
                    <div class="stat-label">
                        <?= $totalDibayar !== null
                            ? 'Total pembayaran berhasil'
                            : 'Total pembayaran belum tersedia'; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-clock-history"></i></div>
                        <span class="stat-tag">TRANSAKSI</span>
                    </div>
                    <div class="stat-value">
                        <?= $paymentTableAvailable ? (int) $totalTransaksi : '—'; ?>
                    </div>
                    <div class="stat-label">Pembayaran tercatat</div>
                </div>

            </div>


            <!-- =================================================
                 TAGIHAN + DETAIL LAYANAN
            ================================================== -->

            <div class="main-grid bill-grid">

                <!-- TAGIHAN -->
                <div class="main-column">

                    <section class="bill-invoice <?= $tagihan ? 'has-invoice' : 'no-invoice'; ?>">

                        <div class="bill-invoice-deco"></div>

                        <div class="bill-invoice-top">

                            <div class="bill-invoice-icon">
                                <i class="bi <?= $tagihan ? 'bi-receipt' : 'bi-patch-check-fill'; ?>"></i>
                            </div>

                            <?php if ($tagihan): ?>
                                <span class="bill-status <?= e($tagihanClass); ?>">
                                    <i class="bi <?= e($tagihanIcon); ?>"></i>
                                    <?= e($tagihanLabel); ?>
                                </span>
                            <?php endif; ?>

                        </div>

                        <span class="bill-invoice-label">TAGIHAN SAAT INI</span>

                        <?php if ($tagihan): ?>

                            <div class="bill-invoice-amount"><?= e(rupiah($jumlahTagihan)); ?></div>

                            <?php if ($dueNote): ?>
                                <p class="bill-invoice-note"><?= e($dueNote); ?></p>
                            <?php endif; ?>

                            <div class="bill-invoice-meta">

                                <div>
                                    <span>Nomor invoice</span>
                                    <strong><?= e($nomorTagihan); ?></strong>
                                </div>

                                <div>
                                    <span>Jatuh tempo</span>
                                    <strong><?= e(formatTanggal($jatuhTempo)); ?></strong>
                                </div>

                                <div>
                                    <span>Paket</span>
                                    <strong><?= e($paketNama); ?></strong>
                                </div>

                            </div>

                            <?php if ($canPay): ?>
                                <a href="../pembayaran.php?tagihan=<?= (int) $tagihan['id']; ?>" class="bill-pay-button">
                                    <i class="bi bi-credit-card"></i>
                                    Bayar Sekarang
                                </a>
                            <?php endif; ?>

                        <?php else: ?>

                            <div class="bill-invoice-amount bill-invoice-empty">
                                <?= $tagihanTableAvailable ? 'Tidak ada tagihan' : 'Tagihan belum tersedia'; ?>
                            </div>

                            <p class="bill-invoice-note">
                                <?= $tagihanTableAvailable
                                    ? 'Saat ini tidak ada tagihan yang harus dibayar.'
                                    : 'Data tagihan akan tampil setelah sistem penagihan terhubung.'; ?>
                            </p>

                        <?php endif; ?>

                    </section>

                </div>


                <!-- DETAIL LAYANAN -->
                <div class="side-column">

                    <div class="dashboard-card bill-service">

                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">LAYANAN</span>
                                <h3>Detail Layanan</h3>
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

                        <div class="bill-package">

                            <div class="bill-package-icon">
                                <i class="bi bi-router-fill"></i>
                            </div>

                            <div>
                                <strong><?= e($paketNama); ?></strong>
                                <span><?= e($paketSpeedLabel); ?></span>
                                <?php if ($paketHarga > 0): ?>
                                    <small><?= e(rupiah($paketHarga)); ?> / bulan</small>
                                <?php endif; ?>
                            </div>

                        </div>

                        <dl class="bill-details">

                            <div>
                                <dt>Kode pelanggan</dt>
                                <dd><?= $kodePelanggan !== '' ? e($kodePelanggan) : '#' . (int) $customerId; ?></dd>
                            </div>

                            <div>
                                <dt>Nama</dt>
                                <dd><?= e($nama); ?></dd>
                            </div>

                            <div>
                                <dt>Email</dt>
                                <dd><?= $email !== '' ? e($email) : '-'; ?></dd>
                            </div>

                            <div>
                                <dt>Nomor HP</dt>
                                <dd><?= $telephone !== '' ? e($telephone) : '-'; ?></dd>
                            </div>

                            <?php if ($alamat !== ''): ?>
                                <div>
                                    <dt>Alamat</dt>
                                    <dd><?= e($alamat); ?></dd>
                                </div>
                            <?php endif; ?>

                        </dl>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 RIWAYAT PEMBAYARAN
            ================================================== -->

            <div class="dashboard-card bill-history" id="riwayat-pembayaran">

                <div class="card-heading compact">

                    <div>
                        <span class="card-kicker">TRANSAKSI</span>
                        <h3>Riwayat Pembayaran</h3>
                        <p>Hingga 10 pembayaran terakhir.</p>
                    </div>

                    <div class="bill-history-icon"><i class="bi bi-clock-history"></i></div>

                </div>

                <div class="bill-table-wrap">

                    <table class="bill-table">

                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Invoice</th>
                                <th>Jumlah</th>
                                <th>Metode</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($payments)): ?>

                            <tr>
                                <td colspan="5" class="bill-empty">
                                    <i class="bi bi-receipt"></i>
                                    <strong>
                                        <?= $paymentTableAvailable
                                            ? 'Belum ada riwayat pembayaran'
                                            : 'Riwayat pembayaran belum tersedia'; ?>
                                    </strong>
                                    <span>Pembayaran yang sudah kamu lakukan akan muncul di sini.</span>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($payments as $payment): ?>

                                <?php
                                $paymentStatus = normalizeBillStatus($payment['status'] ?? '');
                                [$pLabel, $pClass, $pIcon] = $billStatusMeta[$paymentStatus] ?? $billStatusMeta['unknown'];

                                $paymentDate = $payment['tanggal_bayar']
                                    ?? $payment['created_at']
                                    ?? $payment['tanggal']
                                    ?? '-';

                                $paymentInvoice = $payment['nomor_pembayaran']
                                    ?? $payment['nomor_tagihan']
                                    ?? $payment['invoice']
                                    ?? $payment['invoice_number']
                                    ?? $payment['kode_pembayaran']
                                    ?? '-';

                                $paymentAmount = $payment['jumlah']
                                    ?? $payment['nominal']
                                    ?? $payment['total']
                                    ?? $payment['amount']
                                    ?? 0;

                                $paymentMethod = $payment['metode_pembayaran']
                                    ?? $payment['metode']
                                    ?? $payment['method']
                                    ?? '';

                                if ($paymentMethod === '' && !empty($payment['payment_provider'])) {
                                    $paymentMethod = ucfirst((string) $payment['payment_provider']);
                                }

                                if ($paymentMethod === '') {
                                    $paymentMethod = '-';
                                }
                                ?>

                                <tr>
                                    <td data-label="Tanggal"><?= e(formatTanggal($paymentDate)); ?></td>
                                    <td data-label="Invoice"><strong class="bill-invoice-no"><?= e($paymentInvoice); ?></strong></td>
                                    <td data-label="Jumlah"><strong><?= e(rupiah($paymentAmount)); ?></strong></td>
                                    <td data-label="Metode"><?= e($paymentMethod); ?></td>
                                    <td data-label="Status">
                                        <span class="bill-status <?= e($pClass); ?>">
                                            <i class="bi <?= e($pIcon); ?>"></i>
                                            <?= e($pLabel); ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <!-- =================================================
                 INFO
            ================================================== -->

            <div class="bill-tips">

                <div class="bill-tip">
                    <div class="bill-tip-icon green"><i class="bi bi-shield-check"></i></div>
                    <div>
                        <strong>Pembayaran aman</strong>
                        <span>Semua pembayaran diproses melalui sistem yang aman.</span>
                    </div>
                </div>

                <div class="bill-tip">
                    <div class="bill-tip-icon blue"><i class="bi bi-calendar-check"></i></div>
                    <div>
                        <strong>Bayar sebelum jatuh tempo</strong>
                        <span>Pembayaran tepat waktu menjaga layanan tetap aktif.</span>
                    </div>
                </div>

                <a href="chat.php" class="bill-tip bill-tip-link">
                    <div class="bill-tip-icon purple"><i class="bi bi-headset"></i></div>
                    <div>
                        <strong>Ada kendala pembayaran?</strong>
                        <span>Chat Customer Service <i class="bi bi-arrow-right"></i></span>
                    </div>
                </a>

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

    // Tutup sidebar saat menu anchor (#riwayat-pembayaran) dipilih di mobile.
    document.querySelectorAll('.sidebar a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });


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