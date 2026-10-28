<?php

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';

$sections = [
    'customers' => [
        'title' => 'Pelanggan',
        'columns' => ['Nama', 'Telepon', 'Email', 'Paket', 'Status'],
        'query' => "
            SELECT c.nama, c.telephone, c.email, p.nama_paket, c.status_langganan
            FROM customers c
            LEFT JOIN paket_wifi p ON p.id = c.paket_id
            ORDER BY c.id DESC
            LIMIT 500
        ",
    ],
    'packages' => [
        'title' => 'Paket WiFi',
        'columns' => ['Nama Paket', 'Kecepatan (Mbps)', 'Harga', 'Status'],
        'query' => "
            SELECT nama_paket, speed_mbps, harga, status
            FROM paket_wifi
            ORDER BY harga ASC
            LIMIT 500
        ",
    ],
    'invoices' => [
        'title' => 'Tagihan',
        'columns' => ['Nomor Tagihan', 'Pelanggan', 'Jumlah', 'Jatuh Tempo', 'Status'],
        'query' => "
            SELECT t.nomor_tagihan, c.nama, t.jumlah, t.jatuh_tempo, t.status
            FROM tagihan t
            LEFT JOIN customers c ON c.id = t.id_customer
            ORDER BY t.id DESC
            LIMIT 500
        ",
    ],
    'payments' => [
        'title' => 'Pembayaran',
        'columns' => ['Nomor Pembayaran', 'Pelanggan', 'Jumlah', 'Provider', 'Invoice Xendit', 'Metode', 'Status', 'Tanggal'],
        'query' => "
            SELECT p.nomor_pembayaran, c.nama, p.jumlah, COALESCE(p.payment_provider, 'legacy'),
                   p.xendit_invoice_id, p.metode_pembayaran, p.status, p.tanggal_bayar
            FROM pembayaran p
            LEFT JOIN customers c ON c.id = p.id_customer
            ORDER BY p.id DESC
            LIMIT 500
        ",
    ],
    'technicians' => [
        'title' => 'Teknisi',
        'columns' => ['Nama', 'Status'],
        'query' => "
            SELECT nama, status
            FROM technicians
            ORDER BY id DESC
            LIMIT 500
        ",
    ],
    'network' => [
        'title' => 'Area Jaringan',
        'columns' => ['Area', 'Status'],
        'query' => "
            SELECT nama_area, status
            FROM network_areas
            ORDER BY nama_area
            LIMIT 500
        ",
    ],
];

$sectionKey = (string) ($_GET['section'] ?? '');
if (!isset($sections[$sectionKey])) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$section = $sections[$sectionKey];

try {
    $result = $conn->query($section['query']);
} catch (mysqli_sql_exception $e) {
    error_log('Admin overview query failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data tidak dapat dimuat saat ini. Silakan coba kembali nanti.');
}

function adminOverviewEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= adminOverviewEscape($section['title']) ?> - YesNet Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?= adminOverviewEscape($section['title']) ?></h1>
        <div class="d-flex gap-2">
            <?php if ($sectionKey === 'packages'): ?>
                <a href="admin/Paket/index.php" class="btn btn-primary">Kelola Paket</a>
            <?php endif; ?>
            <?php if ($sectionKey === 'network'): ?>
                <a href="admin/jaringan/gangguan.php" class="btn btn-primary">Catat Gangguan</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-outline-secondary">Kembali ke Dashboard</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead><tr>
                <?php foreach ($section['columns'] as $column): ?>
                    <th scope="col"><?= adminOverviewEscape($column) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php if ($result->num_rows === 0): ?>
                    <tr><td colspan="<?= count($section['columns']) ?>" class="text-center">Belum ada data.</td></tr>
                <?php else: ?>
                    <?php while ($row = $result->fetch_row()): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?= adminOverviewEscape($value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
