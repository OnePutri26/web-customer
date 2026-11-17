<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/complaint.php';

function adminComplaintEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$statuses = YESNET_COMPLAINT_STATUSES;
$statusInput = $_GET['status'] ?? '';
$selectedStatus = is_string($statusInput) ? $statusInput : '';
if ($selectedStatus !== '' && !isset($statuses[$selectedStatus])) {
    $selectedStatus = '';
}
$searchInput = $_GET['q'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
$types = '';
if ($selectedStatus !== '') {
    $where[] = 'c.status = ?';
    $types .= 's';
    $params[] = $selectedStatus;
}
if ($search !== '') {
    $where[] = '(c.complaint_code LIKE ? OR c.subject LIKE ? OR cu.nama LIKE ? OR cu.telephone LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total,
           SUM(c.status = 'pending') AS pending,
           SUM(c.status = 'in_progress') AS in_progress,
           SUM(c.status = 'waiting_confirmation') AS waiting_confirmation,
           SUM(c.status = 'resolved') AS resolved,
           SUM(c.status = 'rejected') AS rejected
    FROM complaint c
    JOIN customers cu ON cu.id = c.id_customer
");
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$stmt = $conn->prepare("
    SELECT u.id, u.nama
    FROM users u
    WHERE LOWER(u.role) IN ('teknisi', 'technician')
      AND LOWER(TRIM(u.status)) IN ('1', 'active', 'aktif')
    ORDER BY u.nama
");
$stmt->execute();
$technicians = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM complaint c
    JOIN customers cu ON cu.id = c.id_customer
    {$whereSql}
");
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRows = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = "
    SELECT c.id, c.complaint_code, c.category, c.subject, c.priority, c.status,
           c.created_at, cu.nama AS customer_name, cu.telephone AS customer_phone,
           u.nama AS technician_name
    FROM complaint c
    JOIN customers cu ON cu.id = c.id_customer
    LEFT JOIN users u ON u.id = c.assigned_user_id
    {$whereSql}
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($query);
$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = $_SESSION['complaint_admin_flash'] ?? null;
unset($_SESSION['complaint_admin_flash']);
$filterQuery = ['q' => $search, 'status' => $selectedStatus];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengaduan - YESNET Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
    <style>
        .complaint-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
        .complaint-stat,.complaint-table { background:#fff; border:1px solid #e9edf3; border-radius:12px; }
        .complaint-stat { padding:16px; }
        .complaint-stat span { display:block; color:#73819d; font-size:11px; }
        .complaint-stat strong { display:block; margin-top:5px; color:#172b4d; font-size:23px; }
        .complaint-table { overflow:hidden; }
        .complaint-table th { padding:12px; background:#f8faff; color:#73819d; font-size:10px; text-transform:uppercase; white-space:nowrap; }
        .complaint-table td { padding:12px; color:#45536a; font-size:12px; vertical-align:middle; }
        .status-pill { display:inline-block; padding:5px 9px; border-radius:999px; background:#eef4ff; color:#2256b5; font-weight:700; white-space:nowrap; }
        @media(max-width:700px){.complaint-toolbar form{width:100%}.complaint-toolbar input,.complaint-toolbar select{width:100%}}
    </style>
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">Y</span><span>YESNET <small>OPERATIONS</small></span></a>
        <div class="nav-caption">MENU UTAMA</div>
        <nav class="admin-nav" aria-label="Navigasi admin">
            <a href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
            <a href="overview.php?section=customers"><i class="bi bi-people"></i> Pelanggan</a>
            <a href="overview.php?section=invoices"><i class="bi bi-receipt"></i> Tagihan</a>
            <a href="overview.php?section=payments"><i class="bi bi-credit-card"></i> Pembayaran</a>
            <a href="admin/Paket/index.php"><i class="bi bi-box-seam"></i> Paket Internet</a>
            <a class="active" href="complaints.php"><i class="bi bi-headset"></i> Complaint</a>
            <a href="coverage_requests.php"><i class="bi bi-geo-alt"></i> Coverage</a>
            <a href="overview.php?section=technicians"><i class="bi bi-person-gear"></i> Teknisi</a>
            <a href="overview.php?section=network"><i class="bi bi-router"></i> Area Jaringan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="admin-profile"><span class="profile-avatar"><?= adminComplaintEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span><span><strong><?= adminComplaintEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span></div>
            <a class="logout-link" href="logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">CUSTOMER SUPPORT</span><h1>Complaint</h1></div>
            <div class="topbar-meta"><span class="live-dot"></span> Data penanganan</div>
        </header>
        <section class="page-content">
            <?php if (is_array($flash) && !empty($flash['message'])): ?>
                <div class="alert alert-<?= ($flash['type'] ?? '') === 'error' ? 'danger' : 'success' ?>" role="status"><?= adminComplaintEscape($flash['message']) ?></div>
            <?php endif; ?>
            <div class="complaint-stats">
                <?php foreach ($statuses as $key => $label): ?>
                    <article class="complaint-stat"><span><?= adminComplaintEscape($label) ?></span><strong><?= number_format((int) ($stats[$key] ?? 0)) ?></strong></article>
                <?php endforeach; ?>
            </div>
            <div class="complaint-table">
                <div class="complaint-toolbar d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 border-bottom">
                    <strong><?= number_format($totalRows) ?> laporan</strong>
                    <form method="get" class="d-flex flex-wrap gap-2">
                        <input class="form-control form-control-sm" type="search" name="q" value="<?= adminComplaintEscape($search) ?>" placeholder="Cari tiket, judul, pelanggan..." aria-label="Cari laporan">
                        <select class="form-select form-select-sm" name="status" aria-label="Filter status">
                            <option value="">Semua status</option>
                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= adminComplaintEscape($key) ?>" <?= $selectedStatus === $key ? 'selected' : '' ?>><?= adminComplaintEscape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
                        <a class="btn btn-sm btn-outline-secondary" href="complaints.php">Reset</a>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Tiket / Tanggal</th><th>Pelanggan</th><th>Gangguan</th><th>Teknisi</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($complaints === []): ?>
                            <tr><td colspan="6" class="text-center py-5 text-secondary">Tidak ada laporan yang cocok.</td></tr>
                        <?php else: foreach ($complaints as $row): ?>
                            <tr>
                                <td><strong><?= adminComplaintEscape($row['complaint_code']) ?></strong><br><small><?= adminComplaintEscape(date('d M Y H:i', strtotime((string) $row['created_at']))) ?></small></td>
                                <td><?= adminComplaintEscape($row['customer_name']) ?><br><small><?= adminComplaintEscape($row['customer_phone']) ?></small></td>
                                <td><?= adminComplaintEscape($row['subject']) ?><br><small><?= adminComplaintEscape(YESNET_COMPLAINT_CATEGORIES[$row['category']] ?? $row['category']) ?></small></td>
                                <td><?= adminComplaintEscape($row['technician_name'] ?: 'Belum ditugaskan') ?></td>
                                <?php $displayStatus = yesnetComplaintStatus((string) $row['status']); ?>
                                <td><span class="status-pill"><?= adminComplaintEscape($statuses[$displayStatus] ?? ucfirst(str_replace('_', ' ', $displayStatus))) ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="complaint_detail.php?id=<?= (int) $row['id'] ?>">Detail</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 border-top small">
                        <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                        <div class="d-flex gap-2">
                            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= adminComplaintEscape(http_build_query($filterQuery + ['page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
                            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= adminComplaintEscape(http_build_query($filterQuery + ['page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
