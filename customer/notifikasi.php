<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/service_notifications.php';
requireRole('customer');
date_default_timezone_set('Asia/Jakarta');

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

$customerName = 'Customer';
$customerId = 0;
$isActiveService = false;
$notificationCsrf = $_SESSION['csrf_notification'] ??= bin2hex(random_bytes(32));

$customerStmt = $conn->prepare("SELECT * FROM customers WHERE user_id = ? LIMIT 1");
if ($customerStmt) {
    $customerStmt->bind_param('i', $userId);
    $customerStmt->execute();
    $customerResult = $customerStmt->get_result();
    if ($customerResult && $customerResult->num_rows > 0) {
        $customerData = $customerResult->fetch_assoc();
        $customerId = (int) ($customerData['id'] ?? 0);
        if (!empty($customerData['nama'])) {
            $customerName = $customerData['nama'];
        } elseif (!empty($customerData['name'])) {
            $customerName = $customerData['name'];
        }
        $isActiveService = in_array(
            strtolower(trim((string) ($customerData['status_langganan'] ?? ''))),
            ['aktif', 'active'],
            true
        );
    }
    $customerStmt->close();
}

/* ======================== TANDAI SEMUA DIBACA ======================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    $postToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postToken === '' || !hash_equals($notificationCsrf, $postToken)) {
        http_response_code(403);
        exit('Sesi notifikasi tidak valid. Muat ulang halaman lalu coba kembali.');
    }
    try {
        yesnetNotificationMarkAllRead($conn, $userId);
        yesnetLegacyNotificationMarkAllRead($conn, $userId);
    } catch (Throwable $e) {
        error_log('Customer mark-all-read failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Status notifikasi belum dapat diperbarui. Coba kembali beberapa saat lagi.');
    }
    header('Location: notifikasi.php');
    exit;
}

/* ======================== AMBIL NOTIFIKASI ======================== */

$notifications = [];
$serviceNotifications = [];
$detailNotification = null;
$serviceNotificationsAvailable = false;
try {
    $notifications = yesnetLegacyNotificationList($conn, $userId);
    $serviceNotificationsAvailable = yesnetNotificationTablesAvailable($conn);
    if ($serviceNotificationsAvailable && $customerId > 0) {
        yesnetNotificationSyncPayments($conn, $userId, $customerId);
        yesnetNotificationSyncIncidents($conn, $userId, $customerId);
    }
    $serviceNotifications = yesnetNotificationList($conn, $userId, 100);
    if (isset($_GET['id']) && preg_match('/^(service|legacy):(\d+)$/', (string) $_GET['id'], $matches)) {
        $detailSource = $matches[1];
        $detailId = (int) $matches[2];
        foreach ($serviceNotifications as &$serviceNotification) {
            if ($detailSource === 'service' && (int) $serviceNotification['id'] === $detailId) {
                yesnetNotificationMarkRead($conn, $userId, $detailId);
                $serviceNotification['dibaca'] = 1;
                $detailNotification = $serviceNotification;
                break;
            }
        }
        unset($serviceNotification);
        if ($detailSource === 'legacy') {
            foreach ($notifications as &$legacyNotification) {
                if ((int) ($legacyNotification['id'] ?? 0) === $detailId) {
                    if ((int) ($legacyNotification['id_user'] ?? 0) === $userId) {
                        yesnetLegacyNotificationMarkRead($conn, $userId, $detailId);
                        $legacyNotification['dibaca'] = 1;
                    }
                    $detailNotification = $legacyNotification;
                    break;
                }
            }
            unset($legacyNotification);
        }
    }
    $notifications = array_merge($notifications, $serviceNotifications);
    usort($notifications, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
} catch (Throwable $e) {
    error_log('Customer notification history failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Riwayat notifikasi belum dapat dimuat. Coba kembali beberapa saat lagi.');
}

$unreadCount = 0;
foreach ($notifications as $notification) {
    if ((int) ($notification['dibaca'] ?? 0) === 0) {
        $unreadCount++;
    }
}

$typeDataList = [
    'promo'        => ['icon' => 'bi-gift-fill',                     'name' => 'Promo'],
    'gangguan'     => ['icon' => 'bi-exclamation-triangle-fill',     'name' => 'Gangguan'],
    'tidak_stabil' => ['icon' => 'bi-wifi-off',                      'name' => 'Koneksi tidak stabil'],
    'pemeliharaan' => ['icon' => 'bi-wrench-adjustable-circle-fill', 'name' => 'Pemeliharaan jaringan'],
    'selesai'      => ['icon' => 'bi-check-circle-fill',             'name' => 'Gangguan selesai'],
    'pembayaran'   => ['icon' => 'bi-credit-card-fill',              'name' => 'Pembayaran'],
    'info'         => ['icon' => 'bi-info-circle-fill',              'name' => 'Informasi'],
];

function formatNotificationDate($date): string
{
    if (empty($date)) {
        return '-';
    }
    $timestamp = strtotime((string) $date);
    return $timestamp ? date('d M Y, H:i', $timestamp) . ' WIB' : e($date);
}

/* ======================== MENU (sama dengan dashboard) ======================== */

$avatar = strtoupper(substr(trim($customerName) ?: 'C', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);

$menuMain = [
    ['dashboard.php',                  'bi-grid-fill',            'Dashboard'],
    ['usage.php',                      'bi-router-fill',          'Internet Saya'],
    ['billing.php',                    'bi-receipt-cutoff',       'Tagihan & Pembayaran'],
    ['billing.php#riwayat-pembayaran', 'bi-clock-history',        'Riwayat Pembayaran'],
    ['speedtest.php',                  'bi-speedometer2',         'Speed Test'],
    ['complaint.php',                  'bi-tools',                'Laporan Gangguan'],
    ['network_status.php',             'bi-globe2',               'Status Jaringan'],
    ['upgrade.php',                    'bi-arrow-up-circle-fill', 'Upgrade Paket'],
    ['chat.php',                       'bi-chat-dots-fill',       'Bantuan / Live Chat'],
];
$menuService = $isActiveService
    ? [['service_request.php', 'bi-plus-circle-fill', 'Layanan Tambahan']]
    : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">
    <title>Notifikasi - YESNET</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <link rel="stylesheet" href="assets/css/notifikasi.css">
    <script defer src="assets/js/portal-ui.js"></script>
</head>

<body class="dashboard-page">
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
                    <a href="<?= e($href); ?>" class="menu-item">
                        <span class="menu-icon"><i class="bi <?= e($icon); ?>"></i></span>
                        <span class="menu-text"><?= e($label); ?></span>
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
                <a href="notifikasi.php" class="menu-item active" aria-current="page">
                    <span class="menu-icon"><i class="bi bi-bell-fill"></i></span>
                    <span class="menu-text">Notifikasi</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="menu-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
                    <?php endif; ?>
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
                <strong><?= e($customerName); ?></strong>
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
                    <div class="breadcrumb-text">Customer Portal <span>/</span> Notifikasi</div>
                    <h1>Notifikasi</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" class="theme-toggle" id="themeToggle"
                        aria-label="Aktifkan tema gelap" aria-pressed="false" title="Ganti tema">
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <div class="topbar-notifications">
                    <a href="notifikasi.php" class="notification-button" aria-label="Notifikasi" title="Notifikasi">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-count"><?= $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <a href="profile.php" class="top-profile" aria-label="Buka pengaturan akun">
                    <div class="top-profile-avatar"><?= e($avatar); ?></div>
                    <div class="top-profile-info">
                        <strong><?= e($customerName); ?></strong>
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
                    <div class="hero-label"><span class="hero-label-dot"></span> Pusat informasi</div>
                    <h2>Notifikasi kamu</h2>
                    <p>Informasi terbaru mengenai layanan, pembayaran, gangguan, dan promo.</p>
                    <?php if ($unreadCount > 0): ?>
                        <div class="hero-actions">
                            <form method="post" class="nt-readall">
                                <input type="hidden" name="csrf_token" value="<?= e($notificationCsrf); ?>">
                                <input type="hidden" name="action" value="read_all">
                                <button type="submit" class="hero-button">
                                    <i class="bi bi-check2-all"></i> Tandai semua sudah dibaca
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="hero-visual" aria-hidden="true">
                    <div class="wifi-orbit orbit-one"></div>
                    <div class="wifi-orbit orbit-two"></div>
                    <div class="wifi-core"><i class="bi bi-bell-fill"></i></div>
                    <span class="signal-dot dot-one"></span>
                    <span class="signal-dot dot-two"></span>
                    <span class="signal-dot dot-three"></span>
                </div>
            </section>

            <!-- ---------- STATUS STRIP ---------- -->
            <section class="status-strip">
                <div class="status-strip-item">
                    <div class="status-strip-icon blue"><i class="bi bi-bell-fill"></i></div>
                    <div><span>Total notifikasi</span><strong><?= count($notifications); ?></strong></div>
                </div>
                <div class="status-divider"></div>
                <div class="status-strip-item">
                    <div class="status-strip-icon <?= $unreadCount > 0 ? 'pending' : 'green'; ?>">
                        <i class="bi <?= $unreadCount > 0 ? 'bi-envelope-fill' : 'bi-envelope-open-fill'; ?>"></i>
                    </div>
                    <div><span>Belum dibaca</span><strong><?= $unreadCount; ?></strong></div>
                </div>
            </section>

            <?php if (!$serviceNotificationsAvailable): ?>
                <div class="nt-alert" role="status">
                    Notifikasi layanan dan pembayaran belum tersedia. Hubungi administrator untuk memeriksa migration notifikasi.
                </div>
            <?php endif; ?>

            <?php if ($detailNotification !== null): ?>
                <?php
                $detailType = strtolower((string) ($detailNotification['type'] ?? 'info'));
                if (!isset($typeDataList[$detailType])) {
                    $detailType = 'info';
                }
                ?>
                <article class="dashboard-card nt-detail">
                    <a class="nt-back" href="notifikasi.php"><i class="bi bi-arrow-left"></i> Kembali ke riwayat</a>
                    <div class="nt-detail-head">
                        <div class="nt-icon type-<?= e($detailType); ?>"><i class="bi <?= e($typeDataList[$detailType]['icon']); ?>"></i></div>
                        <div>
                            <span><?= e($typeDataList[$detailType]['name']); ?></span>
                            <h2><?= e($detailNotification['judul'] ?? 'Notifikasi'); ?></h2>
                        </div>
                    </div>
                    <p><?= nl2br(e($detailNotification['message'] ?? '')); ?></p>
                    <?php if (!empty($detailNotification['action_url']) && $detailType === 'pembayaran'): ?>
                        <div class="nt-actions">
                            <a class="nt-pay" href="<?= e($detailNotification['action_url']); ?>"><i class="bi bi-credit-card-fill"></i> Bayar Sekarang</a>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <!-- ---------- RIWAYAT ---------- -->
            <div class="section-title-row">
                <div>
                    <span class="section-kicker">Riwayat</span>
                    <h2>Semua notifikasi</h2>
                </div>
            </div>

            <?php if ($notifications !== []): ?>
                <div class="nt-toolbar">
                    <div class="nt-tabs" role="tablist" aria-label="Filter notifikasi">
                        <button type="button" class="nt-tab active" data-filter="all" role="tab" aria-selected="true">Semua</button>
                        <button type="button" class="nt-tab" data-filter="unread" role="tab" aria-selected="false">Belum dibaca</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="nt-list" id="notificationList">
                <?php if ($notifications === []): ?>
                    <div class="nt-empty">
                        <div class="empty-icon"><i class="bi bi-bell-slash"></i></div>
                        <strong>Belum ada notifikasi</strong>
                        <span>Saat ini belum ada informasi baru untuk akun kamu.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $type = strtolower(trim((string) ($notification['type'] ?? 'info')));
                        if (!isset($typeDataList[$type])) {
                            $type = 'info';
                        }
                        $typeData = $typeDataList[$type];
                        $isUnread = (int) ($notification['dibaca'] ?? 0) === 0;
                        $isService = ($notification['source'] ?? '') === 'service';
                        $detailHref = 'notifikasi.php?id=' . ($isService ? 'service' : 'legacy') . ':' . (int) ($notification['id'] ?? 0);
                        ?>
                        <article class="nt-item <?= $isUnread ? 'is-unread' : ''; ?>" data-unread="<?= $isUnread ? '1' : '0'; ?>">
                            <div class="nt-icon type-<?= e($type); ?>"><i class="bi <?= e($typeData['icon']); ?>"></i></div>
                            <div class="nt-body">
                                <div class="nt-meta">
                                    <div class="nt-meta-left">
                                        <span class="nt-badge"><?= e($typeData['name']); ?></span>
                                        <?php if ($isUnread): ?><span class="nt-new">Baru</span><?php endif; ?>
                                    </div>
                                    <time><i class="bi bi-clock"></i><?= formatNotificationDate($notification['created_at'] ?? ''); ?></time>
                                </div>
                                <h3><?= e($notification['judul'] ?? 'Notifikasi'); ?></h3>
                                <p><?= nl2br(e($notification['message'] ?? '')); ?></p>
                                <div class="nt-actions">
                                    <a class="nt-link" href="<?= e($detailHref); ?>">Lihat detail <i class="bi bi-arrow-up-right"></i></a>
                                    <?php if ($isService && !empty($notification['action_url']) && $type === 'pembayaran'): ?>
                                        <a class="nt-pay" href="<?= e($notification['action_url']); ?>"><i class="bi bi-credit-card-fill"></i> Bayar Sekarang</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <div class="nt-empty" id="notificationFilterEmpty" hidden>
                        <div class="empty-icon"><i class="bi bi-check2-all"></i></div>
                        <strong>Semua sudah dibaca</strong>
                        <span>Tidak ada notifikasi yang belum dibaca.</span>
                    </div>
                <?php endif; ?>
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

    /* ---------------- SIDEBAR MOBILE ---------------- */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');

    function isMobile() {
        return window.matchMedia
            ? window.matchMedia('(max-width: 900px)').matches
            : window.innerWidth <= 900;
    }

    function toggleSidebar(open) {
        if (!sidebar) { return; }
        sidebar.classList.toggle('show', open);
        if (overlay) {
            overlay.classList.toggle('show', open);
            overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        }
        document.body.classList.toggle('sidebar-open', open);
        if (mobileButton) { mobileButton.setAttribute('aria-expanded', open ? 'true' : 'false'); }

        if (isMobile()) {
            sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (open) { sidebar.removeAttribute('inert'); } else { sidebar.setAttribute('inert', ''); }
        } else {
            sidebar.removeAttribute('aria-hidden');
            sidebar.removeAttribute('inert');
        }
        if (!open && document.activeElement === closeButton && mobileButton) { mobileButton.focus(); }
    }

    if (mobileButton) { mobileButton.addEventListener('click', function () { toggleSidebar(true); }); }
    if (closeButton) { closeButton.addEventListener('click', function () { toggleSidebar(false); }); }
    if (overlay) { overlay.addEventListener('click', function () { toggleSidebar(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { toggleSidebar(false); }
    });
    window.addEventListener('resize', function () { if (!isMobile()) { toggleSidebar(false); } });
    toggleSidebar(false);

    /* ---------------- TEMA (disimpan sama dengan dashboard) ---------------- */

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';
        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute('aria-label', isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap');
        }
        if (themeToggleIcon) { themeToggleIcon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars'; }
    }

    try {
        applyTheme(localStorage.getItem('yesnet-customer-theme') || 'light');
    } catch (error) {
        applyTheme('light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const next = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('yesnet-customer-theme', next); } catch (error) {}
        });
    }

    /* ---------------- FILTER SEMUA / BELUM DIBACA ---------------- */

    const tabs = document.querySelectorAll('.nt-tab');
    const items = document.querySelectorAll('.nt-item');
    const filterEmpty = document.getElementById('notificationFilterEmpty');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const onlyUnread = tab.dataset.filter === 'unread';
            let visible = 0;

            tabs.forEach(function (t) {
                const active = t === tab;
                t.classList.toggle('active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            items.forEach(function (item) {
                const show = !onlyUnread || item.dataset.unread === '1';
                item.hidden = !show;
                if (show) { visible++; }
            });
            if (filterEmpty) { filterEmpty.hidden = visible > 0; }
        });
    });
});
</script>

</body>
</html>