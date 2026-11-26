<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireActiveCustomer($conn);

// Cek login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ======================== HELPER ======================== */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function profile_initial(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return 'C';
    }
    return function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8')
        : strtoupper(substr($text, 0, 1));
}

function profile_date_id($date): string
{
    if (empty($date)) {
        return '-';
    }
    $time = strtotime((string) $date);
    if (!$time) {
        return '-';
    }
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    return date('d', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . date('Y', $time);
}

/* ======================== PESAN NOTIFIKASI (FLASH) ======================== */

$notice = '';
if (!empty($_SESSION['profile_notice'])) {
    $notice = (string) $_SESSION['profile_notice'];
    unset($_SESSION['profile_notice']);
}

/* ======================== DATA USER ======================== */

$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result   = $stmt->get_result();
$customer = $result->fetch_assoc();

if (!$customer) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Password tidak dibutuhkan di halaman ini
unset($customer['password']);

/* ======================== ID PELANGGAN (opsional) ======================== */

$customerId = 0;
try {
    $stmtCustomer = $conn->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    if ($stmtCustomer) {
        $stmtCustomer->bind_param("i", $user_id);
        $stmtCustomer->execute();
        $rowCustomer = $stmtCustomer->get_result()->fetch_assoc();
        $customerId  = (int) ($rowCustomer['id'] ?? 0);
        $stmtCustomer->close();
    }
} catch (Throwable $ex) {
    error_log('Profile YESNET customer id lookup failed: ' . $ex->getMessage());
}

/* ======================== DATA TAMPILAN ======================== */

$username    = trim((string) ($customer['username'] ?? ''));
$namaLengkap = trim((string) ($customer['nama'] ?? ''));
$email       = trim((string) ($customer['email'] ?? ''));
$telephone   = trim((string) ($customer['telephone'] ?? ''));
$alamat      = trim((string) ($customer['alamat'] ?? ''));

$displayName = $namaLengkap !== '' ? $namaLengkap : $username;
$initial     = profile_initial($displayName);
$joinedAt    = profile_date_id($customer['created_at'] ?? null);
$currentPage = basename($_SERVER['PHP_SELF']);

$menuMain = [
    ['dashboard.php',      'bi-grid-fill',            'Dashboard'],
    ['usage.php',          'bi-router-fill',          'Internet Saya'],
    ['billing.php',        'bi-receipt-cutoff',       'Tagihan & Pembayaran'],
    ['speedtest.php',      'bi-speedometer2',         'Speed Test'],
    ['complaint.php',      'bi-tools',                'Laporan Gangguan'],
    ['network_status.php', 'bi-globe2',               'Status Jaringan'],
    ['upgrade.php',        'bi-arrow-up-circle-fill', 'Upgrade Paket'],
    ['chat.php',           'bi-chat-dots-fill',       'Bantuan / Live Chat'],
];

// [label, ikon, warna, nilai, teks kosong, bisa disalin, lebar penuh]
$infoItems = [
    ['Nama Lengkap',  'bi-person-lines-fill', 'blue',   $namaLengkap, 'Belum diisi',        false, false],
    ['Nama Pengguna', 'bi-person-fill',       'purple', $username,    'Belum diisi',        true,  false],
    ['Email',         'bi-envelope-fill',     'green',  $email,       'Belum diisi',        true,  false],
    ['Nomor Telepon', 'bi-telephone-fill',    'orange', $telephone,   '-',                  true,  false],
    ['Alamat',        'bi-geo-alt-fill',      'blue',   $alamat,      'Alamat belum diisi', false, true],
];

$quickServices = [
    ['installation.php', 'blue',   'bi-router-fill',          'Instalasi', 'Lihat instalasi WiFi'],
    ['complaint.php',    'orange', 'bi-ticket-detailed-fill', 'Complaint', 'Lihat laporan gangguan'],
    ['chat.php',         'green',  'bi-chat-dots-fill',       'Chat CS',   'Hubungi customer service'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">
    <title>Profil Saya - YESNET</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- CSS: dashboard (layout bersama) + profile (khusus halaman ini) -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/profile.css">
    <link rel="stylesheet" href="assets/css/portal-unified.css">
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

            <div class="menu-section">
                <div class="menu-label">Akun</div>
                <a href="profile.php"
                   class="menu-item <?= $currentPage === 'profile.php' ? 'active' : ''; ?>"
                   <?= $currentPage === 'profile.php' ? 'aria-current="page"' : ''; ?>>
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
            <div class="sidebar-user-avatar"><?= e($initial); ?></div>
            <div class="sidebar-user-info">
                <strong><?= e($displayName); ?></strong>
                <span><?= $customerId > 0 ? 'ID pelanggan #' . $customerId : 'Customer'; ?></span>
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
                    <div class="breadcrumb-text">Customer Portal <span>/</span> Profil</div>
                    <h1>Profil Saya</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" class="theme-toggle" id="themeToggle"
                        aria-label="Aktifkan tema gelap" aria-pressed="false" title="Ganti tema">
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <a href="notifikasi.php" class="notification-button" aria-label="Buka notifikasi" title="Notifikasi">
                    <i class="bi bi-bell"></i>
                </a>

                <a href="profile.php" class="top-profile" aria-label="Profil saya">
                    <div class="top-profile-avatar"><?= e($initial); ?></div>
                    <div class="top-profile-info">
                        <strong><?= e($displayName); ?></strong>
                        <span><?= $customerId > 0 ? 'ID pelanggan #' . $customerId : 'Customer'; ?></span>
                    </div>
                </a>
            </div>
        </header>

        <div class="dashboard-content">

            <!-- ---------- NOTIFIKASI ---------- -->
            <?php if ($notice !== ''): ?>
                <div class="pf-alert" role="alert">
                    <i class="bi bi-info-circle-fill"></i>
                    <div><?= e($notice); ?></div>
                </div>
            <?php endif; ?>

            <!-- ---------- HERO ---------- -->
            <section class="pf-hero">
                <div class="hero-decoration hero-decoration-one"></div>
                <div class="hero-decoration hero-decoration-two"></div>

                <div class="pf-avatar-wrap">
                    <div class="pf-avatar"><?= e($initial); ?></div>
                    <span class="pf-avatar-dot" title="Akun aktif"></span>
                </div>

                <div class="pf-hero-info">
                    <div class="pf-chips">
                        <span class="pf-chip"><i class="bi bi-patch-check-fill"></i> Customer</span>
                        <span class="pf-chip"><i class="bi bi-shield-check"></i> Akun Aktif</span>
                    </div>
                    <h2><?= e($displayName); ?></h2>
                    <p>
                        <span><i class="bi bi-envelope"></i> <?= e($email !== '' ? $email : '-'); ?></span>
                        <span><i class="bi bi-calendar-check"></i> Bergabung sejak <?= e($joinedAt); ?></span>
                    </p>
                </div>

                <div class="pf-readonly-pill" title="Data profil tidak dapat diubah sendiri">
                    <i class="bi bi-lock-fill"></i>
                    <span>Mode hanya lihat</span>
                </div>
            </section>

            <!-- ---------- GRID ---------- -->
            <div class="pf-grid">

                <!-- Informasi pribadi -->
                <section class="dashboard-card pf-card">
                    <div class="card-heading compact">
                        <div class="pf-card-title">
                            <div class="pf-icon blue"><i class="bi bi-person-vcard-fill"></i></div>
                            <div>
                                <span class="card-kicker">Data akun</span>
                                <h3>Informasi pribadi</h3>
                            </div>
                        </div>
                        <span class="pf-lock-tag"><i class="bi bi-lock-fill"></i> Hanya lihat</span>
                    </div>

                    <div class="pf-info-grid">
                        <?php foreach ($infoItems as [$label, $icon, $color, $value, $emptyText, $copyable, $full]): ?>
                            <?php $hasValue = $value !== ''; ?>
                            <div class="pf-info-item <?= $full ? 'pf-span-2' : ''; ?>">
                                <div class="pf-icon <?= e($color); ?>"><i class="bi <?= e($icon); ?>"></i></div>
                                <div class="pf-info-text">
                                    <span><?= e($label); ?></span>
                                    <strong class="<?= $hasValue ? '' : 'is-empty'; ?>">
                                        <?= e($hasValue ? $value : $emptyText); ?>
                                    </strong>
                                </div>
                                <?php if ($copyable && $hasValue): ?>
                                    <button type="button" class="pf-copy"
                                            data-copy="<?= e($value); ?>"
                                            aria-label="Salin <?= e($label); ?>" title="Salin">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Kolom samping -->
                <div class="pf-side">

                    <!-- Status akun -->
                    <section class="dashboard-card pf-card">
                        <div class="card-heading compact">
                            <div class="pf-card-title">
                                <div class="pf-icon green"><i class="bi bi-shield-check"></i></div>
                                <div>
                                    <span class="card-kicker">Keamanan</span>
                                    <h3>Status akun</h3>
                                </div>
                            </div>
                        </div>

                        <div class="pf-status-list">
                            <div class="pf-status-row">
                                <div class="pf-icon green"><i class="bi bi-check-lg"></i></div>
                                <div>
                                    <span>Status</span>
                                    <strong>Akun Aktif</strong>
                                </div>
                            </div>
                            <div class="pf-status-row">
                                <div class="pf-icon purple"><i class="bi bi-person-badge-fill"></i></div>
                                <div>
                                    <span>Tipe akun</span>
                                    <strong>Customer</strong>
                                </div>
                            </div>
                            <div class="pf-status-row">
                                <div class="pf-icon blue"><i class="bi bi-calendar-check-fill"></i></div>
                                <div>
                                    <span>Bergabung sejak</span>
                                    <strong><?= e($joinedAt); ?></strong>
                                </div>
                            </div>
                            <?php if ($customerId > 0): ?>
                                <div class="pf-status-row">
                                    <div class="pf-icon orange"><i class="bi bi-hash"></i></div>
                                    <div>
                                        <span>ID pelanggan</span>
                                        <strong>#<?= $customerId; ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Bantuan ubah data -->
                    <section class="dashboard-card help-card">
                        <div class="help-background"></div>
                        <div class="help-content">
                            <div class="help-icon"><i class="bi bi-headset"></i></div>
                            <span class="card-kicker">Perlu perubahan data?</span>
                            <h3>Hubungi Customer Service</h3>
                            <p>Data profil dikelola oleh tim kami. Hubungi CS jika ada informasi yang perlu diperbarui.</p>
                            <a href="chat.php" class="help-button">
                                <i class="bi bi-chat-dots-fill"></i> Buka live chat
                            </a>
                        </div>
                    </section>
                </div>
            </div>

            <!-- ---------- AKSI CEPAT ---------- -->
            <div class="section-title-row quick-title">
                <div>
                    <span class="section-kicker">Pintasan</span>
                    <h2>Aksi cepat</h2>
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

            <!-- ---------- FOOTER ---------- -->
            <footer class="dashboard-footer pf-footer">
                <span><i class="bi bi-shield-lock-fill"></i> Data akun kamu tersimpan dengan aman</span>
                <a href="../logout.php">Logout <i class="bi bi-box-arrow-right"></i></a>
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

        if (!open && document.activeElement === closeButton && mobileButton) {
            mobileButton.focus();
        }
    }

    if (mobileButton) { mobileButton.addEventListener('click', function () { toggleSidebar(true); }); }
    if (closeButton) { closeButton.addEventListener('click', function () { toggleSidebar(false); }); }
    if (overlay) { overlay.addEventListener('click', function () { toggleSidebar(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { toggleSidebar(false); }
    });
    window.addEventListener('resize', function () {
        if (!isMobile()) { toggleSidebar(false); }
    });
    toggleSidebar(false);

    /* ---------------- TEMA (sama dengan dashboard) ---------------- */

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
        applyTheme('light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const next = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('yesnet-customer-theme', next); } catch (error) {}
        });
    }

    /* ---------------- SALIN DATA ---------------- */

    function fallbackCopy(text) {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (error) {}
        document.body.removeChild(area);
    }

    document.querySelectorAll('.pf-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            const text = button.dataset.copy || '';
            const done = function () {
                const icon = button.querySelector('i');
                if (!icon) { return; }
                icon.className = 'bi bi-check-lg';
                button.classList.add('is-copied');
                window.setTimeout(function () {
                    icon.className = 'bi bi-copy';
                    button.classList.remove('is-copied');
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text);
                    done();
                });
            } else {
                fallbackCopy(text);
                done();
            }
        });
    });
});
</script>

</body>
</html>