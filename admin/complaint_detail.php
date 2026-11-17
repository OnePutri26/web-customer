<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/complaint.php';

function adminComplaintDetailEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$complaintId || $complaintId < 1) {
    http_response_code(404);
    exit('Laporan tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT c.*, cu.nama AS customer_name, cu.telephone AS customer_phone,
           cu.email AS customer_email, u.nama AS technician_name
    FROM complaint c
    JOIN customers cu ON cu.id = c.id_customer
    LEFT JOIN users u ON u.id = c.assigned_user_id
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$complaint) {
    http_response_code(404);
    exit('Laporan tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT actor_name, event_type, description, old_status, new_status, created_at
    FROM complaint_histories
    WHERE complaint_id = ?
    ORDER BY created_at ASC, id ASC
");
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$histories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT id, nama
    FROM users
    WHERE LOWER(role) IN ('teknisi', 'technician')
      AND LOWER(TRIM(status)) IN ('1', 'active', 'aktif')
    ORDER BY nama
");
$stmt->execute();
$technicians = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare('SELECT 1 FROM complaint_attachments WHERE complaint_id = ? LIMIT 1');
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$hasAttachment = $stmt->get_result()->num_rows > 0;
$stmt->close();

$status = yesnetComplaintStatus((string) $complaint['status']);
$flash = $_SESSION['complaint_admin_flash'] ?? null;
unset($_SESSION['complaint_admin_flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= adminComplaintDetailEscape($complaint['complaint_code']) ?> - Complaint YESNET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
    <style>
        .detail-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr); gap:18px; }
        .detail-card { padding:22px; background:#fff; border:1px solid #e9edf3; border-radius:14px; }
        .history-line { position:relative; margin:18px 0 0 7px; padding-left:25px; border-left:2px solid #e5ebf4; }
        .history-item { position:relative; padding-bottom:22px; }
        .history-item:last-child { padding-bottom:0; }
        .history-dot { position:absolute; top:4px; left:-33px; width:14px; height:14px; border:3px solid white; border-radius:50%; background:#397be4; box-shadow:0 0 0 1px #b8cdf2; }
        .history-meta { color:#788599; font-size:11px; }
        .evidence { max-width:100%; max-height:380px; border-radius:10px; }
        @media(max-width:850px){.detail-grid{grid-template-columns:1fr}}
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
            <div class="admin-profile"><span class="profile-avatar"><?= adminComplaintDetailEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span><span><strong><?= adminComplaintDetailEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span></div>
            <a class="logout-link" href="logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">CUSTOMER SUPPORT</span><h1>Detail Complaint</h1></div>
            <a class="btn btn-sm btn-outline-secondary" href="complaints.php"><i class="bi bi-arrow-left me-1"></i>Daftar laporan</a>
        </header>
        <section class="page-content">
            <?php if (is_array($flash) && !empty($flash['message'])): ?>
                <div class="alert alert-<?= ($flash['type'] ?? '') === 'error' ? 'danger' : 'success' ?>" role="status"><?= adminComplaintDetailEscape($flash['message']) ?></div>
            <?php endif; ?>
            <div class="detail-grid">
                <div class="d-grid gap-3">
                    <article class="detail-card">
                        <div class="d-flex flex-wrap justify-content-between gap-3">
                            <div>
                                <span class="text-primary fw-bold small"><?= adminComplaintDetailEscape($complaint['complaint_code']) ?></span>
                                <h2 class="h4 mt-2 mb-1"><?= adminComplaintDetailEscape($complaint['subject']) ?></h2>
                                <div class="text-secondary small"><?= adminComplaintDetailEscape(YESNET_COMPLAINT_CATEGORIES[$complaint['category']] ?? $complaint['category']) ?> · <?= adminComplaintDetailEscape(date('d M Y H:i', strtotime((string) $complaint['created_at']))) ?></div>
                            </div>
                            <span class="badge text-bg-primary align-self-start"><?= adminComplaintDetailEscape(YESNET_COMPLAINT_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status))) ?></span>
                        </div>
                        <hr>
                        <p class="mb-0" style="white-space:pre-wrap"><?= adminComplaintDetailEscape($complaint['description']) ?></p>
                        <?php if ($hasAttachment): ?>
                            <div class="mt-4"><h3 class="h6">Foto bukti</h3><a href="complaint_attachment.php?id=<?= (int) $complaintId ?>" target="_blank" rel="noopener"><img class="evidence mt-2" src="complaint_attachment.php?id=<?= (int) $complaintId ?>" alt="Foto bukti complaint"></a></div>
                        <?php endif; ?>
                    </article>
                    <article class="detail-card">
                        <h2 class="h5 mb-1">Riwayat aktivitas</h2>
                        <p class="text-secondary small">Catatan perubahan tersimpan sebagai riwayat permanen.</p>
                        <?php if ($histories === []): ?>
                            <p class="text-secondary mb-0">Belum ada aktivitas.</p>
                        <?php else: ?>
                            <div class="history-line">
                                <?php foreach ($histories as $history): ?>
                                    <div class="history-item">
                                        <span class="history-dot" aria-hidden="true"></span>
                                        <div class="fw-semibold"><?= adminComplaintDetailEscape($history['description']) ?></div>
                                        <div class="history-meta mt-1"><?= adminComplaintDetailEscape(date('d M Y H:i', strtotime((string) $history['created_at']))) ?> WIB · <?= adminComplaintDetailEscape($history['actor_name']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                </div>
                <aside class="d-grid align-content-start gap-3">
                    <section class="detail-card">
                        <h2 class="h6 mb-3">Data pelanggan</h2>
                        <div class="fw-semibold"><?= adminComplaintDetailEscape($complaint['customer_name']) ?></div>
                        <div class="text-secondary small"><?= adminComplaintDetailEscape($complaint['customer_phone']) ?></div>
                        <div class="text-secondary small"><?= adminComplaintDetailEscape($complaint['customer_email']) ?></div>
                        <hr>
                        <div class="small text-secondary">Prioritas</div>
                        <div class="fw-semibold"><?= adminComplaintDetailEscape(ucfirst((string) $complaint['priority'])) ?></div>
                    </section>
                    <section class="detail-card">
                        <h2 class="h6 mb-3">Tindakan penanganan</h2>
                        <form method="post" action="update_complaint.php" class="d-grid gap-3">
                            <input type="hidden" name="csrf" value="<?= adminComplaintDetailEscape(adminCsrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $complaintId ?>">
                            <label class="form-label mb-0">Status
                                <select class="form-select mt-1" name="status" required>
                                    <?php foreach (YESNET_COMPLAINT_STATUSES as $key => $label): ?>
                                        <option value="<?= adminComplaintDetailEscape($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= adminComplaintDetailEscape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-label mb-0">Teknisi
                                <select class="form-select mt-1" name="assigned_user_id">
                                    <option value="">Belum ditugaskan</option>
                                    <?php foreach ($technicians as $technician): ?>
                                        <option value="<?= (int) $technician['id'] ?>" <?= (int) $complaint['assigned_user_id'] === (int) $technician['id'] ? 'selected' : '' ?>><?= adminComplaintDetailEscape($technician['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-label mb-0">Catatan penanganan
                                <textarea class="form-control mt-1" name="note" rows="4" maxlength="2000" placeholder="Tulis catatan untuk pelanggan atau tim teknis"></textarea>
                            </label>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Simpan perubahan</button>
                        </form>
                    </section>
                </aside>
            </div>
        </section>
    </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
