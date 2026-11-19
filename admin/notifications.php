<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/service_notifications.php';
adminRequirePermission('network.manage');

date_default_timezone_set('Asia/Jakarta');

function adminNotificationEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function adminNotificationLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

$types = [
    'gangguan' => 'Gangguan jaringan',
    'tidak_stabil' => 'Koneksi tidak stabil',
    'pemeliharaan' => 'Pemeliharaan jaringan',
    'selesai' => 'Gangguan telah selesai',
];
$error = '';
$success = isset($_GET['saved']) && $_GET['saved'] === '1'
    ? 'Notifikasi berhasil disimpan.'
    : (isset($_GET['deleted']) && $_GET['deleted'] === '1'
        ? 'Notifikasi berhasil dihapus.'
        : '');
$areas = [];
$customers = [];
$customerNames = [];
$incidents = [];
$customerNotifications = [];
$unreadCustomerNotifications = 0;
$editing = null;

try {
    $notificationTablesAvailable = yesnetNotificationTablesAvailable($conn);
} catch (Throwable $e) {
    error_log('Admin notification schema check failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Skema notifikasi belum dapat diperiksa. Coba kembali beberapa saat lagi.');
}

if (!$notificationTablesAvailable) {
    http_response_code(503);
    exit('Fitur notifikasi belum tersedia. Terapkan database/migrasi_notifikasi_yesnet.sql terlebih dahulu.');
}

if (
    yesnetNotificationColumnExists($conn, 'network_areas', 'id')
    && yesnetNotificationColumnExists($conn, 'network_areas', 'nama_area')
) {
    $areaResult = $conn->query('SELECT id, nama_area FROM network_areas ORDER BY nama_area');
    $areas = $areaResult->fetch_all(MYSQLI_ASSOC);
}
$areaNames = [];
foreach ($areas as $area) {
    $areaNames[(int) $area['id']] = (string) $area['nama_area'];
}

if (
    yesnetNotificationColumnExists($conn, 'customers', 'id')
    && yesnetNotificationColumnExists($conn, 'customers', 'user_id')
    && yesnetNotificationColumnExists($conn, 'customers', 'nama')
) {
    $customerResult = $conn->query(
        'SELECT id, nama FROM customers WHERE user_id IS NOT NULL AND user_id > 0 ORDER BY nama'
    );
    $customers = $customerResult->fetch_all(MYSQLI_ASSOC);
}
foreach ($customers as $customer) {
    $customerNames[(int) $customer['id']] = (string) $customer['nama'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $incidentId = filter_var($_POST['incident_id'] ?? '0', FILTER_VALIDATE_INT);
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        if (!adminVerifyCsrf($token)) {
            $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        } elseif ($incidentId === false || $incidentId === null || $incidentId < 1) {
            $error = 'Notifikasi yang akan dihapus tidak valid.';
        } else {
            try {
                $stmt = $conn->prepare('DELETE FROM yesnet_network_incidents WHERE id = ?');
                $stmt->bind_param('i', $incidentId);
                $stmt->execute();
                $deleted = $stmt->affected_rows > 0;
                $stmt->close();
                if (!$deleted) {
                    throw new RuntimeException('Notifikasi gangguan tidak ditemukan.');
                }
                header('Location: notifications.php?deleted=1');
                exit;
            } catch (Throwable $e) {
                error_log('Admin network notification delete failed: ' . $e->getMessage());
                $error = 'Notifikasi gagal dihapus.';
            }
        }
    } else {
    $title = trim((string) ($_POST['title'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $type = (string) ($_POST['type'] ?? '');
    $areaId = filter_var($_POST['area_id'] ?? '0', FILTER_VALIDATE_INT);
    $targetCustomerId = filter_var($_POST['target_customer_id'] ?? '0', FILTER_VALIDATE_INT);
    $startsAtRaw = (string) ($_POST['starts_at'] ?? '');
    $estimateRaw = (string) ($_POST['estimate'] ?? '');
    $active = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
    $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsAtRaw);
    $estimate = $estimateRaw === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $estimateRaw);

    if (!adminVerifyCsrf($token)) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (
        $incidentId === false || $incidentId === null || $incidentId < 0 ||
        !isset($types[$type]) ||
        adminNotificationLength($title) < 3 || adminNotificationLength($title) > 160 ||
        $message === '' || adminNotificationLength($message) > 5000 ||
        $areaId === false || $areaId < 0 ||
        ($areaId > 0 && !isset($areaNames[$areaId])) ||
        $targetCustomerId === false || $targetCustomerId < 0 ||
        ($targetCustomerId > 0 && !isset($customerNames[$targetCustomerId])) ||
        ($areaId > 0 && $targetCustomerId > 0) ||
        !$startsAt || $startsAt->format('Y-m-d\TH:i') !== $startsAtRaw ||
        ($estimateRaw !== '' && (!$estimate || $estimate->format('Y-m-d\TH:i') !== $estimateRaw)) ||
        ($estimate !== null && $startsAt !== false && $estimate < $startsAt)
    ) {
        $error = 'Periksa kembali judul, pesan, wilayah, jenis, dan waktu notifikasi.';
    } elseif ($targetCustomerId > 0 && !yesnetNotificationColumnExists($conn, 'yesnet_network_incidents', 'customer_id')) {
        $error = 'Penargetan customer belum tersedia. Terapkan migration notifikasi terlebih dahulu.';
    } elseif ($areaId > 0 && !yesnetNotificationColumnExists($conn, 'customers', 'network_area_id')
        && !yesnetNotificationColumnExists($conn, 'customers', 'area_id')) {
        $error = 'Penargetan wilayah belum tersedia karena relasi area pelanggan belum dikonfigurasi.';
    } elseif (!yesnetNotificationColumnExists($conn, 'customers', 'user_id')
        || !yesnetNotificationColumnExists($conn, 'customers', 'id')) {
        $error = 'Data akun pelanggan belum memiliki relasi yang diperlukan untuk pengiriman notifikasi.';
    } else {
        $areaName = $targetCustomerId > 0
            ? 'Pelanggan #' . $targetCustomerId
            : ($areaId > 0 ? $areaNames[$areaId] : 'Semua wilayah');
        $adminId = (int) $_SESSION['user_id'];
        $startSql = $startsAt->format('Y-m-d H:i:s');
        $estimateSql = $estimate ? $estimate->format('Y-m-d H:i:s') : null;
        $notificationMessage = $message . "\nWaktu mulai: " . $startsAt->format('d M Y, H:i') . ' WIB';
        if ($estimate !== null) {
            $notificationMessage .= "\nEstimasi pemulihan: " . $estimate->format('d M Y, H:i') . ' WIB';
        }
        $areaColumn = yesnetNotificationColumnExists($conn, 'customers', 'network_area_id')
            ? 'network_area_id'
            : (yesnetNotificationColumnExists($conn, 'customers', 'area_id') ? 'area_id' : null);

        try {
            $conn->begin_transaction();
            if ($incidentId > 0) {
                $existsStmt = $conn->prepare('SELECT id FROM yesnet_network_incidents WHERE id = ? FOR UPDATE');
                $existsStmt->bind_param('i', $incidentId);
                $existsStmt->execute();
                $incidentExists = $existsStmt->get_result()->fetch_assoc();
                $existsStmt->close();
                if (!$incidentExists) {
                    throw new RuntimeException('Notifikasi gangguan tidak ditemukan.');
                }
                $stmt = $conn->prepare(
                    'UPDATE yesnet_network_incidents
                     SET judul = ?, message = ?, type = ?, area_id = NULLIF(?, 0),
                         customer_id = NULLIF(?, 0), area_name = ?,
                         mulai = ?, estimasi_selesai = ?, is_active = ?, version = version + 1,
                         updated_by = ?
                     WHERE id = ?'
                );
                $stmt->bind_param(
                    'sssiisssiii',
                    $title,
                    $message,
                    $type,
                    $areaId,
                    $targetCustomerId,
                    $areaName,
                    $startSql,
                    $estimateSql,
                    $active,
                    $adminId,
                    $incidentId
                );
                $stmt->execute();
                if ($stmt->affected_rows < 0) {
                    throw new RuntimeException('Perubahan insiden tidak dapat dikonfirmasi.');
                }
                $stmt->close();
                $savedId = $incidentId;
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO yesnet_network_incidents
                        (judul, message, type, area_id, customer_id, area_name, mulai, estimasi_selesai,
                         is_active, created_by, updated_by)
                     VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'sssiisssiii',
                    $title,
                    $message,
                    $type,
                    $areaId,
                    $targetCustomerId,
                    $areaName,
                    $startSql,
                    $estimateSql,
                    $active,
                    $adminId,
                    $adminId
                );
                $stmt->execute();
                $savedId = (int) $conn->insert_id;
                $stmt->close();
            }

            if ($active === 1 && $startsAt <= new DateTimeImmutable('now')) {
                $stmt = $conn->prepare('SELECT version FROM yesnet_network_incidents WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $savedId);
                $stmt->execute();
                $version = (int) $stmt->get_result()->fetch_assoc()['version'];
                $stmt->close();

                if ($targetCustomerId > 0) {
                    $recipientStmt = $conn->prepare(
                        'SELECT id, user_id FROM customers WHERE id = ? AND user_id IS NOT NULL AND user_id > 0'
                    );
                    $recipientStmt->bind_param('i', $targetCustomerId);
                } else {
                    $selectArea = $areaColumn !== null ? ', `' . $areaColumn . '` AS area_id' : '';
                    $sql = 'SELECT id, user_id' . $selectArea . ' FROM customers WHERE user_id IS NOT NULL AND user_id > 0';
                    if ($areaId > 0) {
                        $sql .= ' AND `' . $areaColumn . '` = ?';
                    }
                    $recipientStmt = $conn->prepare($sql);
                    if ($areaId > 0) {
                        $recipientStmt->bind_param('i', $areaId);
                    }
                }
                $recipientStmt->execute();
                $recipients = $recipientStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $recipientStmt->close();

                $dedupeKey = 'incident:' . $savedId . ':version:' . $version;
                $noticeStmt = $conn->prepare(
                    'INSERT IGNORE INTO yesnet_customer_notifications
                        (user_id, customer_id, judul, message, type, action_url, dedupe_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $actionUrl = 'notifikasi.php';
                foreach ($recipients as $recipient) {
                    $recipientUserId = (int) $recipient['user_id'];
                    $recipientCustomerId = (int) $recipient['id'];
                    $noticeStmt->bind_param(
                        'iisssss',
                        $recipientUserId,
                        $recipientCustomerId,
                        $title,
                        $notificationMessage,
                        $type,
                        $actionUrl,
                        $dedupeKey
                    );
                    $noticeStmt->execute();
                }
                $noticeStmt->close();
            }
            $conn->commit();
            header('Location: notifications.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Admin network notification save failed: ' . $e->getMessage());
            $error = 'Notifikasi gagal disimpan. Periksa koneksi database dan struktur tabel pelanggan.';
        }
    }
    }
}

if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) && (int) $_GET['edit'] > 0) {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM yesnet_network_incidents WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$result = $conn->query('SELECT * FROM yesnet_network_incidents ORDER BY created_at DESC, id DESC LIMIT 100');
$incidents = $result->fetch_all(MYSQLI_ASSOC);
$unreadCustomerNotifications = (int) $conn->query(
    'SELECT COUNT(*) AS total
     FROM yesnet_customer_notifications n
     LEFT JOIN yesnet_customer_notification_reads r
       ON r.notification_id = n.id AND r.user_id = n.user_id
     WHERE r.notification_id IS NULL'
)->fetch_assoc()['total'];
$notificationResult = $conn->query(
    'SELECT n.id, n.customer_id, n.judul, n.message, n.type, n.created_at,
            c.nama AS customer_name,
            IF(r.notification_id IS NULL, 0, 1) AS dibaca
     FROM yesnet_customer_notifications n
     LEFT JOIN customers c ON c.id = n.customer_id
     LEFT JOIN yesnet_customer_notification_reads r
       ON r.notification_id = n.id AND r.user_id = n.user_id
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 100'
);
$customerNotifications = $notificationResult->fetch_all(MYSQLI_ASSOC);
$formIncident = $editing ?: [
    'id' => 0,
    'judul' => '',
    'message' => '',
    'type' => 'gangguan',
    'area_id' => null,
    'customer_id' => null,
    'mulai' => date('Y-m-d H:i:s'),
    'estimasi_selesai' => '',
    'is_active' => 0,
];
$formatInputDate = static function (mixed $value): string {
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifikasi Layanan - YESNET Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
    <style>
        .notice-grid{display:grid;grid-template-columns:minmax(300px,.8fr) minmax(0,1.4fr);gap:17px}
        .notice-form{padding:21px}.notice-form .form-label{font-size:11px;font-weight:700;color:#58677c}
        .notice-form .form-control,.notice-form .form-select{font-size:12px}
        .notice-list{padding:18px}.notice-item{display:flex;gap:12px;padding:15px 0;border-bottom:1px solid #eef1f5}
        .notice-item:last-child{border-bottom:0}.notice-symbol{display:grid;flex:0 0 38px;height:38px;place-items:center;border-radius:11px;background:#fff2e9;color:#db7827}
        .notice-copy{min-width:0;flex:1}.notice-copy h3{margin:0 0 4px;font-size:13px;font-weight:750}
        .notice-copy p{margin:0;color:#6b788b;font-size:11px;white-space:pre-line}
        .notice-meta{margin-top:7px;color:#8591a2;font-size:10px}.notice-controls{display:flex;align-items:flex-start;gap:8px}
        .notice-controls .btn{font-size:10px}.notice-inactive{opacity:.62}
        @media(max-width:900px){.notice-grid{grid-template-columns:1fr}}
        @media(max-width:760px){.notice-item{flex-wrap:wrap}.notice-controls{margin-left:50px}.notice-list{padding:14px}}
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
            <a href="complaints.php"><i class="bi bi-headset"></i> Pengaduan</a>
            <a href="overview.php?section=network"><i class="bi bi-router"></i> Area Jaringan</a>
            <a class="active" href="notifications.php"><i class="bi bi-bell"></i> Notifikasi Layanan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="admin-profile"><span class="profile-avatar"><?= adminNotificationEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span>
                <span><strong><?= adminNotificationEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span></div>
            <a class="logout-link" href="logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">PUSAT INFORMASI</span><h1>Notifikasi layanan</h1></div>
            <div class="topbar-meta"><a class="panel-link" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a></div>
        </header>
        <section class="page-content">
            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= adminNotificationEscape($error) ?></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= adminNotificationEscape($success) ?></div><?php endif; ?>
            <div class="welcome-row">
                <div><h2>Gangguan internet</h2><p>Buat dan kelola pengumuman untuk seluruh pelanggan, wilayah tertentu, atau satu customer.</p></div>
            </div>
            <div class="notice-grid">
                <section class="panel notice-form">
                    <h3 class="h6 fw-bold mb-3"><?= !empty($formIncident['id']) ? 'Edit notifikasi' : 'Buat notifikasi'; ?></h3>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= adminNotificationEscape(adminCsrfToken()) ?>">
                        <input type="hidden" name="incident_id" value="<?= (int) $formIncident['id'] ?>">
                        <div class="mb-3"><label class="form-label" for="title">Judul</label>
                            <input class="form-control" id="title" name="title" maxlength="160" required value="<?= adminNotificationEscape($formIncident['judul']) ?>"></div>
                        <div class="mb-3"><label class="form-label" for="message">Pesan</label>
                            <textarea class="form-control" id="message" name="message" rows="4" maxlength="5000" required><?= adminNotificationEscape($formIncident['message']) ?></textarea></div>
                        <div class="mb-3"><label class="form-label" for="type">Jenis notifikasi</label>
                            <select class="form-select" id="type" name="type" required>
                                <?php foreach ($types as $value => $label): ?>
                                    <option value="<?= adminNotificationEscape($value) ?>" <?= $formIncident['type'] === $value ? 'selected' : '' ?>><?= adminNotificationEscape($label) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="mb-3"><label class="form-label" for="area_id">Wilayah terdampak</label>
                            <select class="form-select" id="area_id" name="area_id">
                                <option value="0">Semua wilayah</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= (int) $area['id'] ?>" <?= (int) ($formIncident['area_id'] ?? 0) === (int) $area['id'] ? 'selected' : '' ?>><?= adminNotificationEscape($area['nama_area']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label" for="target_customer_id">Customer tertentu (opsional)</label>
                            <select class="form-select" id="target_customer_id" name="target_customer_id">
                                <option value="0">Tidak ada - gunakan pilihan wilayah</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int) $customer['id'] ?>" <?= (int) ($formIncident['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>>
                                        <?= adminNotificationEscape($customer['nama']) ?> (#<?= (int) $customer['id'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($customers === []): ?><small class="text-muted">Belum ada customer dengan akun terhubung.</small><?php endif; ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-12 col-md-6 mb-3"><label class="form-label" for="starts_at">Waktu mulai</label>
                                <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" required value="<?= adminNotificationEscape($formatInputDate($formIncident['mulai'])) ?>"></div>
                            <div class="col-12 col-md-6 mb-3"><label class="form-label" for="estimate">Estimasi pemulihan</label>
                                <input class="form-control" type="datetime-local" id="estimate" name="estimate" value="<?= adminNotificationEscape($formatInputDate($formIncident['estimasi_selesai'])) ?>"></div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $formIncident['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="is_active">Aktifkan dan kirim notifikasi</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>
                            <?php if (!empty($formIncident['id'])): ?><a class="btn btn-outline-secondary btn-sm" href="notifications.php">Batal edit</a><?php endif; ?>
                        </div>
                    </form>
                </section>
                <section class="panel notice-list">
                    <div class="panel-heading"><div><h3>Riwayat notifikasi</h3><p><?= count($incidents) ?> notifikasi terbaru</p></div><i class="bi bi-bell text-primary"></i></div>
                    <?php if ($incidents === []): ?><div class="empty-state">Belum ada notifikasi gangguan.</div><?php endif; ?>
                    <?php foreach ($incidents as $incident): ?>
                        <?php $incidentType = $types[$incident['type']] ?? 'Gangguan jaringan'; ?>
                        <article class="notice-item <?= (int) $incident['is_active'] === 0 ? 'notice-inactive' : '' ?>">
                            <span class="notice-symbol"><i class="bi <?= $incident['type'] === 'selesai' ? 'bi-check-circle-fill' : 'bi-wifi-off' ?>"></i></span>
                            <div class="notice-copy">
                                <h3><?= adminNotificationEscape($incident['judul']) ?></h3>
                                <p><?= adminNotificationEscape($incident['message']) ?></p>
                                <?php
                                $incidentTarget = !empty($incident['customer_id'])
                                    ? ($customerNames[(int) $incident['customer_id']] ?? 'Pelanggan #' . (int) $incident['customer_id'])
                                    : $incident['area_name'];
                                ?>
                                <div class="notice-meta"><?= adminNotificationEscape($incidentType) ?> · <?= adminNotificationEscape($incidentTarget) ?>
                                    · Mulai <?= adminNotificationEscape(date('d M Y, H:i', strtotime($incident['mulai']))) ?>
                                    <?php if (!empty($incident['estimasi_selesai'])): ?> · Estimasi <?= adminNotificationEscape(date('d M Y, H:i', strtotime($incident['estimasi_selesai']))) ?><?php endif; ?>
                                    · <?= (int) $incident['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                </div>
                            </div>
                            <div class="notice-controls">
                                <a class="btn btn-outline-primary btn-sm" href="notifications.php?edit=<?= (int) $incident['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
                                <form method="post" onsubmit="return confirm('Hapus pengumuman ini? Notifikasi yang sudah terkirim tetap tersimpan pada customer.');">
                                    <input type="hidden" name="csrf" value="<?= adminNotificationEscape(adminCsrfToken()) ?>">
                                    <input type="hidden" name="incident_id" value="<?= (int) $incident['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash"></i> Hapus</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            </div>
            <section class="panel notice-list mt-3">
                <div class="panel-heading">
                    <div><h3>Notifikasi customer</h3><p><?= count($customerNotifications) ?> terbaru · <?= $unreadCustomerNotifications ?> belum dibaca</p></div>
                    <i class="bi bi-people text-primary"></i>
                </div>
                <?php if ($customerNotifications === []): ?>
                    <div class="empty-state">Belum ada notifikasi customer.</div>
                <?php endif; ?>
                <?php foreach ($customerNotifications as $customerNotification): ?>
                    <article class="notice-item <?= (int) $customerNotification['dibaca'] === 0 ? 'notice-inactive' : '' ?>">
                        <span class="notice-symbol"><i class="bi <?= $customerNotification['type'] === 'pembayaran' ? 'bi-credit-card-fill' : 'bi-bell-fill' ?>"></i></span>
                        <div class="notice-copy">
                            <h3><?= adminNotificationEscape($customerNotification['judul']) ?></h3>
                            <p><?= adminNotificationEscape($customerNotification['message']) ?></p>
                            <div class="notice-meta">
                                Customer: <?= adminNotificationEscape($customerNotification['customer_name'] ?: 'Customer #' . (int) $customerNotification['customer_id']) ?>
                                · <?= adminNotificationEscape($customerNotification['type']) ?>
                                · <?= adminNotificationEscape(date('d M Y, H:i', strtotime($customerNotification['created_at']))) ?>
                                · <?= (int) $customerNotification['dibaca'] === 1 ? 'Sudah dibaca' : 'Belum dibaca' ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
            <footer class="page-footer">YESNET Internal Operations <span>·</span> <?= date('Y') ?></footer>
        </section>
    </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
