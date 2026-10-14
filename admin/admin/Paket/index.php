<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../../config/database.php';

function paketAdminEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

try {
    $result = $conn->query("
        SELECT id, nama_paket, speed_mbps, harga, deskripsi, status
        FROM paket_wifi
        ORDER BY harga ASC, id ASC
    ");
} catch (mysqli_sql_exception $e) {
    error_log('Admin package list query failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data paket belum dapat dimuat. Silakan coba kembali nanti.');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Paket WiFi - YesNet Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h3 mb-0">Kelola Paket WiFi</h1>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="tambah.php">Tambah Paket</a>
            <a class="btn btn-outline-secondary" href="../../dashboard.php">Kembali ke Dashboard</a>
        </div>
    </header>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead>
            <tr>
                <th>Nama Paket</th>
                <th>Kecepatan</th>
                <th>Harga / Bulan</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result->num_rows === 0): ?>
                <tr><td colspan="5" class="text-center">Belum ada paket.</td></tr>
            <?php else: ?>
                <?php while ($package = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?= paketAdminEscape($package['nama_paket']) ?></strong>
                            <?php if (trim((string) ($package['deskripsi'] ?? '')) !== ''): ?>
                                <div class="small text-muted"><?= paketAdminEscape($package['deskripsi']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $package['speed_mbps'] ?> Mbps</td>
                        <td>Rp <?= number_format((float) $package['harga'], 0, ',', '.') ?></td>
                        <td><?= paketAdminEscape($package['status'] ?: 'Belum diatur') ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int) $package['id'] ?>">Ubah</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
