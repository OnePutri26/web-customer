<?php

session_start();

require_once "../config/auth.php";

requireLogin();

require_once "../config/database.php";
require_once __DIR__ . '/../inc/complaint.php';

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

function textLength(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

function complaintCreateInput(array $input, string $key): string
{
    $value = $input[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}


/*
|--------------------------------------------------------------------------
| OPSI FORM
|--------------------------------------------------------------------------
| Nilai (key) disimpan ke database; label hanya untuk tampilan.
| Daftar key harus sinkron dengan CATEGORY_LABELS di complaint.php.
*/

$categoryGroups = [
    'Gangguan Internet' => YESNET_COMPLAINT_CATEGORIES,
];

$categories = [];

foreach ($categoryGroups as $groupItems) {
    $categories += $groupItems;
}

$priorities = [
    'low'      => ['Rendah', 'Tidak mengganggu aktivitas'],
    'medium'   => ['Sedang', 'Mengganggu sebagian aktivitas'],
    'high'     => ['Tinggi', 'Internet sangat terganggu'],
    'critical' => ['Kritis', 'Mati total / sangat mendesak'],
];

// Prioritas yang disarankan otomatis saat jenis gangguan dipilih.
$suggestedPriority = [
    'internet_down'   => 'high',
    'slow_connection' => 'medium',
    'unstable_connection' => 'medium',
    'modem_ont'       => 'medium',
    'wifi_not_detected' => 'medium',
    'los_red'         => 'high',
    'other'           => 'medium',
];

// Detail tambahan (opsional) agar tim teknis cepat memahami masalahnya.
$startedOptions = [
    'now'       => 'Baru saja (kurang dari 1 jam)',
    'today'     => 'Hari ini',
    'yesterday' => 'Kemarin',
    'days'      => 'Beberapa hari terakhir',
    'week'      => 'Lebih dari seminggu',
];

$scopeOptions = [
    'all'     => 'Semua perangkat',
    'single'  => 'Satu perangkat saja',
    'wifi'    => 'Hanya lewat WiFi',
    'lan'     => 'Hanya lewat kabel LAN',
    'unknown' => 'Tidak tahu',
];

const SUBJECT_MIN = 5;
const SUBJECT_MAX = 150;
const DESCRIPTION_MIN = 10;
// Sedikit di bawah 2000 agar detail tambahan yang ditempelkan tetap muat.
const DESCRIPTION_MAX = 1800;
const HOURLY_LIMIT = 5;


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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_complaint'])) {
    $_SESSION['csrf_complaint'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_complaint'];


/*
|--------------------------------------------------------------------------
| DEFAULT DATA
|--------------------------------------------------------------------------
*/

$customer = null;
$customerId = 0;

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Customer';

$statusLangganan = 'belum_berlangganan';
$totalGangguan = 0;
$unreadNotifications = 0;

$errors = [];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$old = [
    'category'    => '',
    'subject'     => '',
    'priority'    => 'medium',
    'started'     => '',
    'scope'       => '',
    'description' => '',
];

// Jalan pintas dari complaint.php: complaint_create.php?category=internet_down
if (!$isPost) {
    $presetCategory = complaintCreateInput($_GET, 'category');

    if (isset($categories[$presetCategory])) {
        $old['category'] = $presetCategory;
        $old['priority'] = $suggestedPriority[$presetCategory] ?? 'medium';
    }
}


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Complaint Create YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}

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
        "Complaint Create YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data halaman gagal dimuat. Periksa struktur tabel customers atau error log hosting.");
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

// Laporan dapat dibuat oleh pelanggan aktif, ditangguhkan, atau yang masih
// menunggu pemasangan (mis. kendala saat instalasi). Hanya akun tanpa
// langganan / berakhir yang diarahkan ke Customer Care.
$canCreate = in_array($statusLangganan, ['active', 'suspended', 'pending'], true);


/*
|--------------------------------------------------------------------------
| TABEL COMPLAINT SIAP?
|--------------------------------------------------------------------------
*/

$complaintReady = tableExists($conn, 'complaint');

foreach (['id_customer', 'complaint_code', 'category', 'subject', 'description', 'priority', 'status', 'assigned_user_id'] as $requiredColumn) {
    $complaintReady = $complaintReady && columnExists($conn, 'complaint', $requiredColumn);
}

$complaintReady = $complaintReady
    && tableExists($conn, 'complaint_histories')
    && tableExists($conn, 'complaint_attachments');

$hasCreatedAt = $complaintReady && columnExists($conn, 'complaint', 'created_at');
$hasStatus = $complaintReady && columnExists($conn, 'complaint', 'status');


/*
|--------------------------------------------------------------------------
| POST: SIMPAN LAPORAN
|--------------------------------------------------------------------------
*/

if ($isPost) {

    $old['category'] = complaintCreateInput($_POST, 'category');
    $old['subject'] = complaintCreateInput($_POST, 'subject');
    $old['priority'] = complaintCreateInput($_POST, 'priority') ?: 'medium';
    $old['started'] = complaintCreateInput($_POST, 'started');
    $old['scope'] = complaintCreateInput($_POST, 'scope');
    $old['description'] = complaintCreateInput($_POST, 'description');

    $postToken = complaintCreateInput($_POST, 'csrf_token');

    if (!hash_equals($csrfToken, $postToken)) {

        $errors[] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';

    } elseif (!$canCreate) {

        $errors[] = 'Laporan gangguan hanya dapat dibuat oleh pelanggan dengan layanan aktif atau dalam proses pemasangan.';

    } elseif (!$complaintReady) {

        error_log('Complaint Create YESNET: struktur tabel complaint belum sesuai.');
        $errors[] = 'Sistem laporan belum siap digunakan. Silakan hubungi Customer Care.';

    } else {

        if (!isset($categories[$old['category']])) {
            $errors[] = 'Pilih jenis gangguan.';
        }

        if (!isset($priorities[$old['priority']])) {
            $errors[] = 'Pilih tingkat prioritas.';
        }

        if ($old['started'] !== '' && !isset($startedOptions[$old['started']])) {
            $errors[] = 'Pilihan "kapan mulai terjadi" tidak valid.';
        }

        if ($old['scope'] !== '' && !isset($scopeOptions[$old['scope']])) {
            $errors[] = 'Pilihan "perangkat yang terdampak" tidak valid.';
        }

        $subjectLength = textLength($old['subject']);

        if ($subjectLength < SUBJECT_MIN) {
            $errors[] = 'Judul minimal ' . SUBJECT_MIN . ' karakter.';
        } elseif ($subjectLength > SUBJECT_MAX) {
            $errors[] = 'Judul maksimal ' . SUBJECT_MAX . ' karakter.';
        }

        $descriptionLength = textLength($old['description']);

        if ($descriptionLength < DESCRIPTION_MIN) {
            $errors[] = 'Deskripsi minimal ' . DESCRIPTION_MIN . ' karakter agar tim dapat memahami masalahnya.';
        } elseif ($descriptionLength > DESCRIPTION_MAX) {
            $errors[] = 'Deskripsi maksimal ' . DESCRIPTION_MAX . ' karakter.';
        }

        // Batasi jumlah laporan per jam untuk mencegah pengiriman berulang.
        if ($errors === [] && $hasCreatedAt) {
            try {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM complaint
                    WHERE id_customer = ?
                      AND created_at >= (NOW() - INTERVAL 1 HOUR)
                ");
                $stmt->bind_param("i", $customerId);
                $stmt->execute();
                $rows = statementFetchAll($stmt);
                $stmt->close();

                if ((int) ($rows[0]['total'] ?? 0) >= HOURLY_LIMIT) {
                    $errors[] = 'Kamu sudah membuat ' . HOURLY_LIMIT
                        . ' laporan dalam 1 jam terakhir. Silakan tunggu atau hubungi Technical Support lewat live chat.';
                }
            } catch (Throwable $e) {
                error_log('Complaint Create YESNET rate limit check failed: ' . $e->getMessage());
            }
        }

        $upload = null;
        if ($errors === [] && isset($_FILES['photo'])) {
            try {
                $upload = yesnetComplaintReadUpload($_FILES['photo']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors === []) {

            // Detail tambahan ditempelkan di akhir deskripsi sehingga tidak
            // perlu kolom baru di tabel complaint.
            $details = [];

            if ($old['started'] !== '') {
                $details[] = 'Mulai terjadi: ' . $startedOptions[$old['started']];
            }

            if ($old['scope'] !== '') {
                $details[] = 'Perangkat terdampak: ' . $scopeOptions[$old['scope']];
            }

            $storedDescription = $old['description'];

            if ($details !== []) {
                $storedDescription .= "\n\n— " . implode(' · ', $details);
            }

            $savedCode = null;

            // A unique database index protects ticket codes across concurrent requests.
            for ($attempt = 1; $attempt <= 3 && $savedCode === null; $attempt++) {

                $code = 'CMP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));

                try {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("
                        INSERT INTO complaint
                            (id_customer, complaint_code, category, subject, description, priority, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->bind_param(
                        "isssss",
                        $customerId,
                        $code,
                        $old['category'],
                        $old['subject'],
                        $storedDescription,
                        $old['priority']
                    );
                    $stmt->execute();
                    $complaintId = (int) $stmt->insert_id;
                    $stmt->close();

                    if ($upload !== null) {
                        $attachmentStmt = $conn->prepare("
                            INSERT INTO complaint_attachments (complaint_id, mime_type, contents)
                            VALUES (?, ?, ?)
                        ");
                        $attachmentMime = $upload['mime'];
                        $attachmentContents = null;
                        $attachmentStmt->bind_param('isb', $complaintId, $attachmentMime, $attachmentContents);
                        $attachmentStmt->send_long_data(2, $upload['contents']);
                        $attachmentStmt->execute();
                        $attachmentStmt->close();
                    }

                    yesnetComplaintAddHistory(
                        $conn,
                        $complaintId,
                        $userId,
                        $nama,
                        'created',
                        'Laporan dibuat oleh pelanggan.',
                        null,
                        'pending'
                    );
                    $conn->commit();
                    $savedCode = $code;
                } catch (Throwable $e) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Complaint create rollback failed: ' . $rollbackError->getMessage());
                    }
                    $isDuplicate = $e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062;

                    if (!$isDuplicate || $attempt === 3) {
                        error_log('Complaint Create YESNET insert failed: ' . $e->getMessage());
                        $errors[] = 'Laporan belum dapat disimpan. Silakan coba lagi.';
                        break;
                    }
                }
            }

            if ($savedCode !== null) {
                $_SESSION['complaint_flash'] = [
                    'type'    => 'success',
                    'message' => 'Laporan ' . $savedCode . ' berhasil dikirim. Tim kami akan segera menanganinya.',
                ];

                header("Location: complaint.php");
                exit;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| BADGE SIDEBAR: LAPORAN GANGGUAN AKTIF
|--------------------------------------------------------------------------
*/

if ($complaintReady) {
    try {
        $sqlCount = "SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?";

        if ($hasStatus) {
            $sqlCount .= " AND LOWER(TRIM(status)) NOT IN ('closed', 'selesai', 'resolved')";
        }

        $stmt = $conn->prepare($sqlCount);
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Complaint Create YESNET badge count failed: ' . $e->getMessage());
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
        error_log('Complaint Create YESNET notification count failed: ' . $e->getMessage());
    }
}


$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));

$currentPage = 'complaint.php'; // Menu "Laporan Gangguan" tetap aktif.

$priorityLabelMap = [];

foreach ($priorities as $priorityKey => $priorityInfo) {
    $priorityLabelMap[$priorityKey] = $priorityInfo[0];
}

?>
<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#0f4cdb">

    <title>Buat Laporan Gangguan - YESNET</title>

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

    <!-- Complaint CSS (daftar + form) -->
    <link rel="stylesheet" href="assets/css/complaint.css?v=14">

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

                <a href="dashboard.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a href="usage.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-router-fill"></i></span>
                    <span class="menu-text">Internet Saya</span>
                </a>

                <a href="billing.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a href="billing.php#riwayat-pembayaran" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="menu-text">Riwayat Pembayaran</span>
                </a>

                <a href="speedtest.php" class="menu-item">
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

                <a href="network_status.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-globe2"></i></span>
                    <span class="menu-text">Status Jaringan</span>
                </a>

                <a href="upgrade.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                    <span class="menu-text">Upgrade Paket</span>
                </a>

                <a href="chat.php" class="menu-item">
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
                        <span>/</span>
                        Buat Laporan
                    </div>

                    <h1>Buat Laporan</h1>
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


            <div class="section-title-row cp-head">

                <div>
                    <span class="section-kicker">CUSTOMER SUPPORT</span>
                    <h2>Buat Laporan Gangguan</h2>
                </div>

                <a href="complaint.php" class="cp-link">
                    <i class="bi bi-arrow-left"></i>
                    Kembali ke daftar laporan
                </a>

            </div>


            <div class="cp-form-layout">


                <!-- =============================================
                     FORM
                ============================================== -->

                <section class="dashboard-card cp-form-card">

                    <div class="card-heading compact">

                        <div>
                            <span class="card-kicker">DETAIL MASALAH</span>
                            <h3>Ceritakan gangguan yang kamu alami</h3>
                            <p>Gangguan internet apa pun bisa dilaporkan. Semakin jelas informasinya, semakin cepat tim kami menangani.</p>
                        </div>

                    </div>


                    <?php if ($errors !== []): ?>

                        <div class="cp-alert" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>
                                <strong>Laporan belum dapat dikirim</strong>
                                <ul>
                                    <?php foreach ($errors as $message): ?>
                                        <li><?= e($message); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                    <?php endif; ?>


                    <?php if (!$canCreate): ?>

                        <div class="cp-empty cp-empty-compact">
                            <div class="cp-empty-icon"><i class="bi bi-lock"></i></div>
                            <h4>Laporan belum tersedia untuk akun ini</h4>
                            <p>
                                Laporan gangguan dapat dibuat oleh pelanggan yang layanan internetnya
                                aktif atau sedang dalam proses pemasangan. Jika ada kendala lain,
                                hubungi Customer Care.
                            </p>
                            <a href="chat.php" class="cp-create">
                                <i class="bi bi-headset"></i>
                                Hubungi Customer Care
                            </a>
                        </div>

                    <?php elseif (!$complaintReady): ?>

                        <div class="cp-empty cp-empty-compact">
                            <div class="cp-empty-icon"><i class="bi bi-plug"></i></div>
                            <h4>Sistem laporan belum siap</h4>
                            <p>
                                Fitur ini belum terhubung. Silakan hubungi Customer Care
                                untuk melaporkan gangguan.
                            </p>
                            <a href="chat.php" class="cp-create">
                                <i class="bi bi-headset"></i>
                                Hubungi Customer Care
                            </a>
                        </div>

                    <?php else: ?>

                        <form method="POST" action="complaint_create.php" class="cp-form" id="complaintForm" enctype="multipart/form-data" novalidate>

                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">

                            <div class="cp-field">
                                <label for="category">Jenis gangguan</label>

                                <select name="category" id="category" class="cp-input" required>
                                    <option value="" disabled <?= $old['category'] === '' ? 'selected' : ''; ?>>
                                        Pilih jenis gangguan
                                    </option>

                                    <?php foreach ($categoryGroups as $groupLabel => $groupItems): ?>
                                        <optgroup label="<?= e($groupLabel); ?>">
                                            <?php foreach ($groupItems as $value => $label): ?>
                                                <option value="<?= e($value); ?>" <?= $old['category'] === $value ? 'selected' : ''; ?>>
                                                    <?= e($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>

                                <small class="cp-select-hint">
                                    Tidak ada yang cocok? Pilih "Gangguan Lainnya" dan jelaskan di deskripsi.
                                </small>
                            </div>

                            <div class="cp-field">
                                <label for="subject">Judul laporan</label>

                                <input
                                    type="text"
                                    name="subject"
                                    id="subject"
                                    class="cp-input"
                                    maxlength="<?= SUBJECT_MAX; ?>"
                                    placeholder="Contoh: Internet mati sejak pagi"
                                    value="<?= e($old['subject']); ?>"
                                    required
                                >
                            </div>

                            <div class="cp-row-2">

                                <div class="cp-field">
                                    <label for="started">Kapan mulai terjadi? <em>(opsional)</em></label>

                                    <select name="started" id="started" class="cp-input">
                                        <option value="">Pilih waktu</option>
                                        <?php foreach ($startedOptions as $value => $label): ?>
                                            <option value="<?= e($value); ?>" <?= $old['started'] === $value ? 'selected' : ''; ?>>
                                                <?= e($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="cp-field">
                                    <label for="scope">Perangkat yang terdampak <em>(opsional)</em></label>

                                    <select name="scope" id="scope" class="cp-input">
                                        <option value="">Pilih cakupan</option>
                                        <?php foreach ($scopeOptions as $value => $label): ?>
                                            <option value="<?= e($value); ?>" <?= $old['scope'] === $value ? 'selected' : ''; ?>>
                                                <?= e($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                            <fieldset class="cp-field">
                                <legend>Tingkat prioritas</legend>

                                <div class="cp-priority">

                                    <?php foreach ($priorities as $value => [$label, $hint]): ?>

                                        <label class="cp-priority-option priority-<?= e($value); ?>">
                                            <input
                                                type="radio"
                                                name="priority"
                                                value="<?= e($value); ?>"
                                                <?= $old['priority'] === $value ? 'checked' : ''; ?>
                                            >
                                            <span class="cp-priority-body">
                                                <strong><?= e($label); ?></strong>
                                                <small><?= e($hint); ?></small>
                                            </span>
                                        </label>

                                    <?php endforeach; ?>

                                </div>

                                <small class="cp-select-hint" id="priorityHint" hidden></small>
                            </fieldset>

                            <div class="cp-field">

                                <label for="description">Deskripsi gangguan</label>

                                <textarea
                                    name="description"
                                    id="description"
                                    class="cp-input cp-textarea"
                                    rows="6"
                                    maxlength="<?= DESCRIPTION_MAX; ?>"
                                    placeholder="Jelaskan gangguannya: apa yang terjadi, perangkat apa yang terdampak, pesan error yang muncul, dan apa yang sudah kamu coba."
                                    required
                                ><?= e($old['description']); ?></textarea>

                                <div class="cp-counter">
                                    <span>Minimal <?= DESCRIPTION_MIN; ?> karakter</span>
                                    <span id="descriptionCount">0/<?= DESCRIPTION_MAX; ?></span>
                                </div>

                            </div>

                            <div class="cp-field">
                                <label for="photo">Foto bukti <em>(opsional, maksimal 5 MB)</em></label>
                                <input
                                    type="file"
                                    name="photo"
                                    id="photo"
                                    class="cp-input"
                                    accept="image/jpeg,image/png,image/webp"
                                >
                                <small class="cp-select-hint">Format JPG, PNG, atau WebP. Foto hanya dapat dilihat oleh customer pemilik laporan dan petugas YESNET.</small>
                            </div>

                            <div class="cp-form-actions">

                                <a href="complaint.php" class="cp-cancel">Batal</a>

                                <button type="submit" class="cp-create" id="submitButton">
                                    <i class="bi bi-send-fill"></i>
                                    Kirim Laporan
                                </button>

                            </div>

                        </form>

                    <?php endif; ?>

                </section>


                <!-- =============================================
                     PANEL SAMPING
                ============================================== -->

                <aside class="cp-form-side">

                    <div class="dashboard-card">

                        <div class="card-heading compact">
                            <div>
                                <span class="card-kicker">SEBELUM MELAPOR</span>
                                <h3>Coba cek dulu</h3>
                            </div>
                        </div>

                        <ul class="cp-tips">

                            <li>
                                <i class="bi bi-globe2"></i>
                                <span>
                                    Lihat
                                    <a href="network_status.php">Status Jaringan</a>
                                    untuk mengetahui apakah ada gangguan di area kamu.
                                </span>
                            </li>

                            <li>
                                <i class="bi bi-router-fill"></i>
                                <span>Cek lampu di ONT/modem. Lampu LOS merah berarti sinyal fiber terputus, sebutkan di laporan.</span>
                            </li>

                            <li>
                                <i class="bi bi-arrow-repeat"></i>
                                <span>Matikan router sebentar lalu nyalakan kembali.</span>
                            </li>

                            <li>
                                <i class="bi bi-speedometer2"></i>
                                <span>
                                    Jalankan
                                    <a href="speedtest.php">Speed Test</a>
                                    dan sertakan hasilnya di deskripsi.
                                </span>
                            </li>

                        </ul>

                    </div>


                    <div class="cp-help cp-help-stack">

                        <div class="cp-help-icon"><i class="bi bi-headset"></i></div>

                        <div>
                            <strong>Butuh bantuan segera?</strong>
                            <span>Untuk gangguan mendesak, hubungi Technical Support lewat live chat.</span>
                        </div>

                        <a href="chat.php?topic=complaint" class="cp-link">
                            Chat Technical Support <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                </aside>

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


    /*
    |----------------------------------------------------------------------
    | FORM
    |----------------------------------------------------------------------
    */

    const form = document.getElementById('complaintForm');
    const description = document.getElementById('description');
    const descriptionCount = document.getElementById('descriptionCount');
    const submitButton = document.getElementById('submitButton');
    const categorySelect = document.getElementById('category');
    const priorityHint = document.getElementById('priorityHint');
    const descriptionMax = <?= (int) DESCRIPTION_MAX; ?>;

    const suggestedPriority = <?= json_encode($suggestedPriority, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const priorityLabels = <?= json_encode($priorityLabelMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    // Jika form dikirim ulang (ada error), hormati pilihan prioritas pengguna.
    let priorityTouched = <?= $isPost ? 'true' : 'false'; ?>;

    function updateCount() {
        if (description && descriptionCount) {
            descriptionCount.textContent = description.value.length + '/' + descriptionMax;
        }
    }

    if (description) {
        description.addEventListener('input', updateCount);
        updateCount();
    }

    // Saran prioritas otomatis berdasarkan jenis gangguan.
    function applySuggestedPriority() {
        if (!categorySelect || priorityTouched) {
            return;
        }

        const suggestion = suggestedPriority[categorySelect.value];

        if (!suggestion) {
            return;
        }

        const radio = document.querySelector('input[name="priority"][value="' + suggestion + '"]');

        if (radio) {
            radio.checked = true;
        }

        if (priorityHint) {
            priorityHint.textContent = 'Prioritas disarankan: ' + (priorityLabels[suggestion] || suggestion)
                + '. Kamu tetap bisa mengubahnya.';
            priorityHint.hidden = false;
        }
    }

    // Pilihan manual pengguna tidak boleh ditimpa saran otomatis.
    document.querySelectorAll('input[name="priority"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            priorityTouched = true;

            if (priorityHint) {
                priorityHint.hidden = true;
            }
        });
    });

    if (categorySelect) {
        categorySelect.addEventListener('change', applySuggestedPriority);

        // Kategori sudah terpilih dari jalan pintas di complaint.php.
        if (categorySelect.value !== '') {
            applySuggestedPriority();
        }
    }

    // Cegah kirim ganda saat tombol ditekan berulang.
    if (form && submitButton) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="cp-spinner"></span> Mengirim...';
        });
    }

});

</script>

</body>

</html>