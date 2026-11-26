<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/service_notifications.php';
requireRole('customer');
date_default_timezone_set('Asia/Jakarta');

/* ======================== HELPER ======================== */
/* Dibungkus function_exists agar aman jika sebagian sudah didefinisikan di include bersama. */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah')) {
    function rupiah($value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $stmt = $conn->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $stmt->store_result();
            $cache[$table] = $stmt->num_rows > 0;
            $stmt->close();
        }
        return $cache[$table];
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!isset($cache[$key])) {
            if (!tableExists($conn, $table)) {
                return $cache[$key] = false;
            }
            $stmt = $conn->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $stmt->store_result();
            $cache[$key] = $stmt->num_rows > 0;
            $stmt->close();
        }
        return $cache[$key];
    }
}

if (!function_exists('allColumnsExist')) {
    function allColumnsExist(mysqli $conn, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!columnExists($conn, $table, $column)) {
                return false;
            }
        }
        return tableExists($conn, $table);
    }
}

if (!function_exists('statementFetchAll')) {
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
}

if (!function_exists('queryRows')) {
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
}

if (!function_exists('pick')) {
    function pick(array $row, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}

/* Cadangan jika normalizeCustomerStatus() tidak tersedia di include bersama. */
if (!function_exists('normalizeCustomerStatus')) {
    function normalizeCustomerStatus($status): string
    {
        $status = strtolower(trim((string) $status));
        return $status === '' ? 'unknown' : str_replace(' ', '_', $status);
    }
}

if (!function_exists('normalizeSubscriptionStatus')) {
    function normalizeSubscriptionStatus($status): string
    {
        $status = strtolower(trim((string) $status));

        if (in_array($status, ['terisolir', 'isolated'], true)) {
            return 'suspended';
        }
        if (in_array($status, ['berhenti berlangganan', 'dihentikan', 'berakhir', 'nonaktif'], true)) {
            return 'terminated';
        }

        $normalized = normalizeCustomerStatus($status);
        return $normalized === 'aktif' ? 'active' : $normalized;
    }
}

/** Kolom pertama yang benar-benar ada di tabel (daftar kandidat tetap, aman untuk SQL). */
function firstExistingColumn(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (columnExists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function formatSpeed(?float $speed): string
{
    if ($speed === null) {
        return 'Belum tersedia';
    }
    $decimals = abs($speed - round($speed)) < 0.001 ? 0 : 1;
    return number_format($speed, $decimals, ',', '.') . ' Mbps';
}

function isTruthyFlag($value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'ya', 'yes', 'true', 'aktif', 'rekomendasi', 'recommended'], true);
}

/** Pecah teks fasilitas menjadi daftar butir (baris baru, ; | atau koma). */
function parseBenefits($text): array
{
    $text = trim((string) $text);
    if ($text === '') {
        return [];
    }
    $parts = preg_split('/\r\n|\r|\n|;|\|/', $text) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    if (count($parts) === 1 && str_contains($parts[0], ',')) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $parts[0])), static fn ($p) => $p !== ''));
    }
    $parts = array_map(static fn ($p) => mb_substr($p, 0, 120), $parts);
    return array_slice($parts, 0, 6);
}

/* ======================== SESSION ======================== */

$userId = (int) ($_SESSION['user_id'] ?? 0);

/* ======================== DEFAULT DATA ======================== */

$packagesError = false;
$packages = [];
$paketNama = null;
$paketSpeed = null;
$paketHarga = null;
$currentPaketId = 0;

/* ======================== CUSTOMER ======================== */

if (!tableExists($conn, 'customers')
    || !columnExists($conn, 'customers', 'user_id')
    || !columnExists($conn, 'customers', 'id')) {
    error_log('Packages YESNET: required customer table/columns are unavailable.');
    http_response_code(503);
    die('Halaman sementara tidak tersedia. Silakan coba kembali nanti.');
}

$paketTableReady = allColumnsExist($conn, 'paket_wifi', ['id', 'nama_paket', 'speed_mbps', 'harga']);
$customerHasPaketId = columnExists($conn, 'customers', 'paket_id');
$customerStatusAvailable = columnExists($conn, 'customers', 'status_langganan');

$joinPaket = ($paketTableReady && $customerHasPaketId) ? 'LEFT JOIN paket_wifi p ON p.id = c.paket_id' : '';
$customerSelect = ['c.id'];
foreach (['paket_id', 'status_langganan', 'nama', 'name'] as $column) {
    if (columnExists($conn, 'customers', $column)) {
        $customerSelect[] = "c.`{$column}`";
    }
}
if ($joinPaket !== '') {
    $customerSelect[] = 'p.`nama_paket` AS `nama_paket`';
    $customerSelect[] = 'p.`speed_mbps` AS `speed_mbps`';
    $customerSelect[] = 'p.`harga` AS `harga_paket`';
}

try {
    $rows = queryRows(
        $conn,
        'SELECT ' . implode(', ', $customerSelect) . " FROM customers c {$joinPaket} WHERE c.user_id = ? LIMIT 1",
        'i',
        [$userId]
    );
} catch (Throwable $e) {
    error_log('Packages YESNET customer query error: ' . $e->getMessage()
        . ' | file: ' . $e->getFile() . ' | line: ' . $e->getLine());
    http_response_code(503);
    die('Data halaman sementara tidak tersedia. Silakan coba kembali nanti.');
}

if ($rows === []) {
    http_response_code(404);
    die('Data customer tidak ditemukan untuk akun ini.');
}

$customer = $rows[0];
$customerId = (int) ($customer['id'] ?? 0);

$nama = trim((string) pick($customer, ['nama', 'name'], $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Customer'));
if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = $customerStatusAvailable
    ? normalizeSubscriptionStatus($customer['status_langganan'] ?? '')
    : 'unknown';

$statusMap = [
    'active'             => ['Layanan Aktif', 'active', 'bi-check-circle-fill'],
    'pending'            => ['Menunggu Aktivasi', 'pending', 'bi-hourglass-split'],
    'suspended'          => ['Layanan Ditangguhkan', 'suspended', 'bi-pause-circle-fill'],
    'terminated'         => ['Layanan Berakhir', 'terminated', 'bi-x-circle-fill'],
    'belum_berlangganan' => ['Belum Berlangganan', 'pending', 'bi-cart3'],
    'unknown'            => ['Status Belum Tersedia', 'unknown', 'bi-question-circle'],
];
if (!isset($statusMap[$statusLangganan])) {
    $statusLangganan = 'unknown';
}
[$statusLabel, $statusClass, $statusIcon] = $statusMap[$statusLangganan];

$currentPaketId = (int) ($customer['paket_id'] ?? 0);
if (!empty($customer['nama_paket'])) {
    $paketNama = trim((string) $customer['nama_paket']);
}
if (is_numeric($customer['speed_mbps'] ?? null)) {
    $paketSpeed = (float) $customer['speed_mbps'];
}
if (is_numeric($customer['harga_paket'] ?? null)) {
    $paketHarga = (float) $customer['harga_paket'];
}
$hasCurrent = $currentPaketId > 0 && $paketNama !== null && $paketNama !== '';

/* ======================== DAFTAR PAKET ======================== */
/* Kolom wajib: id, nama_paket, speed_mbps, harga. Kolom lain dipakai hanya jika memang ada. */

if (!$paketTableReady) {
    error_log('Packages YESNET: paket_wifi table or required columns are unavailable.');
    $packagesError = true;
} else {
    $descCol    = firstExistingColumn($conn, 'paket_wifi', ['deskripsi', 'deskripsi_paket', 'keterangan']);
    $benefitCol = firstExistingColumn($conn, 'paket_wifi', ['fasilitas', 'benefit', 'fitur', 'keunggulan']);
    $recCol     = firstExistingColumn($conn, 'paket_wifi', ['is_recommended', 'rekomendasi', 'recommended', 'is_populer', 'populer']);
    $orderCol   = firstExistingColumn($conn, 'paket_wifi', ['urutan', 'sort_order', 'order_no', 'no_urut']);
    $statusCol  = firstExistingColumn($conn, 'paket_wifi', ['status', 'is_active', 'aktif', 'status_paket', 'is_available']);

    $select = ['`id`', '`nama_paket`', '`speed_mbps`', '`harga`'];
    $select[] = $descCol    ? "`{$descCol}` AS `deskripsi`"      : 'NULL AS `deskripsi`';
    $select[] = $benefitCol ? "`{$benefitCol}` AS `fasilitas`"   : 'NULL AS `fasilitas`';
    $select[] = $recCol     ? "`{$recCol}` AS `rekomendasi`"     : 'NULL AS `rekomendasi`';

    $where = '';
    if ($statusCol !== null) {
        $where = " WHERE LOWER(TRIM(CAST(`{$statusCol}` AS CHAR))) IN
                   ('1','aktif','active','tersedia','available','ya','yes','true')";
    }

    $orderBy = [];
    if ($orderCol !== null) {
        $orderBy[] = "`{$orderCol}` ASC";
    }
    $orderBy[] = '`speed_mbps` ASC';
    $orderBy[] = '`harga` ASC';
    $orderBy[] = '`id` ASC';

    try {
        $paketRows = queryRows(
            $conn,
            'SELECT ' . implode(', ', $select) . ' FROM paket_wifi' . $where . ' ORDER BY ' . implode(', ', $orderBy)
        );
    } catch (Throwable $e) {
        error_log('Packages YESNET paket query error: ' . $e->getMessage()
            . ' | file: ' . $e->getFile() . ' | line: ' . $e->getLine());
        $paketRows = [];
        $packagesError = true;
    }

    foreach ($paketRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['nama_paket'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }

        $speed = is_numeric($row['speed_mbps'] ?? null) ? (float) $row['speed_mbps'] : null;
        $price = is_numeric($row['harga'] ?? null) ? (float) $row['harga'] : null;

        /* Klasifikasi terhadap paket aktif pelanggan. */
        $group = 'other';
        if ($hasCurrent && $id === $currentPaketId) {
            $group = 'current';
        } elseif ($statusLangganan === 'active' && $hasCurrent && $speed !== null && $paketSpeed !== null) {
            $higher = $speed > $paketSpeed
                || (abs($speed - $paketSpeed) < 0.001 && $price !== null && $paketHarga !== null && $price > $paketHarga);
            if ($higher) {
                $group = 'upgrade';
            }
        }

        /* Tombol aksi: mengikuti mekanisme yang sudah ada, tidak ada perubahan paket di halaman ini. */
        if ($group === 'current') {
            $action = ['type' => 'current', 'href' => '', 'icon' => 'bi-check-circle-fill',
                'label' => $statusLangganan === 'active' ? 'Paket kamu saat ini' : 'Paket terdaftar'];
        } elseif ($statusLangganan === 'active') {
            if ($group === 'upgrade') {
                $action = ['type' => 'primary', 'href' => 'upgrade.php?paket=' . $id,
                    'icon' => 'bi-arrow-up-circle-fill', 'label' => 'Upgrade ke paket ini'];
            } elseif ($hasCurrent) {
                $action = ['type' => 'disabled', 'href' => '', 'icon' => 'bi-dash-circle',
                    'label' => 'Tidak tersedia untuk upgrade'];
            } else {
                $action = ['type' => 'outline', 'href' => 'chat.php', 'icon' => 'bi-headset', 'label' => 'Hubungi CS'];
            }
        } elseif ($statusLangganan === 'belum_berlangganan') {
            $action = ['type' => 'primary', 'href' => '../paket.php?mode=direct&paket=' . $id,
                'icon' => 'bi-box-seam-fill', 'label' => 'Pilih paket ini'];
        } elseif ($statusLangganan === 'pending') {
            $action = ['type' => 'outline', 'href' => 'installation.php', 'icon' => 'bi-geo-alt', 'label' => 'Lihat pemasangan'];
        } else {
            $action = ['type' => 'outline', 'href' => 'chat.php', 'icon' => 'bi-headset', 'label' => 'Hubungi CS'];
        }

        $delta = null;
        if ($group === 'upgrade' && $speed !== null && $paketSpeed !== null && $price !== null && $paketHarga !== null) {
            $delta = ['speed' => $speed - $paketSpeed, 'price' => $price - $paketHarga];
        }

        $packages[] = [
            'id'          => $id,
            'name'        => $name,
            'speed'       => $speed,
            'price'       => $price,
            'per_mbps'    => ($speed !== null && $speed > 0 && $price !== null) ? $price / $speed : null,
            'description' => trim((string) ($row['deskripsi'] ?? '')),
            'benefits'    => parseBenefits($row['fasilitas'] ?? ''),
            'recommended' => isTruthyFlag($row['rekomendasi'] ?? ''),
            'group'       => $group,
            'action'      => $action,
            'delta'       => $delta,
        ];
    }
}

$totalPackages = count($packages);
$upgradeCount = count(array_filter($packages, static fn ($p) => $p['group'] === 'upgrade'));
$currentInList = count(array_filter($packages, static fn ($p) => $p['group'] === 'current')) > 0;

/* ======================== NOTIFIKASI (hanya jumlah belum dibaca) ======================== */

$unreadNotifications = 0;
if (allColumnsExist($conn, 'notifications', ['id_user', 'dibaca'])) {
    try {
        $rows = queryRows(
            $conn,
            'SELECT COUNT(*) AS total FROM notifications WHERE (id_user = ? OR id_user IS NULL) AND dibaca = 0',
            'i',
            [$userId]
        );
        $unreadNotifications = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('Packages YESNET notification failed: ' . $e->getMessage());
    }
}
try {
    $unreadNotifications += yesnetNotificationUnreadCount($conn, $userId);
} catch (Throwable $e) {
    error_log('Packages YESNET service notification failed: ' . $e->getMessage());
}

/* ======================== TAMPILAN ======================== */

$avatar = strtoupper(substr(trim($nama) ?: 'C', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);

$totalGangguan = 0;
if ($customerId > 0 && allColumnsExist($conn, 'complaint', ['id_customer'])) {
    try {
        $sql = 'SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?';
        if (columnExists($conn, 'complaint', 'status')) {
            $sql .= " AND LOWER(status) NOT IN ('closed', 'resolved')";
        }
        $rows = queryRows($conn, $sql, 'i', [$customerId]);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('Packages YESNET complaint failed: ' . $e->getMessage());
    }
}

$menuMain = [
    ['dashboard.php',      'bi-grid-fill',            'Dashboard'],
    ['usage.php',          'bi-router-fill',          'Internet Saya'],
    ['packages.php',       'bi-box-seam-fill',        'Paket Internet'],
    ['billing.php',        'bi-receipt-cutoff',       'Tagihan & Pembayaran'],
    ['speedtest.php',      'bi-speedometer2',         'Speed Test'],
    ['complaint.php',      'bi-tools',                'Laporan Gangguan'],
    ['network_status.php', 'bi-globe2',               'Status Jaringan'],
    ['upgrade.php',        'bi-arrow-up-circle-fill', 'Upgrade Paket'],
    ['chat.php',           'bi-chat-dots-fill',       'Bantuan / Live Chat'],
];

$menuService = match ($statusLangganan) {
    'belum_berlangganan' => [['../paket.php?mode=direct', 'bi-box-seam-fill', 'Pilih Paket']],
    'pending'            => [['installation.php', 'bi-hammer', 'Status Pemasangan']],
    'active'             => [['service_request.php', 'bi-plus-circle-fill', 'Layanan Tambahan']],
    default              => [],
};

$heroText = match ($statusLangganan) {
    'active'             => 'Lihat paket yang kamu gunakan dan bandingkan dengan pilihan paket YESNET lainnya.',
    'pending'            => 'Pengajuan pemasangan kamu sedang diproses. Kamu tetap bisa melihat daftar paket YESNET.',
    'suspended'          => 'Layanan kamu sedang ditangguhkan. Hubungi Customer Service untuk mengaktifkan kembali.',
    'terminated'         => 'Layanan kamu sudah berakhir. Hubungi Customer Service untuk informasi lebih lanjut.',
    'belum_berlangganan' => 'Kamu belum berlangganan. Pilih paket internet YESNET yang sesuai kebutuhanmu.',
    default              => 'Status langganan belum tersedia. Hubungi Customer Service untuk memastikan kondisi layanan kamu.',
};

[$heroLink, $heroIcon, $heroButton] = match ($statusLangganan) {
    'active'             => $upgradeCount > 0 ? ['upgrade.php', 'bi-arrow-up-circle', 'Lihat Upgrade Paket'] : ['billing.php', 'bi-receipt', 'Lihat Tagihan'],
    'pending'            => ['installation.php', 'bi-geo-alt', 'Lihat Pemasangan'],
    'belum_berlangganan' => ['../paket.php?mode=direct', 'bi-box-seam', 'Pilih Paket Internet'],
    default              => ['chat.php', 'bi-headset', 'Hubungi CS'],
};

$csrfToken = e($_SESSION['csrf_notification'] ??= bin2hex(random_bytes(32)));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">
    <title>Paket Internet - YESNET</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <link rel="stylesheet" href="assets/css/packages.css">
    <meta name="notification-csrf" content="<?= $csrfToken; ?>">
    <script defer src="assets/js/portal-ui.js"></script>
    <script defer src="assets/js/packages.js"></script>
</head>

<body class="dashboard-page packages-page">
<div class="customer-layout">

    <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar" id="sidebar" aria-label="Navigasi pelanggan">
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
                       class="menu-item <?= $currentPage === $href ? 'active' : ''; ?>"
                       <?= $currentPage === $href ? 'aria-current="page"' : ''; ?>>
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
                <button type="button" class="mobile-menu-button" id="mobileMenuButton"
                        aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false">
                    <i class="bi bi-list"></i>
                </button>
                <div>
                    <div class="breadcrumb-text">Customer Portal <span>/</span> Paket Internet</div>
                    <h1>Paket Internet</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" class="theme-toggle" id="themeToggle"
                        aria-label="Aktifkan tema gelap" aria-pressed="false" title="Ganti tema">
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <a href="notifikasi.php" class="notification-button" aria-label="Buka notifikasi" title="Notifikasi">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                    <?php endif; ?>
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
            <section class="welcome-hero packages-hero">
                <div class="hero-decoration hero-decoration-one"></div>
                <div class="hero-decoration hero-decoration-two"></div>

                <div class="hero-content">
                    <div class="hero-label"><span class="hero-label-dot"></span> Katalog Paket</div>
                    <h2>Paket internet YESNET</h2>
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
                    <div class="wifi-core"><i class="bi bi-box-seam"></i></div>
                    <span class="signal-dot dot-one"></span>
                    <span class="signal-dot dot-two"></span>
                    <span class="signal-dot dot-three"></span>
                </div>
            </section>

            <!-- ---------- PAKET SAYA ---------- -->
            <div class="section-title-row">
                <div>
                    <span class="section-kicker">Langganan</span>
                    <h2>Paket saya</h2>
                </div>
            </div>

            <div class="plan-overview">
                <section class="dashboard-card current-plan-card" aria-label="Paket yang sedang digunakan">
                    <?php if ($hasCurrent): ?>
                        <div class="current-plan-head">
                            <div class="current-plan-icon"><i class="bi bi-router-fill"></i></div>
                            <div>
                                <span class="card-kicker">Paket saat ini</span>
                                <h3><?= e($paketNama); ?></h3>
                            </div>
                            <span class="active-pill status-<?= e($statusClass); ?>">
                                <i class="bi <?= e($statusIcon); ?>"></i> <?= e($statusLabel); ?>
                            </span>
                        </div>

                        <div class="current-plan-facts">
                            <div>
                                <span>Kecepatan</span>
                                <strong><?= e(formatSpeed($paketSpeed)); ?></strong>
                            </div>
                            <div>
                                <span>Harga berlangganan</span>
                                <strong><?= $paketHarga !== null ? e(rupiah($paketHarga)) . ' <small>/ bulan</small>' : 'Belum tersedia'; ?></strong>
                            </div>
                            <div>
                                <span>Status langganan</span>
                                <strong><?= e($statusLabel); ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="current-plan-empty">
                            <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
                            <strong>
                                <?= $statusLangganan === 'belum_berlangganan' ? 'Kamu belum berlangganan' : 'Paket aktif belum tersedia'; ?>
                            </strong>
                            <span>
                                <?= $statusLangganan === 'belum_berlangganan'
                                    ? 'Pilih salah satu paket di bawah untuk memulai langganan.'
                                    : 'Kami belum menemukan paket yang terhubung dengan akun kamu. Hubungi Customer Service untuk memastikan.'; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="dashboard-card plan-summary-card" aria-label="Ringkasan paket">
                    <div class="plan-summary-item">
                        <div class="stat-icon blue"><i class="bi bi-grid-fill"></i></div>
                        <div><strong><?= $totalPackages; ?></strong><span>Paket tersedia</span></div>
                    </div>
                    <div class="plan-summary-item">
                        <div class="stat-icon green"><i class="bi bi-arrow-up-circle-fill"></i></div>
                        <div>
                            <strong><?= $statusLangganan === 'active' && $hasCurrent ? $upgradeCount : '—'; ?></strong>
                            <span>Pilihan upgrade</span>
                        </div>
                    </div>
                    <a href="<?= $statusLangganan === 'active' ? 'upgrade.php' : 'chat.php'; ?>" class="full-outline-button">
                        <i class="bi <?= $statusLangganan === 'active' ? 'bi-arrow-up-circle' : 'bi-headset'; ?>"></i>
                        <?= $statusLangganan === 'active' ? 'Buka halaman upgrade' : 'Hubungi Customer Service'; ?>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </section>
            </div>

            <!-- ---------- DAFTAR PAKET ---------- -->
            <div class="section-title-row">
                <div>
                    <span class="section-kicker">Pilihan paket</span>
                    <h2>Semua paket YESNET</h2>
                </div>
                <?php if ($totalPackages > 0): ?>
                    <div class="plan-filter" role="group" aria-label="Filter paket">
                        <button type="button" class="plan-chip is-active" data-filter="all" aria-pressed="true">
                            Semua <span><?= $totalPackages; ?></span>
                        </button>
                        <?php if ($currentInList): ?>
                            <button type="button" class="plan-chip" data-filter="current" aria-pressed="false">
                                Paket saya <span>1</span>
                            </button>
                        <?php endif; ?>
                        <?php if ($upgradeCount > 0): ?>
                            <button type="button" class="plan-chip" data-filter="upgrade" aria-pressed="false">
                                Bisa upgrade <span><?= $upgradeCount; ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($packagesError): ?>
                <section class="state-card plan-state unknown-state">
                    <div class="state-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <span class="state-label">Daftar paket</span>
                    <h2>Daftar paket belum dapat ditampilkan</h2>
                    <p>Terjadi kendala saat memuat data paket. Silakan coba lagi nanti atau hubungi Customer Service.</p>
                    <a href="chat.php" class="state-button"><i class="bi bi-headset"></i> Hubungi Customer Service</a>
                </section>
            <?php elseif ($packages === []): ?>
                <section class="state-card plan-state unknown-state">
                    <div class="state-icon"><i class="bi bi-box-seam"></i></div>
                    <span class="state-label">Daftar paket</span>
                    <h2>Belum ada paket yang tersedia</h2>
                    <p>Saat ini belum ada paket internet yang dapat dipilih. Silakan cek kembali nanti.</p>
                </section>
            <?php else: ?>
                <div class="plan-grid" id="planGrid">
                    <?php foreach ($packages as $p): ?>
                        <?php
                        $classes = 'plan-card';
                        if ($p['group'] === 'current') { $classes .= ' is-current'; }
                        if ($p['recommended']) { $classes .= ' is-recommended'; }
                        $action = $p['action'];
                        ?>
                        <article class="<?= e($classes); ?>" data-group="<?= e($p['group']); ?>">
                            <div class="plan-badges">
                                <?php if ($p['group'] === 'current'): ?>
                                    <span class="plan-badge badge-current">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <?= $statusLangganan === 'active' ? 'Paket aktif' : 'Terdaftar'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($p['recommended']): ?>
                                    <span class="plan-badge badge-recommended"><i class="bi bi-stars"></i> Direkomendasikan</span>
                                <?php endif; ?>
                                <?php if ($p['group'] === 'upgrade'): ?>
                                    <span class="plan-badge badge-upgrade"><i class="bi bi-arrow-up-circle-fill"></i> Upgrade</span>
                                <?php endif; ?>
                            </div>

                            <h3 class="plan-name"><?= e($p['name']); ?></h3>

                            <div class="plan-speed">
                                <?php if ($p['speed'] !== null): ?>
                                    <strong><?= e(rtrim(rtrim(number_format($p['speed'], 1, ',', '.'), '0'), ',')); ?></strong>
                                    <span>Mbps</span>
                                <?php else: ?>
                                    <span>Kecepatan belum tersedia</span>
                                <?php endif; ?>
                            </div>

                            <div class="plan-price">
                                <?php if ($p['price'] !== null): ?>
                                    <strong><?= e(rupiah($p['price'])); ?></strong>
                                    <span>/ bulan</span>
                                <?php else: ?>
                                    <span>Harga belum tersedia</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($p['per_mbps'] !== null): ?>
                                <div class="plan-permbps">≈ <?= e(rupiah(round($p['per_mbps']))); ?> per Mbps</div>
                            <?php endif; ?>

                            <?php if ($p['description'] !== ''): ?>
                                <p class="plan-desc"><?= e($p['description']); ?></p>
                            <?php endif; ?>

                            <?php if ($p['benefits'] !== []): ?>
                                <ul class="plan-benefits">
                                    <?php foreach ($p['benefits'] as $benefit): ?>
                                        <li><i class="bi bi-check2-circle"></i><span><?= e($benefit); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($p['delta'] !== null): ?>
                                <div class="plan-delta">
                                    <i class="bi bi-graph-up-arrow"></i>
                                    <span>
                                        <?= e(formatSpeed(max(0, $p['delta']['speed']))); ?> lebih cepat
                                        <?php if ($p['delta']['price'] > 0): ?>
                                            · +<?= e(rupiah($p['delta']['price'])); ?>/bln
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="plan-action">
                                <?php if ($action['type'] === 'current'): ?>
                                    <span class="plan-button is-current-state">
                                        <i class="bi <?= e($action['icon']); ?>"></i> <?= e($action['label']); ?>
                                    </span>
                                <?php elseif ($action['type'] === 'disabled'): ?>
                                    <span class="plan-button is-disabled" aria-disabled="true">
                                        <i class="bi <?= e($action['icon']); ?>"></i> <?= e($action['label']); ?>
                                    </span>
                                <?php else: ?>
                                    <a href="<?= e($action['href']); ?>"
                                       class="plan-button <?= $action['type'] === 'primary' ? 'is-primary' : 'is-outline'; ?>">
                                        <i class="bi <?= e($action['icon']); ?>"></i> <?= e($action['label']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="plan-filter-empty" id="planFilterEmpty" hidden role="status">
                    Tidak ada paket untuk filter ini.
                </div>

                <p class="plan-note">
                    <i class="bi bi-info-circle"></i>
                    Perubahan paket hanya dilakukan melalui halaman <a href="upgrade.php">Upgrade Paket</a>.
                    Halaman ini tidak mengubah langganan kamu secara otomatis.
                </p>
            <?php endif; ?>

            <footer class="dashboard-footer">
                <span>© <?= date('Y'); ?> YESNET</span>
                <span>Customer Portal</span>
            </footer>
        </div>
    </main>
</div>
</body>
</html>