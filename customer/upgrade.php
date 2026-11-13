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


function rupiah($value): string
{
    return 'Rp ' . number_format(
        (float) $value,
        0,
        ',',
        '.'
    );
}


function formatSpeed($mbps): string
{
    $mbps = (float) $mbps;

    if ($mbps >= 1000) {
        $gbps = $mbps / 1000;

        return rtrim(rtrim(number_format($gbps, 1, ',', '.'), '0'), ',') . ' Gbps';
    }

    return number_format($mbps, 0, ',', '.') . ' Mbps';
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


function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);

    exit;
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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_upgrade'])) {
    $_SESSION['csrf_upgrade'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_upgrade'];


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

$currentPaket = null;
$regionColumn = null;
$statusColumnExists = false;

$packages = [];
$pendingRequest = null;
$requestTableReady = false;


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
    error_log("Upgrade YESNET: kolom customers.user_id tidak ditemukan.");
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
        "Upgrade YESNET customer query error: " .
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
| PAKET SAAT INI (tabel paket_wifi)
|--------------------------------------------------------------------------
*/

$paketTableReady =
    tableExists($conn, 'paket_wifi') &&
    columnExists($conn, 'paket_wifi', 'id') &&
    columnExists($conn, 'paket_wifi', 'nama_paket') &&
    columnExists($conn, 'paket_wifi', 'speed_mbps') &&
    columnExists($conn, 'paket_wifi', 'harga');

if ($paketTableReady) {

    $statusColumnExists = columnExists($conn, 'paket_wifi', 'status');

    // Kolom wilayah/area paket (JATIM, JAKARTA, dst.). Nama kolom dideteksi otomatis.
    foreach (['wilayah', 'area', 'region', 'lokasi', 'kota'] as $candidate) {
        if (columnExists($conn, 'paket_wifi', $candidate)) {
            $regionColumn = $candidate;
            break;
        }
    }

    $hasDescription = columnExists($conn, 'paket_wifi', 'deskripsi');

    $selectColumns = "id, nama_paket, speed_mbps, harga";

    if ($regionColumn !== null) {
        $selectColumns .= ", `{$regionColumn}` AS wilayah";
    }

    if ($hasDescription) {
        $selectColumns .= ", deskripsi";
    }

    $paketId = (int) ($customer['paket_id'] ?? 0);

    if ($paketId > 0 && columnExists($conn, 'customers', 'paket_id')) {
        try {
            $stmt = $conn->prepare("
                SELECT {$selectColumns}
                FROM paket_wifi
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $paketId);
            $stmt->execute();
            $rows = statementFetchAll($stmt);
            $stmt->close();

            $currentPaket = $rows[0] ?? null;
        } catch (Throwable $e) {
            error_log('Upgrade YESNET current package query failed: ' . $e->getMessage());
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DAFTAR PAKET YANG BISA DI-UPGRADE
    |--------------------------------------------------------------------------
    | Hanya paket aktif, di wilayah yang sama dengan paket saat ini,
    | dan dengan kecepatan lebih tinggi.
    */

    if ($currentPaket !== null) {
        try {
            $sql = "
                SELECT {$selectColumns}
                FROM paket_wifi
                WHERE speed_mbps > ?
            ";

            $types = "d";
            $params = [(float) $currentPaket['speed_mbps']];

            if ($statusColumnExists) {
                $sql .= " AND LOWER(status) IN ('aktif', 'active')";
            }

            if ($regionColumn !== null && !empty($currentPaket['wilayah'])) {
                $sql .= " AND `{$regionColumn}` = ?";
                $types .= "s";
                $params[] = (string) $currentPaket['wilayah'];
            }

            $sql .= " ORDER BY speed_mbps ASC, harga ASC, id ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $packages = statementFetchAll($stmt);
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Upgrade YESNET package list query failed: ' . $e->getMessage());
            $packages = [];
        }
    }
}


/*
|--------------------------------------------------------------------------
| TABEL PENGAJUAN UPGRADE
|--------------------------------------------------------------------------
| Struktur yang dibutuhkan: lihat file upgrade_requests.sql
*/

$requestTableReady =
    tableExists($conn, 'upgrade_requests') &&
    columnExists($conn, 'upgrade_requests', 'customer_id') &&
    columnExists($conn, 'upgrade_requests', 'current_paket_id') &&
    columnExists($conn, 'upgrade_requests', 'requested_paket_id') &&
    columnExists($conn, 'upgrade_requests', 'status') &&
    columnExists($conn, 'upgrade_requests', 'created_at');

if ($requestTableReady && $customerId > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT r.id, r.created_at, p.nama_paket, p.speed_mbps
            FROM upgrade_requests r
            LEFT JOIN paket_wifi p ON p.id = r.requested_paket_id
            WHERE r.customer_id = ?
              AND r.status = 'pending'
            ORDER BY r.id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();

        $pendingRequest = $rows[0] ?? null;
    } catch (Throwable $e) {
        error_log('Upgrade YESNET pending request query failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| POST: AJUKAN UPGRADE (JSON)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        jsonResponse([
            'success' => false,
            'message' => 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'
        ], 403);
    }

    if ($statusLangganan !== 'active') {
        jsonResponse([
            'success' => false,
            'message' => 'Upgrade hanya tersedia untuk layanan yang aktif.'
        ], 403);
    }

    if ($currentPaket === null) {
        jsonResponse([
            'success' => false,
            'message' => 'Paket saat ini tidak ditemukan pada akun kamu.'
        ], 422);
    }

    if (!$requestTableReady) {
        error_log('Upgrade YESNET: tabel upgrade_requests belum tersedia.');
        jsonResponse([
            'success' => false,
            'message' => 'Fitur pengajuan upgrade belum disiapkan. Hubungi Customer Service.'
        ], 503);
    }

    if ($pendingRequest !== null) {
        jsonResponse([
            'success' => false,
            'message' => 'Kamu masih memiliki pengajuan upgrade yang sedang diproses.'
        ], 409);
    }

    $requestedId = (int) ($_POST['paket_id'] ?? 0);

    // Paket harus ada di daftar yang valid (aktif, wilayah sama, lebih cepat).
    // Harga dan kecepatan selalu diambil dari database, bukan dari browser.
    $selectedPackage = null;

    foreach ($packages as $package) {
        if ((int) $package['id'] === $requestedId) {
            $selectedPackage = $package;
            break;
        }
    }

    if ($selectedPackage === null) {
        jsonResponse([
            'success' => false,
            'message' => 'Paket yang dipilih tidak tersedia untuk upgrade.'
        ], 422);
    }

    try {
        $currentId = (int) $currentPaket['id'];

        $stmt = $conn->prepare("
            INSERT INTO upgrade_requests
                (customer_id, current_paket_id, requested_paket_id, status, created_at)
            VALUES
                (?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iii", $customerId, $currentId, $requestedId);
        $stmt->execute();
        $stmt->close();

        jsonResponse([
            'success' => true,
            'message' => 'Pengajuan upgrade ke paket '
                . strtoupper((string) $selectedPackage['nama_paket'])
                . ' berhasil dikirim. Customer Service akan menghubungi kamu.'
        ]);
    } catch (Throwable $e) {
        error_log('Upgrade YESNET insert failed: ' . $e->getMessage());

        jsonResponse([
            'success' => false,
            'message' => 'Pengajuan gagal disimpan. Silakan coba lagi.'
        ], 500);
    }
}


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
        error_log('Upgrade YESNET complaint count failed: ' . $e->getMessage());
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
        error_log('Upgrade YESNET notification count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| DATA TAMPILAN
|--------------------------------------------------------------------------
*/

$currentName = $currentPaket
    ? strtoupper((string) $currentPaket['nama_paket'])
    : '';

$currentSpeedLabel = $currentPaket
    ? formatSpeed($currentPaket['speed_mbps'])
    : '-';

$currentPrice = $currentPaket
    ? (float) $currentPaket['harga']
    : 0.0;

$currentRegion = ($currentPaket && !empty($currentPaket['wilayah']))
    ? (string) $currentPaket['wilayah']
    : '';

$canUpgrade = $statusLangganan === 'active'
    && $currentPaket !== null
    && $requestTableReady
    && $pendingRequest === null;

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
        Upgrade Paket - YESNET
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


    <!-- Halaman Upgrade -->

    <link
        rel="stylesheet"
        href="assets/css/upgrade.css"
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

                    <a href="upgrade.php" class="menu-item <?= $currentPage === 'upgrade.php' ? 'active' : ''; ?>">
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
                        Upgrade Paket
                    </div>

                    <h1>Upgrade Paket</h1>

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


            <?php if ($statusLangganan !== 'active'): ?>

                <!-- =============================================
                     LAYANAN BELUM AKTIF
                ============================================== -->

                <section class="state-card up-state">

                    <div class="state-icon">
                        <i class="bi bi-arrow-up-circle-fill"></i>
                    </div>

                    <span class="state-label">UPGRADE PAKET</span>

                    <h2>
                        <?php if ($statusLangganan === 'pending'): ?>
                            Upgrade tersedia setelah layanan aktif
                        <?php elseif ($statusLangganan === 'belum_berlangganan'): ?>
                            Kamu belum berlangganan paket internet
                        <?php else: ?>
                            Upgrade tidak tersedia untuk layanan ini
                        <?php endif; ?>
                    </h2>

                    <p>
                        <?php if ($statusLangganan === 'pending'): ?>
                            Pemasangan kamu masih diproses. Setelah layanan aktif,
                            kamu bisa mengajukan upgrade paket dari halaman ini.
                        <?php elseif ($statusLangganan === 'belum_berlangganan'): ?>
                            Pilih paket internet terlebih dahulu. Setelah layanan aktif,
                            kamu bisa meningkatkan kecepatan kapan saja.
                        <?php else: ?>
                            Status layanan kamu saat ini tidak memungkinkan perubahan paket.
                            Hubungi Customer Service untuk informasi lebih lanjut.
                        <?php endif; ?>
                    </p>

                    <?php if ($statusLangganan === 'belum_berlangganan'): ?>
                        <a href="../paket.php?mode=direct" class="state-button">
                            <i class="bi bi-box-seam-fill"></i>
                            Pilih Paket Internet
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    <?php elseif ($statusLangganan === 'pending'): ?>
                        <a href="installation.php" class="state-button">
                            <i class="bi bi-geo-alt-fill"></i>
                            Lihat Status Pemasangan
                        </a>
                    <?php else: ?>
                        <a href="chat.php" class="state-button">
                            <i class="bi bi-headset"></i>
                            Hubungi Customer Service
                        </a>
                    <?php endif; ?>

                </section>

            <?php else: ?>


                <!-- =============================================
                     HERO
                ============================================== -->

                <section class="welcome-hero up-hero">

                    <div class="hero-decoration hero-decoration-one"></div>

                    <div class="hero-decoration hero-decoration-two"></div>

                    <div class="hero-content">

                        <div class="hero-label">
                            <span class="hero-label-dot"></span>
                            UPGRADE INTERNET
                        </div>

                        <h2>Tingkatkan Kecepatan WiFi Kamu</h2>

                        <p>
                            Pilih paket dengan kecepatan lebih tinggi sesuai kebutuhan
                            streaming, bekerja, dan perangkat di rumah kamu.
                        </p>

                    </div>

                    <div class="hero-visual">

                        <div class="wifi-orbit orbit-one"></div>

                        <div class="wifi-orbit orbit-two"></div>

                        <div class="wifi-core">
                            <i class="bi bi-rocket-takeoff-fill"></i>
                        </div>

                        <span class="signal-dot dot-one"></span>
                        <span class="signal-dot dot-two"></span>
                        <span class="signal-dot dot-three"></span>

                    </div>

                </section>


                <!-- =============================================
                     PAKET SAAT INI
                ============================================== -->

                <section class="dashboard-card up-current">

                    <div class="up-current-icon">
                        <i class="bi bi-router-fill"></i>
                    </div>

                    <div class="up-current-info">

                        <span class="card-kicker">PAKET SAAT INI</span>

                        <?php if ($currentPaket): ?>

                            <h3><?= e($currentName); ?></h3>

                            <div class="up-current-meta">
                                <span>
                                    <i class="bi bi-lightning-charge"></i>
                                    <?= e($currentSpeedLabel); ?>
                                </span>
                                <span>
                                    <i class="bi bi-cash-stack"></i>
                                    <?= e(rupiah($currentPrice)); ?> / bulan
                                </span>
                                <?php if ($currentRegion !== ''): ?>
                                    <span>
                                        <i class="bi bi-geo-alt"></i>
                                        <?= e($currentRegion); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>

                            <h3>Paket belum terhubung</h3>

                            <div class="up-current-meta">
                                <span>
                                    Data paket pada akun kamu belum dapat dibaca.
                                    Hubungi Customer Service.
                                </span>
                            </div>

                        <?php endif; ?>

                    </div>

                    <span class="active-pill">
                        <i class="bi bi-check-circle-fill"></i>
                        AKTIF
                    </span>

                </section>


                <!-- =============================================
                     PENGAJUAN SEDANG DIPROSES
                ============================================== -->

                <?php if ($pendingRequest): ?>

                    <div class="up-banner up-banner-pending" role="status">

                        <i class="bi bi-hourglass-split"></i>

                        <div>
                            <strong>Pengajuan upgrade sedang diproses</strong>
                            <span>
                                Kamu mengajukan paket
                                <?= e(strtoupper((string) ($pendingRequest['nama_paket'] ?? '-'))); ?>
                                <?php if (!empty($pendingRequest['speed_mbps'])): ?>
                                    (<?= e(formatSpeed($pendingRequest['speed_mbps'])); ?>)
                                <?php endif; ?>
                                pada
                                <?= e(date('d M Y, H:i', strtotime((string) $pendingRequest['created_at']))); ?> WIB.
                                Customer Service akan menghubungi kamu.
                            </span>
                        </div>

                        <a href="chat.php" class="view-all">
                            Chat CS <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                <?php elseif (!$requestTableReady): ?>

                    <div class="up-banner up-banner-warning" role="status">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <div>
                            <strong>Pengajuan online belum tersedia</strong>
                            <span>
                                Untuk saat ini upgrade diproses melalui Customer Service.
                            </span>
                        </div>

                        <a href="chat.php" class="view-all">
                            Chat CS <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                <?php endif; ?>


                <!-- =============================================
                     DAFTAR PAKET
                ============================================== -->

                <div class="section-title-row">

                    <div>

                        <span class="section-kicker">PILIH PAKET</span>

                        <h2>Paket Upgrade Tersedia</h2>

                    </div>

                    <?php if ($currentRegion !== ''): ?>
                        <span class="section-date">
                            <i class="bi bi-geo-alt"></i>
                            Wilayah <?= e($currentRegion); ?>
                        </span>
                    <?php endif; ?>

                </div>


                <?php if (!$paketTableReady): ?>

                    <div class="up-empty">
                        <i class="bi bi-box-seam"></i>
                        <strong>Data paket belum tersedia</strong>
                        <span>Tabel paket belum dapat dibaca. Hubungi Customer Service.</span>
                    </div>

                <?php elseif ($currentPaket === null): ?>

                    <div class="up-empty">
                        <i class="bi bi-question-circle"></i>
                        <strong>Paket saat ini belum diketahui</strong>
                        <span>Kami belum bisa menentukan paket upgrade untuk akun kamu.</span>
                    </div>

                <?php elseif (empty($packages)): ?>

                    <div class="up-empty">
                        <i class="bi bi-trophy"></i>
                        <strong>Kamu sudah di paket tertinggi</strong>
                        <span>
                            Tidak ada paket dengan kecepatan lebih tinggi di wilayah
                            <?= e($currentRegion !== '' ? $currentRegion : 'kamu'); ?> saat ini.
                        </span>
                    </div>

                <?php else: ?>

                    <div class="up-grid">

                        <?php foreach ($packages as $index => $package): ?>

                            <?php
                            $packageName = strtoupper((string) $package['nama_paket']);
                            $packageSpeed = (float) $package['speed_mbps'];
                            $packagePrice = (float) $package['harga'];
                            $speedGain = $packageSpeed - (float) $currentPaket['speed_mbps'];
                            $priceDiff = $packagePrice - $currentPrice;
                            $isNext = $index === 0;
                            ?>

                            <article class="up-package <?= $isNext ? 'is-next' : ''; ?>">

                                <?php if ($isNext): ?>
                                    <div class="up-badge">
                                        <i class="bi bi-arrow-up-short"></i>
                                        UPGRADE TERDEKAT
                                    </div>
                                <?php endif; ?>

                                <div class="up-package-icon">
                                    <i class="bi bi-wifi"></i>
                                </div>

                                <h3><?= e($packageName); ?></h3>

                                <div class="up-speed">
                                    <?= e(formatSpeed($packageSpeed)); ?>
                                </div>

                                <?php if (!empty($package['deskripsi'])): ?>
                                    <p class="up-description">
                                        <?= e($package['deskripsi']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="up-price">
                                    <strong><?= e(rupiah($packagePrice)); ?></strong>
                                    <span>/ bulan</span>
                                </div>

                                <div class="up-divider"></div>

                                <ul class="up-features">

                                    <li>
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            Kecepatan hingga <?= e(formatSpeed($packageSpeed)); ?>
                                        </span>
                                    </li>

                                    <li>
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            +<?= e(formatSpeed($speedGain)); ?> dari paket saat ini
                                        </span>
                                    </li>

                                    <li>
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            <?php if ($priceDiff > 0): ?>
                                                Selisih <?= e(rupiah($priceDiff)); ?> / bulan
                                            <?php elseif ($priceDiff < 0): ?>
                                                Lebih hemat <?= e(rupiah(abs($priceDiff))); ?> / bulan
                                            <?php else: ?>
                                                Harga sama dengan paket saat ini
                                            <?php endif; ?>
                                        </span>
                                    </li>

                                    <?php if (!empty($package['wilayah'])): ?>
                                        <li>
                                            <i class="bi bi-check-circle-fill"></i>
                                            <span>Wilayah <?= e($package['wilayah']); ?></span>
                                        </li>
                                    <?php endif; ?>

                                </ul>

                                <button
                                    type="button"
                                    class="up-button"
                                    data-id="<?= (int) $package['id']; ?>"
                                    data-name="<?= e($packageName); ?>"
                                    data-speed="<?= e(formatSpeed($packageSpeed)); ?>"
                                    data-price="<?= e(rupiah($packagePrice)); ?>"
                                    <?= $canUpgrade ? '' : 'disabled'; ?>
                                >
                                    <i class="bi bi-rocket-takeoff"></i>
                                    <?= $pendingRequest
                                        ? 'Pengajuan diproses'
                                        : 'Upgrade Sekarang'; ?>
                                </button>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <!-- =============================================
                     INFORMASI
                ============================================== -->

                <div class="up-info dashboard-card">

                    <div class="up-info-icon">
                        <i class="bi bi-info-circle-fill"></i>
                    </div>

                    <div class="up-info-text">
                        <strong>Informasi Upgrade</strong>
                        <p>
                            Setelah mengajukan upgrade, tim Customer Service akan
                            menghubungi kamu untuk konfirmasi dan jadwal perubahan paket.
                            Tagihan baru mengikuti harga paket yang dipilih.
                        </p>
                    </div>

                    <a href="chat.php" class="up-info-button">
                        <i class="bi bi-headset"></i>
                        Hubungi CS
                    </a>

                </div>

            <?php endif; ?>


            <!-- FOOTER -->

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


<!-- =====================================================
     MODAL KONFIRMASI
====================================================== -->

<dialog class="up-dialog" id="upgradeDialog" aria-labelledby="upgradeDialogTitle">

    <div class="up-dialog-header">

        <div class="up-dialog-icon">
            <i class="bi bi-rocket-takeoff-fill"></i>
        </div>

        <div>
            <h3 id="upgradeDialogTitle">Konfirmasi Upgrade</h3>
            <span>Periksa paket baru kamu</span>
        </div>

        <button
            type="button"
            class="up-dialog-close"
            id="dialogClose"
            aria-label="Tutup"
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <div class="up-dialog-body">

        <div class="up-compare">

            <div>
                <span>Paket saat ini</span>
                <strong><?= e($currentName ?: '-'); ?></strong>
                <small><?= e($currentSpeedLabel); ?></small>
            </div>

            <i class="bi bi-arrow-right"></i>

            <div class="is-new">
                <span>Paket baru</span>
                <strong id="dialogName">-</strong>
                <small id="dialogSpeed">-</small>
            </div>

        </div>

        <div class="up-dialog-price">
            <span>Harga paket baru</span>
            <strong id="dialogPrice">-</strong>
        </div>

        <div class="up-dialog-note" id="dialogNote">
            <i class="bi bi-info-circle-fill"></i>
            <p>
                Pengajuan akan diteruskan ke Customer Service untuk diproses.
                Pastikan paket yang dipilih sudah sesuai.
            </p>
        </div>

        <div class="up-dialog-result" id="dialogResult" role="status" hidden></div>

    </div>

    <div class="up-dialog-footer">

        <button type="button" class="up-btn-cancel" id="dialogCancel">
            Batal
        </button>

        <button type="button" class="up-btn-confirm" id="dialogConfirm">
            <i class="bi bi-check-circle-fill"></i>
            Ajukan Upgrade
        </button>

    </div>

</dialog>


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
    | DIALOG UPGRADE
    |--------------------------------------------------------------------------
    */

    const dialog = document.getElementById('upgradeDialog');
    const confirmButton = document.getElementById('dialogConfirm');
    const cancelButton = document.getElementById('dialogCancel');
    const dialogCloseButton = document.getElementById('dialogClose');
    const resultBox = document.getElementById('dialogResult');
    const noteBox = document.getElementById('dialogNote');

    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const confirmDefaultHtml = confirmButton ? confirmButton.innerHTML : '';

    let selectedId = null;
    let submitted = false;

    function showResult(message, isSuccess) {
        if (!resultBox) {
            return;
        }

        resultBox.textContent = message;
        resultBox.className = 'up-dialog-result ' + (isSuccess ? 'is-success' : 'is-error');
        resultBox.hidden = false;
    }

    function closeDialog() {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    document.querySelectorAll('.up-button').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!dialog || button.disabled) {
                return;
            }

            selectedId = button.dataset.id;
            submitted = false;

            document.getElementById('dialogName').textContent = button.dataset.name || '-';
            document.getElementById('dialogSpeed').textContent = button.dataset.speed || '-';
            document.getElementById('dialogPrice').textContent = button.dataset.price || '-';

            if (resultBox) {
                resultBox.hidden = true;
                resultBox.textContent = '';
            }

            if (noteBox) {
                noteBox.hidden = false;
            }

            if (confirmButton) {
                confirmButton.hidden = false;
                confirmButton.disabled = false;
                confirmButton.innerHTML = confirmDefaultHtml;
            }

            if (cancelButton) {
                cancelButton.textContent = 'Batal';
            }

            dialog.showModal();
        });
    });

    if (cancelButton) {
        cancelButton.addEventListener('click', closeDialog);
    }

    if (dialogCloseButton) {
        dialogCloseButton.addEventListener('click', closeDialog);
    }

    if (dialog) {
        // Klik di luar kotak dialog menutupnya.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        // Setelah berhasil mengajukan, muat ulang agar status pengajuan tampil.
        dialog.addEventListener('close', function () {
            if (submitted) {
                window.location.reload();
            }
        });
    }

    if (confirmButton) {
        confirmButton.addEventListener('click', async function () {
            if (!selectedId) {
                return;
            }

            confirmButton.disabled = true;
            confirmButton.innerHTML =
                '<span class="up-spinner"></span> Memproses...';

            try {
                const body = new URLSearchParams();
                body.set('csrf_token', csrfToken);
                body.set('paket_id', selectedId);

                const response = await fetch('upgrade.php', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    credentials: 'same-origin',
                    body: body.toString()
                });

                const payload = await response.json();

                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Pengajuan gagal dikirim.');
                }

                submitted = true;
                showResult(payload.message, true);

                if (noteBox) {
                    noteBox.hidden = true;
                }

                confirmButton.hidden = true;

                if (cancelButton) {
                    cancelButton.textContent = 'Selesai';
                }
            } catch (error) {
                console.error('Pengajuan upgrade gagal:', error);
                showResult(
                    error.message || 'Pengajuan gagal dikirim. Silakan coba lagi.',
                    false
                );

                confirmButton.disabled = false;
                confirmButton.innerHTML = confirmDefaultHtml;
            }
        });
    }

});

</script>

</body>

</html>