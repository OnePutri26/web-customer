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
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
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
| Kelompok status complaint. Dipakai untuk normalisasi tampilan
| sekaligus untuk membangun filter SQL, sehingga keduanya selalu sama.
*/
const COMPLAINT_STATUS_GROUPS = [
    'open'    => ['open', 'baru', 'new', 'pending'],
    'process' => ['process', 'proses', 'diproses', 'on progress', 'in progress', 'in_progress'],
    'closed'  => ['closed', 'selesai', 'resolved'],
];

function normalizeComplaintStatus($status): string
{
    $status = strtolower(trim((string) ($status ?? '')));

    foreach (COMPLAINT_STATUS_GROUPS as $key => $values) {
        if (in_array($status, $values, true)) {
            return $key;
        }
    }

    return 'other';
}

function normalizePriority($priority): string
{
    $priority = strtolower(trim((string) ($priority ?? '')));

    if (in_array($priority, ['critical', 'kritis'], true)) {
        return 'critical';
    }

    if (in_array($priority, ['high', 'tinggi', 'urgent'], true)) {
        return 'high';
    }

    if (in_array($priority, ['medium', 'sedang', 'normal'], true)) {
        return 'medium';
    }

    if (in_array($priority, ['low', 'rendah'], true)) {
        return 'low';
    }

    return 'other';
}

/*
| Label jenis gangguan. Harus sama dengan daftar di complaint_create.php.
*/
const CATEGORY_LABELS = [
    'internet_down'   => 'Internet Mati',
    'slow_connection' => 'Internet Lambat',
    'intermittent'    => 'Koneksi Putus-Putus',
    'high_latency'    => 'Ping Tinggi / Lag',
    'site_blocked'    => 'Situs / Aplikasi Error',
    'los_red_light'   => 'Lampu LOS Merah / Kabel Fiber',
    'wifi_problem'    => 'Masalah WiFi',
    'router_problem'  => 'Masalah Router / ONT',
    'ip_dns'          => 'Masalah IP / DNS',
    'billing'         => 'Billing',
    'other'           => 'Gangguan Lainnya',
];

function categoryLabel($category): string
{
    $category = strtolower(trim((string) ($category ?? '')));

    if ($category === '') {
        return '';
    }

    return CATEGORY_LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
}

function truncateText(string $text, int $limit = 140): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        return rtrim(mb_substr($text, 0, $limit)) . '…';
    }

    if (!function_exists('mb_strlen') && strlen($text) > $limit) {
        return rtrim(substr($text, 0, $limit)) . '…';
    }

    return $text;
}

$complaintStatusMeta = [
    'open'    => ['Baru', 'open', 'bi-exclamation-circle-fill'],
    'process' => ['Diproses', 'process', 'bi-arrow-repeat'],
    'closed'  => ['Selesai', 'closed', 'bi-check-circle-fill'],
    'other'   => ['Tidak diketahui', 'other', 'bi-question-circle-fill'],
];

$priorityMeta = [
    'critical' => ['Kritis', 'critical'],
    'high'     => ['Tinggi', 'high'],
    'medium'   => ['Sedang', 'medium'],
    'low'      => ['Rendah', 'low'],
    'other'    => ['Tidak diketahui', 'other'],
];

/*
| Jalan pintas pelaporan: [kode kategori, label, ikon Bootstrap Icons].
*/
$quickCategories = [
    ['internet_down',   'Internet Mati',          'bi-wifi-off'],
    ['slow_connection', 'Internet Lambat',        'bi-speedometer2'],
    ['intermittent',    'Putus-Putus',            'bi-activity'],
    ['high_latency',    'Ping Tinggi / Lag',      'bi-controller'],
    ['site_blocked',    'Situs / Aplikasi Error', 'bi-ban'],
    ['los_red_light',   'Lampu LOS Merah',        'bi-router-fill'],
    ['wifi_problem',    'Masalah WiFi',           'bi-wifi-2'],
    ['router_problem',  'Router / ONT',           'bi-hdd-network'],
    ['other',           'Gangguan Lainnya',       'bi-three-dots'],
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
| FLASH MESSAGE (dari complaint_create.php)
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

if (!is_array($flash) || empty($flash['message'])) {
    $flash = null;
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

$unreadNotifications = 0;

$complaintTableAvailable = false;
$complaints = [];

$counts = [
    'all'     => 0,
    'open'    => 0,
    'process' => 0,
    'closed'  => 0,
];

$perPage = 10;


/*
|--------------------------------------------------------------------------
| CEK TABEL CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Complaint YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER
|--------------------------------------------------------------------------
*/

try {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE user_id = ? LIMIT 1");
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
        "Complaint YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data complaint gagal dimuat. Periksa struktur tabel customers atau error log hosting.");
}

$customerId = (int) ($customer['id'] ?? 0);

if ($customerId <= 0) {
    http_response_code(500);
    die("ID customer tidak valid.");
}

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

// Sama dengan aturan di complaint_create.php.
$canReport = in_array($statusLangganan, ['active', 'suspended', 'pending'], true);


/*
|--------------------------------------------------------------------------
| FILTER STATUS + HALAMAN
|--------------------------------------------------------------------------
*/

$allowedFilters = ['all', 'open', 'process', 'closed'];
$filter = (string) ($_GET['status'] ?? 'all');

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$page = max(1, (int) ($_GET['page'] ?? 1));


/*
|--------------------------------------------------------------------------
| JUMLAH COMPLAINT PER STATUS
|--------------------------------------------------------------------------
| Relasi: complaint.id_customer -> customers.id
| Dihitung dengan query agregat agar akurat berapa pun jumlah datanya.
*/

$hasStatusColumn = false;

if (
    tableExists($conn, 'complaint') &&
    columnExists($conn, 'complaint', 'id_customer')
) {
    $complaintTableAvailable = true;
    $hasStatusColumn = columnExists($conn, 'complaint', 'status');

    try {
        if ($hasStatusColumn) {
            $stmt = $conn->prepare("
                SELECT LOWER(TRIM(status)) AS status_key, COUNT(*) AS total
                FROM complaint
                WHERE id_customer = ?
                GROUP BY LOWER(TRIM(status))
            ");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $countRows = statementFetchAll($stmt);
            $stmt->close();

            foreach ($countRows as $countRow) {
                $total = (int) ($countRow['total'] ?? 0);
                $key = normalizeComplaintStatus($countRow['status_key'] ?? '');

                $counts['all'] += $total;

                if (isset($counts[$key])) {
                    $counts[$key] += $total;
                }
            }
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $countRows = statementFetchAll($stmt);
            $stmt->close();

            $counts['all'] = (int) ($countRows[0]['total'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('Complaint YESNET count query failed: ' . $e->getMessage());
    }
}

// Tanpa kolom status, filter status tidak bermakna.
if (!$hasStatusColumn) {
    $filter = 'all';
}

// Badge menu: complaint yang belum selesai.
$totalGangguan = max(0, $counts['all'] - $counts['closed']);

$filterTotal = (int) ($counts[$filter] ?? 0);
$totalPages = max(1, (int) ceil($filterTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;


/*
|--------------------------------------------------------------------------
| AMBIL DAFTAR COMPLAINT
|--------------------------------------------------------------------------
*/

if ($complaintTableAvailable && $filterTotal > 0) {
    try {
        $where = "id_customer = ?";

        if ($filter !== 'all') {
            $quoted = array_map(
                static fn (string $value): string => "'" . $conn->real_escape_string($value) . "'",
                COMPLAINT_STATUS_GROUPS[$filter]
            );

            $where .= " AND LOWER(TRIM(status)) IN (" . implode(', ', $quoted) . ")";
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM complaint
            WHERE {$where}
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $customerId, $perPage, $offset);
        $stmt->execute();
        $complaints = statementFetchAll($stmt);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Complaint YESNET complaint query failed: ' . $e->getMessage());
        $complaints = [];
    }
}

foreach ($complaints as $index => $row) {
    $complaints[$index]['_status'] = normalizeComplaintStatus($row['status'] ?? '');
    $complaints[$index]['_priority'] = normalizePriority($row['priority'] ?? $row['prioritas'] ?? '');
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
        error_log('Complaint YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| TAMPILAN
|--------------------------------------------------------------------------
*/

$filterLabels = [
    'all'     => 'Semua',
    'open'    => 'Baru',
    'process' => 'Diproses',
    'closed'  => 'Selesai',
];

function complaintPageUrl(string $filter, int $page): string
{
    $query = ['status' => $filter];

    if ($page > 1) {
        $query['page'] = $page;
    }

    return 'complaint.php?' . http_build_query($query);
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

    <title>Laporan Gangguan - YESNET</title>

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

    <!-- Complaint CSS (khusus halaman laporan) -->
    <link rel="stylesheet" href="assets/css/complaint.css?v=13">

    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <script defer src="assets/js/portal-ui.js"></script>

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

                <a href="speedtest.php" class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="menu-text">Speed Test</span>
                </a>

                <a href="complaint.php" class="menu-item <?= $currentPage === 'complaint.php' ? 'active' : ''; ?>" aria-current="page">
                    <span class="menu-icon"><i class="bi bi-tools"></i></span>
                    <span class="menu-text">Laporan Gangguan</span>
                    <?php if ($totalGangguan > 0): ?>
                        <span class="menu-badge"><?= (int) $totalGangguan; ?></span>
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
                        Laporan Gangguan
                    </div>

                    <h1>Laporan Gangguan</h1>
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


            <?php if ($flash): ?>

                <div
                    class="cp-flash <?= ($flash['type'] ?? '') === 'error' ? 'is-error' : 'is-success'; ?>"
                    role="status"
                >
                    <i class="bi <?= ($flash['type'] ?? '') === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?>"></i>
                    <span><?= e($flash['message']); ?></span>
                </div>

            <?php endif; ?>


            <div class="section-title-row cp-head">

                <div>
                    <span class="section-kicker">CUSTOMER SUPPORT</span>
                    <h2>Laporan Gangguan Saya</h2>
                </div>

                <a href="complaint_create.php" class="cp-create">
                    <i class="bi bi-plus-lg"></i>
                    Buat Laporan
                </a>

            </div>


            <!-- =================================================
                 RINGKASAN
            ================================================== -->

            <div class="stats-grid">

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue"><i class="bi bi-ticket-perforated-fill"></i></div>
                        <span class="stat-tag">TOTAL</span>
                    </div>
                    <div class="stat-value"><?= (int) $counts['all']; ?></div>
                    <div class="stat-label">Laporan dibuat</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon orange"><i class="bi bi-exclamation-circle-fill"></i></div>
                        <span class="stat-tag">BARU</span>
                    </div>
                    <div class="stat-value"><?= (int) $counts['open']; ?></div>
                    <div class="stat-label">Menunggu ditangani</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple"><i class="bi bi-arrow-repeat"></i></div>
                        <span class="stat-tag">DIPROSES</span>
                    </div>
                    <div class="stat-value"><?= (int) $counts['process']; ?></div>
                    <div class="stat-label">Sedang ditangani tim</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                        <span class="stat-tag green">SELESAI</span>
                    </div>
                    <div class="stat-value"><?= (int) $counts['closed']; ?></div>
                    <div class="stat-label">Sudah terselesaikan</div>
                </div>

            </div>


            <!-- =================================================
                 LAPORKAN GANGGUAN (jalan pintas per jenis)
            ================================================== -->

            <?php if ($canReport): ?>

                <section class="dashboard-card cp-quick" aria-labelledby="cpQuickTitle">

                    <div class="card-heading compact">
                        <div>
                            <span class="card-kicker">LAPORKAN GANGGUAN</span>
                            <h3 id="cpQuickTitle">Gangguan apa yang kamu alami?</h3>
                            <p>Semua jenis gangguan internet bisa dilaporkan. Pilih salah satu, formulirnya langsung disiapkan.</p>
                        </div>
                    </div>

                    <div class="cp-quick-grid">

                        <?php foreach ($quickCategories as [$quickKey, $quickLabel, $quickIcon]): ?>

                            <a href="complaint_create.php?category=<?= e($quickKey); ?>" class="cp-quick-item">
                                <i class="bi <?= e($quickIcon); ?>"></i>
                                <span><?= e($quickLabel); ?></span>
                            </a>

                        <?php endforeach; ?>

                    </div>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 DAFTAR LAPORAN
            ================================================== -->

            <section class="dashboard-card cp-card" aria-labelledby="cpListTitle">

                <div class="card-heading compact">

                    <div>
                        <span class="card-kicker">RIWAYAT</span>
                        <h3 id="cpListTitle">Daftar Laporan</h3>
                        <p>Pantau proses penanganan laporan yang pernah kamu buat.</p>
                    </div>

                </div>

                <?php if ($complaintTableAvailable && $hasStatusColumn && $counts['all'] > 0): ?>

                    <nav class="cp-tabs" aria-label="Filter status laporan">
                        <?php foreach ($filterLabels as $key => $label): ?>
                            <a
                                href="complaint.php?status=<?= e($key); ?>"
                                class="cp-tab <?= $filter === $key ? 'active' : ''; ?>"
                                <?= $filter === $key ? 'aria-current="true"' : ''; ?>
                            >
                                <?= e($label); ?>
                                <span><?= (int) $counts[$key]; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                <?php endif; ?>


                <?php if (!$complaintTableAvailable): ?>

                    <div class="cp-empty">
                        <div class="cp-empty-icon"><i class="bi bi-plug"></i></div>
                        <h4>Data laporan belum tersedia</h4>
                        <p>
                            Sistem laporan gangguan belum terhubung. Silakan hubungi
                            Customer Care jika kamu mengalami masalah internet.
                        </p>
                        <a href="chat.php" class="cp-create">
                            <i class="bi bi-headset"></i>
                            Hubungi Customer Care
                        </a>
                    </div>

                <?php elseif ($counts['all'] === 0): ?>

                    <div class="cp-empty">
                        <div class="cp-empty-icon"><i class="bi bi-ticket-detailed"></i></div>
                        <h4>Belum ada laporan</h4>
                        <p>
                            Kamu belum membuat laporan gangguan. Jika mengalami masalah internet
                            apa pun, buat laporan baru dan tim kami akan menanganinya.
                        </p>
                        <a href="complaint_create.php" class="cp-create">
                            <i class="bi bi-plus-lg"></i>
                            Buat Laporan
                        </a>
                    </div>

                <?php elseif (empty($complaints)): ?>

                    <div class="cp-empty cp-empty-compact">
                        <div class="cp-empty-icon"><i class="bi bi-funnel"></i></div>
                        <h4>Tidak ada laporan dengan status ini</h4>
                        <p>Pilih status lain untuk melihat laporan kamu.</p>
                        <a href="complaint.php" class="cp-link">Tampilkan semua laporan</a>
                    </div>

                <?php else: ?>

                    <div class="cp-list">

                        <?php foreach ($complaints as $row): ?>

                            <?php
                            $complaintId = (int) ($row['id'] ?? 0);

                            [$statusLabel, $statusClass, $statusIcon] =
                                $complaintStatusMeta[$row['_status']] ?? $complaintStatusMeta['other'];

                            [$priorityLabel, $priorityClass] =
                                $priorityMeta[$row['_priority']] ?? $priorityMeta['other'];

                            // Status asli ditampilkan bila tidak dikenali.
                            if ($row['_status'] === 'other' && trim((string) ($row['status'] ?? '')) !== '') {
                                $statusLabel = ucfirst(trim((string) $row['status']));
                            }

                            $subject = trim((string) ($row['subject'] ?? $row['judul'] ?? ''));

                            if ($subject === '') {
                                $subject = 'Tanpa subjek';
                            }

                            // Kolom sesuai INSERT di complaint_create.php: complaint_code.
                            $ticketCode = trim((string) (
                                $row['complaint_code']
                                ?? $row['kode_tiket']
                                ?? $row['ticket_code']
                                ?? $row['kode_complaint']
                                ?? ''
                            ));

                            if ($ticketCode === '') {
                                $ticketCode = 'Tiket #' . $complaintId;
                            }

                            $description = trim((string) (
                                $row['description']
                                ?? $row['deskripsi']
                                ?? $row['isi']
                                ?? $row['pesan']
                                ?? ''
                            ));

                            $categoryText = categoryLabel($row['category'] ?? '');

                            $createdAt = 'Waktu tidak tersedia';
                            $createdTs = !empty($row['created_at']) ? strtotime((string) $row['created_at']) : false;

                            if ($createdTs !== false) {
                                $createdAt = date('d M Y, H:i', $createdTs) . ' WIB';
                            }
                            ?>

                            <article class="cp-item">

                                <div class="cp-item-icon <?= e($statusClass); ?>">
                                    <i class="bi <?= e($statusIcon); ?>"></i>
                                </div>

                                <div class="cp-item-main">

                                    <div class="cp-item-top">
                                        <span class="cp-code"><?= e($ticketCode); ?></span>
                                        <span class="cp-date">
                                            <i class="bi bi-clock"></i>
                                            <?= e($createdAt); ?>
                                        </span>
                                    </div>

                                    <h4><?= e($subject); ?></h4>

                                    <?php if ($description !== ''): ?>
                                        <p><?= e(truncateText($description)); ?></p>
                                    <?php endif; ?>

                                    <div class="cp-meta">

                                        <?php if ($categoryText !== ''): ?>
                                            <span class="cp-badge category">
                                                <i class="bi bi-tag-fill"></i>
                                                <?= e($categoryText); ?>
                                            </span>
                                        <?php endif; ?>

                                        <span class="cp-badge priority-<?= e($priorityClass); ?>">
                                            <i class="bi bi-flag-fill"></i>
                                            Prioritas <?= e($priorityLabel); ?>
                                        </span>

                                        <span class="cp-badge status-<?= e($statusClass); ?>">
                                            <i class="bi <?= e($statusIcon); ?>"></i>
                                            <?= e($statusLabel); ?>
                                        </span>

                                    </div>

                                </div>

                                <?php if ($complaintId > 0): ?>
                                    <a
                                        href="complaint_detail.php?id=<?= $complaintId; ?>"
                                        class="cp-detail"
                                        title="Lihat detail laporan"
                                        aria-label="Lihat detail <?= e($subject); ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>


                    <?php if ($totalPages > 1): ?>

                        <nav class="cp-pagination" aria-label="Halaman laporan">

                            <?php if ($page > 1): ?>
                                <a href="<?= e(complaintPageUrl($filter, $page - 1)); ?>" class="cp-page-btn">
                                    <i class="bi bi-chevron-left"></i> Sebelumnya
                                </a>
                            <?php else: ?>
                                <span class="cp-page-btn is-disabled">
                                    <i class="bi bi-chevron-left"></i> Sebelumnya
                                </span>
                            <?php endif; ?>

                            <span class="cp-page-info">
                                Halaman <?= (int) $page; ?> dari <?= (int) $totalPages; ?>
                            </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= e(complaintPageUrl($filter, $page + 1)); ?>" class="cp-page-btn">
                                    Berikutnya <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="cp-page-btn is-disabled">
                                    Berikutnya <i class="bi bi-chevron-right"></i>
                                </span>
                            <?php endif; ?>

                        </nav>

                    <?php endif; ?>

                <?php endif; ?>

            </section>


            <!-- Bantuan cepat -->
            <div class="cp-help">

                <div class="cp-help-icon"><i class="bi bi-headset"></i></div>

                <div>
                    <strong>Butuh bantuan segera?</strong>
                    <span>Untuk gangguan mendesak, hubungi Technical Support lewat live chat.</span>
                </div>

                <a href="chat.php?topic=complaint" class="cp-link">
                    Chat Technical Support <i class="bi bi-arrow-right"></i>
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