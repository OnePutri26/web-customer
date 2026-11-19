<?php

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/service_notifications.php';

date_default_timezone_set('Asia/Jakarta');

function adminDashboardEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function adminDashboardMoney(mixed $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$stats = [
    'customers' => 0,
    'active_customers' => 0,
    'inactive_customers' => 0,
    'income_month' => 0,
    'paid_month' => 0,
    'arrears' => 0,
    'overdue' => 0,
    'active_complaints' => 0,
    'network_issues' => 0,
];
$payments = [];
$networkIssues = [];
$incomeLabels = [];
$incomeValues = [];
$customerValues = [];
$customerGrowthAvailable = false;
$activeNoticeCount = 0;
$latestIncidents = [];

if (yesnetNotificationTablesAvailable($conn)) {
    $activeNoticeCount = (int) $conn->query(
        'SELECT COUNT(*) AS total FROM yesnet_network_incidents WHERE is_active = 1'
    )->fetch_assoc()['total'];
    $incidentResult = $conn->query(
        'SELECT id, judul, message, type, area_name
         FROM yesnet_network_incidents WHERE is_active = 1
         ORDER BY updated_at DESC, id DESC LIMIT 4'
    );
    $latestIncidents = $incidentResult->fetch_all(MYSQLI_ASSOC);
}

try {
    $customerStats = $conn->query("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(LOWER(status_langganan) IN ('aktif', 'active', '1')), 0) AS active
        FROM customers
    ")->fetch_assoc();
    $stats['customers'] = (int) $customerStats['total'];
    $stats['active_customers'] = (int) $customerStats['active'];
    $stats['inactive_customers'] = max(0, $stats['customers'] - $stats['active_customers']);

    $billingStats = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending') THEN jumlah ELSE 0 END), 0) AS arrears,
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending') AND jatuh_tempo < CURDATE()
                              THEN jumlah ELSE 0 END), 0) AS overdue
        FROM tagihan
    ")->fetch_assoc();
    $stats['arrears'] = (float) $billingStats['arrears'];
    $stats['overdue'] = (float) $billingStats['overdue'];

    $paymentStats = $conn->query("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(jumlah), 0) AS income
        FROM pembayaran
        WHERE status = 'paid'
          AND tanggal_bayar >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND tanggal_bayar < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
    ")->fetch_assoc();
    $stats['paid_month'] = (int) $paymentStats['total'];
    $stats['income_month'] = (float) $paymentStats['income'];

    $complaintStats = $conn->query("
        SELECT COUNT(*) AS total
        FROM complaint
        WHERE LOWER(status) NOT IN ('resolved', 'rejected')
    ")->fetch_assoc();
    $stats['active_complaints'] = (int) $complaintStats['total'];

    $networkStats = $conn->query("
        SELECT COUNT(*) AS total
        FROM network_areas
        WHERE LOWER(status) IN ('gangguan', 'down', 'offline')
    ")->fetch_assoc();
    $stats['network_issues'] = (int) $networkStats['total'];

    $paymentResult = $conn->query("
        SELECT p.nomor_pembayaran, c.nama, p.jumlah, p.status, p.tanggal_bayar
        FROM pembayaran p
        LEFT JOIN customers c ON c.id = p.id_customer
        ORDER BY p.id DESC
        LIMIT 5
    ");
    while ($payment = $paymentResult->fetch_assoc()) {
        $payments[] = $payment;
    }

    $networkResult = $conn->query("
        SELECT nama_area, status
        FROM network_areas
        WHERE LOWER(status) IN ('gangguan', 'down', 'offline')
        ORDER BY nama_area
        LIMIT 5
    ");
    while ($issue = $networkResult->fetch_assoc()) {
        $networkIssues[] = $issue;
    }

    $monthCursor = new DateTimeImmutable('first day of this month');
    for ($offset = 5; $offset >= 0; $offset--) {
        $month = $monthCursor->modify("-{$offset} months");
        $monthKey = $month->format('Y-m');
        $incomeLabels[] = $month->format('M Y');
        $incomeValues[$monthKey] = 0;
        $customerValues[$monthKey] = 0;
    }

    $incomeResult = $conn->query("
        SELECT DATE_FORMAT(tanggal_bayar, '%Y-%m') AS period,
               COALESCE(SUM(jumlah), 0) AS total
        FROM pembayaran
        WHERE status = 'paid'
          AND tanggal_bayar >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
          AND tanggal_bayar < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
        GROUP BY DATE_FORMAT(tanggal_bayar, '%Y-%m')
    ");
    while ($row = $incomeResult->fetch_assoc()) {
        if (array_key_exists($row['period'], $incomeValues)) {
            $incomeValues[$row['period']] = (float) $row['total'];
        }
    }

    $columnCheck = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'customers'
          AND column_name = 'created_at'
    ");
    $columnCheck->execute();
    $customerGrowthAvailable = (int) $columnCheck->get_result()->fetch_assoc()['total'] > 0;
    $columnCheck->close();

    if ($customerGrowthAvailable) {
        $customerResult = $conn->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS total
            FROM customers
            WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
              AND created_at < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ");
        while ($row = $customerResult->fetch_assoc()) {
            if (array_key_exists($row['period'], $customerValues)) {
                $customerValues[$row['period']] = (int) $row['total'];
            }
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log('Admin dashboard query failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data dashboard belum dapat dimuat. Periksa ketersediaan tabel operasional dan coba kembali.');
}

$chartCustomers = array_values($customerValues);
$chartIncome = array_values($incomeValues);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Operasional - YESNET Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">Y</span><span>YESNET <small>OPERATIONS</small></span></a>
        <div class="nav-caption">MENU UTAMA</div>
        <nav class="admin-nav" aria-label="Navigasi admin">
            <a class="active" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
            <a href="overview.php?section=customers"><i class="bi bi-people"></i> Pelanggan</a>
            <a href="overview.php?section=invoices"><i class="bi bi-receipt"></i> Tagihan</a>
            <a href="overview.php?section=payments"><i class="bi bi-credit-card"></i> Pembayaran</a>
            <a href="admin/Paket/index.php"><i class="bi bi-box-seam"></i> Paket Internet</a>
            <a href="complaints.php"><i class="bi bi-headset"></i> Pengaduan</a>
            <a href="coverage_requests.php"><i class="bi bi-geo-alt"></i> Coverage</a>
            <a href="overview.php?section=technicians"><i class="bi bi-person-gear"></i> Teknisi</a>
            <a href="overview.php?section=network"><i class="bi bi-router"></i> Area Jaringan</a>
            <a href="notifications.php"><i class="bi bi-bell"></i> Notifikasi Layanan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="admin-profile">
                <span class="profile-avatar"><?= adminDashboardEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span>
                <span><strong><?= adminDashboardEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span>
            </div>
            <a class="logout-link" href="logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">OVERVIEW</span><h1>Dashboard Operasional</h1></div>
            <div class="topbar-meta">
                <div class="admin-notification-wrap">
                    <button class="admin-notification-button" id="adminNotificationBell" type="button" aria-label="Notifikasi layanan" aria-expanded="false">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($activeNoticeCount > 0): ?><span class="admin-notification-badge"><?= $activeNoticeCount > 99 ? '99+' : $activeNoticeCount ?></span><span class="admin-notification-dot"></span><?php endif; ?>
                    </button>
                    <div class="admin-notification-menu" id="adminNotificationMenu" hidden>
                        <div class="admin-notification-menu-head"><strong>Notifikasi layanan aktif</strong><a href="notifications.php">Lihat Semua</a></div>
                        <?php if ($latestIncidents === []): ?>
                            <div class="admin-notification-empty">Belum ada notifikasi aktif.</div>
                        <?php else: foreach ($latestIncidents as $incident): ?>
                            <a class="admin-notification-item" href="notifications.php?edit=<?= (int) $incident['id'] ?>">
                                <i class="bi <?= $incident['type'] === 'selesai' ? 'bi-check-circle-fill' : 'bi-wifi-off' ?>"></i>
                                <span><strong><?= adminDashboardEscape($incident['judul']) ?></strong><small><?= adminDashboardEscape($incident['area_name']) ?> · <?= adminDashboardEscape($incident['message']) ?></small></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <span class="live-dot"></span> Data operasional <span class="topbar-date"><?= date('d M Y') ?></span>
            </div>
        </header>

        <section class="page-content">
            <div class="welcome-row">
                <div><h2>Selamat datang, <?= adminDashboardEscape($_SESSION['nama'] ?? 'Admin') ?></h2><p>Ringkasan kondisi layanan YESNET hari ini.</p></div>
                <a class="btn btn-primary" href="overview.php?section=customers"><i class="bi bi-people me-2"></i>Kelola Pelanggan</a>
            </div>

            <div class="stat-grid">
                <article class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                    <div><span>Total pelanggan</span><strong><?= number_format($stats['customers']) ?></strong><small><?= number_format($stats['active_customers']) ?> aktif · <?= number_format($stats['inactive_customers']) ?> belum aktif</small></div>
                </article>
                <article class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-wallet2"></i></div>
                    <div><span>Pendapatan bulan ini</span><strong><?= adminDashboardMoney($stats['income_month']) ?></strong><small><?= number_format($stats['paid_month']) ?> pembayaran lunas</small></div>
                </article>
                <article class="stat-card">
                    <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
                    <div><span>Total tunggakan</span><strong><?= adminDashboardMoney($stats['arrears']) ?></strong><small><?= adminDashboardMoney($stats['overdue']) ?> melewati jatuh tempo</small></div>
                </article>
                <article class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-activity"></i></div>
                    <div><span>Perlu perhatian</span><strong><?= number_format($stats['active_complaints'] + $stats['network_issues']) ?></strong><small><?= number_format($stats['active_complaints']) ?> pengaduan · <?= number_format($stats['network_issues']) ?> area gangguan</small></div>
                </article>
            </div>

            <div class="dashboard-grid">
                <section class="panel chart-panel">
                    <div class="panel-heading"><div><h3>Tren operasional</h3><p>Perkembangan enam bulan terakhir</p></div><span class="panel-badge"><i class="bi bi-graph-up-arrow"></i> 6 bulan</span></div>
                    <div class="chart-wrap"><canvas id="operationsChart" aria-label="Grafik pelanggan dan pendapatan" role="img"></canvas></div>
                    <?php if (!$customerGrowthAvailable): ?>
                        <p class="data-note"><i class="bi bi-info-circle me-1"></i>Grafik pertumbuhan pelanggan belum tersedia karena tabel pelanggan tidak menyimpan tanggal pendaftaran.</p>
                    <?php endif; ?>
                </section>

                <section class="panel network-panel">
                    <div class="panel-heading"><div><h3>Monitoring jaringan</h3><p>Area yang sedang mengalami gangguan</p></div><a class="panel-link" href="overview.php?section=network">Lihat area</a></div>
                    <div class="network-summary"><span class="network-pulse <?= $stats['network_issues'] > 0 ? 'is-down' : '' ?>"></span><strong><?= $stats['network_issues'] > 0 ? number_format($stats['network_issues']) . ' area terdampak' : 'Semua area normal' ?></strong></div>
                    <div class="list-stack">
                        <?php if ($networkIssues === []): ?>
                            <div class="empty-state"><i class="bi bi-wifi"></i><span>Tidak ada gangguan jaringan aktif.</span></div>
                        <?php else: ?>
                            <?php foreach ($networkIssues as $issue): ?>
                                <div class="list-row"><span class="status-indicator danger"></span><span class="list-title"><?= adminDashboardEscape($issue['nama_area']) ?></span><span class="status-pill danger"><?= adminDashboardEscape($issue['status']) ?></span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a class="incident-link" href="admin/jaringan/gangguan.php"><i class="bi bi-plus-circle me-2"></i>Catat gangguan jaringan</a>
                </section>
            </div>

            <section class="panel payments-panel">
                <div class="panel-heading"><div><h3>Transaksi terbaru</h3><p>Pembaruan pembayaran terakhir</p></div><a class="panel-link" href="overview.php?section=payments">Lihat semua <i class="bi bi-arrow-right ms-1"></i></a></div>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead><tr><th>Nomor transaksi</th><th>Pelanggan</th><th>Tanggal</th><th>Jumlah</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if ($payments === []): ?>
                            <tr><td class="empty-table" colspan="5">Belum ada transaksi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="fw-semibold"><?= adminDashboardEscape($payment['nomor_pembayaran'] ?: '—') ?></td>
                                    <td><?= adminDashboardEscape($payment['nama'] ?: 'Pelanggan') ?></td>
                                    <td><?= adminDashboardEscape($payment['tanggal_bayar'] ?: '—') ?></td>
                                    <td class="fw-semibold"><?= adminDashboardMoney($payment['jumlah']) ?></td>
                                    <td><span class="status-pill <?= strtolower((string) $payment['status']) === 'paid' ? 'success' : 'neutral' ?>"><?= adminDashboardEscape($payment['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <footer class="page-footer">YESNET Internal Operations <span>·</span> <?= date('Y') ?></footer>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.yesnetDashboard = {
    labels: <?= json_encode($incomeLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    customers: <?= json_encode($chartCustomers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    income: <?= json_encode($chartIncome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var bell = document.getElementById('adminNotificationBell');
    var menu = document.getElementById('adminNotificationMenu');
    if (!bell || !menu) { return; }
    bell.addEventListener('click', function () {
        menu.hidden = !menu.hidden;
        bell.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.admin-notification-wrap')) {
            menu.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>
</body>
</html>
