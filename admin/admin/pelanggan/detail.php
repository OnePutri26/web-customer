<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../../config/database.php';

function customerDetailEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

$customerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$customerId || $customerId < 1) {
    http_response_code(400);
    exit('ID pelanggan tidak valid.');
}

try {
    $stmt = $conn->prepare("
        SELECT c.id, c.nama, c.telephone, c.email, c.alamat, c.status_langganan,
               p.nama_paket, p.speed_mbps, p.harga
        FROM customers c
        LEFT JOIN paket_wifi p ON p.id = c.paket_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        http_response_code(404);
        exit('Pelanggan tidak ditemukan.');
    }

    $stmt = $conn->prepare("
        SELECT nomor_tagihan, jumlah, jatuh_tempo, status
        FROM tagihan
        WHERE id_customer = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $invoices = $stmt->get_result();

    $stmt = $conn->prepare("
        SELECT nomor_pembayaran, jumlah, metode_pembayaran, status, tanggal_bayar
        FROM pembayaran
        WHERE id_customer = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $payments = $stmt->get_result();
} catch (mysqli_sql_exception $e) {
    error_log('Admin customer detail query failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Detail pelanggan belum dapat dimuat. Silakan coba kembali.');
}

function customerDetailMoney(mixed $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Pelanggan - YESNET Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../../assets/admin.css" rel="stylesheet">
    <style>
        .detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px; }
        .detail-item { padding:14px; border:1px solid #edf0f5; border-radius:9px; }
        .detail-item span { display:block; margin-bottom:5px; color:#7a8799; font-size:10px; text-transform:uppercase; }
        .detail-item strong { color:#263750; font-size:13px; overflow-wrap:anywhere; }
        .detail-panel { margin-bottom:16px; padding:20px; }
        .detail-panel h2 { margin:0 0 15px; font-size:14px; font-weight:750; }
        .detail-section { margin:20px 0 10px; font-size:15px; font-weight:750; }
        @media(max-width:600px) { .detail-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand" href="../../dashboard.php"><span class="brand-mark">Y</span><span>YESNET <small>OPERATIONS</small></span></a>
        <div class="nav-caption">MENU UTAMA</div>
        <nav class="admin-nav" aria-label="Navigasi admin">
            <a href="../../dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
            <a class="active" href="../../overview.php?section=customers"><i class="bi bi-people"></i> Pelanggan</a>
            <a href="../../overview.php?section=invoices"><i class="bi bi-receipt"></i> Tagihan</a>
            <a href="../../overview.php?section=payments"><i class="bi bi-credit-card"></i> Pembayaran</a>
            <a href="../Paket/index.php"><i class="bi bi-box-seam"></i> Paket Internet</a>
            <a href="../../complaints.php"><i class="bi bi-headset"></i> Pengaduan</a>
            <a href="../../coverage_requests.php"><i class="bi bi-geo-alt"></i> Coverage</a>
            <a href="../../overview.php?section=technicians"><i class="bi bi-person-gear"></i> Teknisi</a>
            <a href="../../overview.php?section=network"><i class="bi bi-router"></i> Area Jaringan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="admin-profile"><span class="profile-avatar"><?= customerDetailEscape(strtoupper(substr((string) ($_SESSION['nama'] ?? 'A'), 0, 1))) ?></span><span><strong><?= customerDetailEscape($_SESSION['nama'] ?? 'Administrator') ?></strong><small>Administrator</small></span></div>
            <a class="logout-link" href="../../logout.php"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="topbar">
            <button class="sidebar-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div><span class="eyebrow">MANAJEMEN PELANGGAN</span><h1>Detail pelanggan</h1></div>
            <div class="topbar-meta"><span class="live-dot"></span> Data operasional</div>
        </header>
        <section class="page-content">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div><h2 class="h5 mb-1"><?= customerDetailEscape($customer['nama']) ?></h2><p class="text-secondary small mb-0">ID pelanggan #<?= (int) $customer['id'] ?></p></div>
                <a class="btn btn-outline-secondary btn-sm" href="../../overview.php?section=customers"><i class="bi bi-arrow-left me-1"></i>Kembali ke pelanggan</a>
            </div>
            <section class="panel detail-panel">
                <h2>Informasi layanan</h2>
                <div class="detail-grid">
                    <div class="detail-item"><span>Status langganan</span><strong><?= customerDetailEscape($customer['status_langganan']) ?></strong></div>
                    <div class="detail-item"><span>Paket</span><strong><?= customerDetailEscape($customer['nama_paket'] ?: 'Belum memilih') ?></strong></div>
                    <div class="detail-item"><span>Kecepatan</span><strong><?= customerDetailEscape($customer['speed_mbps'] !== null ? $customer['speed_mbps'] . ' Mbps' : '-') ?></strong></div>
                    <div class="detail-item"><span>Harga paket</span><strong><?= customerDetailEscape($customer['harga'] !== null ? customerDetailMoney($customer['harga']) : '-') ?></strong></div>
                </div>
            </section>
            <section class="panel detail-panel">
                <h2>Informasi kontak</h2>
                <div class="detail-grid">
                    <div class="detail-item"><span>Nama</span><strong><?= customerDetailEscape($customer['nama']) ?></strong></div>
                    <div class="detail-item"><span>Telepon</span><strong><?= customerDetailEscape($customer['telephone']) ?></strong></div>
                    <div class="detail-item"><span>Email</span><strong><?= customerDetailEscape($customer['email']) ?></strong></div>
                    <div class="detail-item"><span>Alamat</span><strong><?= nl2br(customerDetailEscape($customer['alamat'])) ?></strong></div>
                </div>
            </section>
            <h2 class="detail-section">Riwayat tagihan</h2>
            <section class="panel mb-3">
                <div class="table-responsive">
                    <table class="table table-hover admin-table align-middle mb-0">
                        <thead><tr><th>Nomor tagihan</th><th>Jumlah</th><th>Jatuh tempo</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if ($invoices->num_rows === 0): ?><tr><td class="empty-table" colspan="4">Belum ada riwayat tagihan.</td></tr>
                        <?php else: while ($invoice = $invoices->fetch_assoc()): ?>
                            <tr><td><?= customerDetailEscape($invoice['nomor_tagihan']) ?></td><td><?= customerDetailMoney($invoice['jumlah']) ?></td><td><?= customerDetailEscape($invoice['jatuh_tempo']) ?></td><td><?= customerDetailEscape($invoice['status']) ?></td></tr>
                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <h2 class="detail-section">Riwayat pembayaran</h2>
            <section class="panel">
                <div class="table-responsive">
                    <table class="table table-hover admin-table align-middle mb-0">
                        <thead><tr><th>Nomor pembayaran</th><th>Jumlah</th><th>Metode</th><th>Status</th><th>Tanggal</th></tr></thead>
                        <tbody>
                        <?php if ($payments->num_rows === 0): ?><tr><td class="empty-table" colspan="5">Belum ada riwayat pembayaran.</td></tr>
                        <?php else: while ($payment = $payments->fetch_assoc()): ?>
                            <tr><td><?= customerDetailEscape($payment['nomor_pembayaran']) ?></td><td><?= customerDetailMoney($payment['jumlah']) ?></td><td><?= customerDetailEscape($payment['metode_pembayaran']) ?></td><td><?= customerDetailEscape($payment['status']) ?></td><td><?= customerDetailEscape($payment['tanggal_bayar']) ?></td></tr>
                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
<script src="../../assets/admin.js"></script>
</body>
</html>
