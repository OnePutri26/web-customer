<?php

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';

function adminOverviewEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

function adminOverviewQuery(mysqli $conn, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($params !== []) {
        $bindings = [];
        foreach ($params as $index => &$value) {
            $bindings[$index] = &$value;
        }
        array_unshift($bindings, $types);
        $stmt->bind_param(...$bindings);
        unset($value);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

$sections = [
    'customers' => [
        'title' => 'Pelanggan',
        'columns' => ['Nama', 'Telepon', 'Email', 'Paket', 'Status'],
        'select' => 'SELECT c.id, c.nama, c.telephone, c.email, p.nama_paket, c.status_langganan',
        'from' => 'FROM customers c LEFT JOIN paket_wifi p ON p.id = c.paket_id',
        'search' => '(c.nama LIKE ? OR c.telephone LIKE ? OR c.email LIKE ?)',
        'status' => 'c.status_langganan',
    ],
    'packages' => [
        'title' => 'Paket WiFi',
        'columns' => ['Nama Paket', 'Kecepatan (Mbps)', 'Harga', 'Status'],
        'select' => 'SELECT id, nama_paket, speed_mbps, harga, status',
        'from' => 'FROM paket_wifi',
        'search' => '(nama_paket LIKE ? OR deskripsi LIKE ?)',
        'status' => 'status',
    ],
    'invoices' => [
        'title' => 'Tagihan',
        'columns' => ['Nomor Tagihan', 'Pelanggan', 'Jumlah', 'Jatuh Tempo', 'Status'],
        'select' => 'SELECT t.nomor_tagihan, c.nama, t.jumlah, t.jatuh_tempo, t.status',
        'from' => 'FROM tagihan t LEFT JOIN customers c ON c.id = t.id_customer',
        'search' => '(t.nomor_tagihan LIKE ? OR c.nama LIKE ?)',
        'status' => 't.status',
    ],
    'payments' => [
        'title' => 'Pembayaran',
        'columns' => ['Nomor Pembayaran', 'Pelanggan', 'Jumlah', 'Provider', 'Invoice Xendit', 'Metode', 'Status', 'Tanggal'],
        'select' => "SELECT p.nomor_pembayaran, c.nama, p.jumlah, COALESCE(p.payment_provider, 'legacy') AS provider,
                            p.xendit_invoice_id, p.metode_pembayaran, p.status, p.tanggal_bayar",
        'from' => 'FROM pembayaran p LEFT JOIN customers c ON c.id = p.id_customer',
        'search' => '(p.nomor_pembayaran LIKE ? OR c.nama LIKE ? OR p.xendit_invoice_id LIKE ?)',
        'status' => 'p.status',
    ],
    'technicians' => [
        'title' => 'Teknisi',
        'columns' => ['Nama', 'Status'],
        'select' => 'SELECT nama, status',
        'from' => 'FROM technicians',
        'search' => '(nama LIKE ?)',
        'status' => 'status',
    ],
    'network' => [
        'title' => 'Area Jaringan',
        'columns' => ['Area', 'Status'],
        'select' => 'SELECT nama_area, status',
        'from' => 'FROM network_areas',
        'search' => '(nama_area LIKE ?)',
        'status' => 'status',
    ],
];

$sectionKey = (string) ($_GET['section'] ?? '');
if (!isset($sections[$sectionKey])) {
    header('Location: dashboard.php', true, 302);
    exit;
}
$section = $sections[$sectionKey];
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$search = substr($search, 0, 100);
$statusFilter = substr($statusFilter, 0, 50);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null ? max(1, $page) : 1;
$pageSize = 25;

$conditions = [];
$params = [];
$types = '';
if ($search !== '') {
    $conditions[] = $section['search'];
    $searchTerms = array_fill(0, substr_count($section['search'], '?'), '%' . $search . '%');
    array_push($params, ...$searchTerms);
    $types .= str_repeat('s', count($searchTerms));
}
if ($statusFilter !== '') {
    $conditions[] = 'CAST(' . $section['status'] . ' AS CHAR) = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

try {
    $countResult = adminOverviewQuery(
        $conn,
        'SELECT COUNT(*) AS total ' . $section['from'] . $where,
        $types,
        $params
    );
    $totalRows = (int) $countResult->fetch_assoc()['total'];
    $totalPages = max(1, (int) ceil($totalRows / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $listParams = $params;
    $listTypes = $types;
    $limitClause = '';
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $limitClause = ' ORDER BY 1 LIMIT 10000';
    } else {
        $limitClause = ' ORDER BY 1 LIMIT ? OFFSET ?';
        $listParams[] = $pageSize;
        $listParams[] = $offset;
        $listTypes .= 'ii';
    }
    $result = adminOverviewQuery(
        $conn,
        $section['select'] . ' ' . $section['from'] . $where . $limitClause,
        $listTypes,
        $listParams
    );
} catch (mysqli_sql_exception $e) {
    error_log('Admin overview query failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data belum dapat dimuat. Silakan periksa ketersediaan tabel dan coba kembali.');
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'yesnet-' . preg_replace('/[^a-z0-9-]/i', '-', $sectionKey) . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('File laporan tidak dapat dibuat.');
    }
    fputcsv($output, $section['columns']);
    while ($row = $result->fetch_row()) {
        if (in_array($sectionKey, ['customers', 'packages'], true)) {
            array_shift($row);
        }
        $safeRow = array_map(static function (mixed $value): string {
            $value = (string) ($value ?? '');
            return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
        }, $row);
        fputcsv($output, $safeRow);
    }
    fclose($output);
    exit;
}

$queryArgs = ['section' => $sectionKey];
if ($search !== '') {
    $queryArgs['q'] = $search;
}
if ($statusFilter !== '') {
    $queryArgs['status'] = $statusFilter;
}
$exportUrl = 'overview.php?' . http_build_query($queryArgs + ['export' => 'csv']);
$statusLabels = [
    'customers' => 'Status langganan',
    'packages' => 'Status paket',
    'invoices' => 'Status tagihan',
    'payments' => 'Status pembayaran',
    'technicians' => 'Status teknisi',
    'network' => 'Status area',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= adminOverviewEscape($section['title']) ?> - YESNET Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
    <style>
        .overview-card { overflow: hidden; border: 1px solid #e9edf3; border-radius: 12px; background: #fff; }
        .overview-toolbar { padding: 18px; border-bottom: 1px solid #edf0f5; }
        .overview-table th { padding: 12px 16px; background: #fafbfd; color: #788599; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
        .overview-table td { padding: 13px 16px; color: #45536a; font-size: 12px; }
        .overview-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; color: #788599; font-size: 11px; }
        .overview-pagination .btn { font-size: 11px; }
        @media print { .overview-toolbar, .overview-pagination, .topbar-meta { display: none !important; } .overview-card { border: 0; } }
    </style>
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">Y</span><span>YESNET <small>OPERATIONS</small></span></a>
        <div class="nav-caption">MENU UTAMA</div>
        <nav class="admin-nav" aria-label="Navigasi admin">
            <a href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
            <a class="<?= $sectionKey === 'customers' ? 'active' : '' ?>" href="overview.php?section=customers"><i class="bi bi-people"></i> Pelanggan</a>
            <a class="<?= $sectionKey === 'invoices' ? 'active' : '' ?>" href="overview.php?section=invoices"><i class="bi bi-receipt"></i> Tagihan</a>
            <a class="<?= $sectionKey === 'payments' ? 'active' : '' ?>" href="overview.php?section=payments"><i class="bi bi-credit-card"></i> Pembayaran</a>
            <a href="admin/Paket/index.php"><i class="bi bi-box-seam"></i> Paket Internet</a>
            <a href="complaints.php"><i class="bi bi-headset"></i> Pengaduan</a>
            <a href="coverage_requests.php"><i class="bi bi-geo-alt"></i> Coverage</a>
            <a class="<?= $sectionKey === 'technicians' ? 'active' : '' ?>" href="overview.php?section=technicians"><i class="bi bi-person-gear"></i> Teknisi</a>
            <a class="<?= $sectionKey === 'network' ? 'active' : '' ?>" href="overview.php?section=network"><i class="bi bi-router"></i> Area Jaringan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="admin-profile"><span class="profile-avatar"><?= adminOverviewEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span><span><strong><?= adminOverviewEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span></div>
            <a class="logout-link" href="logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">MANAJEMEN OPERASIONAL</span><h1><?= adminOverviewEscape($section['title']) ?></h1></div>
            <div class="topbar-meta"><span class="live-dot"></span> Data operasional <span class="topbar-date"><?= date('d M Y') ?></span></div>
        </header>
        <section class="page-content">
            <div class="overview-card">
                <form method="get" class="overview-toolbar">
                    <input type="hidden" name="section" value="<?= adminOverviewEscape($sectionKey) ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label small text-secondary" for="search">Cari <?= adminOverviewEscape(strtolower($section['title'])) ?></label>
                            <input class="form-control form-control-sm" id="search" name="q" maxlength="100" value="<?= adminOverviewEscape($search) ?>" placeholder="Ketik kata kunci...">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small text-secondary" for="status">Filter <?= adminOverviewEscape($statusLabels[$sectionKey]) ?></label>
                            <input class="form-control form-control-sm" id="status" name="status" maxlength="50" value="<?= adminOverviewEscape($statusFilter) ?>" placeholder="Contoh: aktif / paid">
                        </div>
                        <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Cari</button>
                            <a class="btn btn-outline-secondary btn-sm" href="overview.php?section=<?= adminOverviewEscape($sectionKey) ?>">Reset</a>
                            <a class="btn btn-outline-primary btn-sm" href="<?= adminOverviewEscape($exportUrl) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Ekspor Excel (CSV)</a>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Simpan PDF</button>
                        </div>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 overview-table">
                        <thead><tr><?php foreach ($section['columns'] as $column): ?><th scope="col"><?= adminOverviewEscape($column) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php if ($result->num_rows === 0): ?>
                            <tr><td class="text-center text-secondary py-5" colspan="<?= count($section['columns']) ?>">Tidak ada data yang cocok.</td></tr>
                        <?php else: ?>
                            <?php while ($row = $result->fetch_row()): ?>
                                <?php if ($sectionKey === 'customers'): ?>
                                    <tr>
                                        <td><a class="fw-semibold text-decoration-none" href="admin/pelanggan/detail.php?id=<?= (int) $row[0] ?>"><?= adminOverviewEscape($row[1]) ?></a></td>
                                        <?php foreach (array_slice($row, 2) as $value): ?><td><?= adminOverviewEscape($value) ?></td><?php endforeach; ?>
                                    </tr>
                                <?php else: ?>
                                    <tr><?php foreach ($row as $value): ?><td><?= adminOverviewEscape($value) ?></td><?php endforeach; ?></tr>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="overview-pagination">
                    <span>Menampilkan <?= $totalRows === 0 ? 0 : $offset + 1 ?>–<?= min($offset + $pageSize, $totalRows) ?> dari <?= number_format($totalRows) ?> data</span>
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex gap-2">
                            <?php $previousArgs = $queryArgs + ['page' => max(1, $page - 1)]; $nextArgs = $queryArgs + ['page' => min($totalPages, $page + 1)]; ?>
                            <a class="btn btn-outline-secondary btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" href="overview.php?<?= adminOverviewEscape(http_build_query($previousArgs)) ?>">Sebelumnya</a>
                            <span class="align-self-center"><?= $page ?> / <?= $totalPages ?></span>
                            <a class="btn btn-outline-secondary btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>" href="overview.php?<?= adminOverviewEscape(http_build_query($nextArgs)) ?>">Berikutnya</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <footer class="page-footer">YESNET Internal Operations <span>·</span> <?= date('Y') ?></footer>
        </section>
    </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
