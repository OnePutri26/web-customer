<?php

require_once __DIR__ . '/auth_check.php';

function coverageAdminEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['coverage_admin_csrf'])) {
    $_SESSION['coverage_admin_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $token = (string) ($_POST['csrf'] ?? '');

    if (!hash_equals((string) $_SESSION['coverage_admin_csrf'], $token)) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!$requestId || cov_len_admin($note) > 500) {
        $error = 'Permintaan perubahan tidak valid.';
    } else {
        $adminId = (int) $_SESSION['user_id'];
        $transition = match ($action) {
            'evaluate' => [
                "UPDATE coverage_expansion_requests
                 SET status = 'evaluasi', reviewed_by = ?, catatan_admin = ?
                 WHERE id = ? AND status = 'menunggu'",
                'isi',
            ],
            'survey' => [
                "UPDATE coverage_expansion_requests
                 SET status = 'survei', reviewed_by = ?, catatan_admin = ?
                 WHERE id = ? AND status = 'evaluasi'",
                'isi',
            ],
            'network_ready' => [
                "UPDATE coverage_expansion_requests
                 SET status = 'tersedia', network_available = 1, network_ready_at = NOW(),
                     reviewed_by = ?, catatan_admin = ?
                 WHERE id = ? AND status = 'disetujui' AND network_available = 0",
                'isi',
            ],
            default => null,
        };

        if ($transition === null) {
            $error = 'Aksi status tidak dikenal.';
        } else {
            try {
                $stmt = $conn->prepare($transition[0]);
                $stmt->bind_param($transition[1], $adminId, $note, $requestId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed !== 1) {
                    $error = 'Status pengajuan sudah berubah atau tidak sesuai tahap alur.';
                } else {
                    $success = 'Status pengajuan berhasil diperbarui.';
                }
            } catch (Throwable $e) {
                error_log('Admin coverage request update failed: ' . $e->getMessage());
                $error = 'Status pengajuan gagal diperbarui. Periksa log server jika masalah berlanjut.';
            }
        }
    }
}

function cov_len_admin(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? 0 : $length;
}

try {
    $requests = $conn->query("
        SELECT id, nomor_pengajuan, nama, telepon, email, alamat, latitude, longitude,
               catatan, catatan_admin, catatan_teknisi, status, network_available,
               created_at, surveyed_at, network_ready_at
        FROM coverage_expansion_requests
        ORDER BY FIELD(status, 'menunggu', 'evaluasi', 'survei', 'disetujui', 'ditolak', 'tersedia'), id DESC
        LIMIT 500
    ");
} catch (Throwable $e) {
    error_log('Admin coverage request list failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data pengajuan perluasan belum dapat dimuat. Pastikan migrasi coverage sudah dijalankan.');
}

$statusLabels = [
    'menunggu' => 'Menunggu evaluasi',
    'evaluasi' => 'Evaluasi admin',
    'survei' => 'Survei teknisi',
    'disetujui' => 'Disetujui, jaringan belum tersedia',
    'ditolak' => 'Ditolak',
    'tersedia' => 'Jaringan tersedia',
    'selesai' => 'Selesai (legacy)',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Perluasan Coverage - YesNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h3 mb-0">Pengajuan Perluasan Coverage</h1>
        <a class="btn btn-outline-secondary" href="dashboard.php">Kembali ke Dashboard</a>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= coverageAdminEscape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= coverageAdminEscape($success) ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead><tr>
                <th>Nomor / Diajukan</th><th>Customer</th><th>Alamat / Lokasi</th>
                <th>Status</th><th>Catatan survei</th><th>Perubahan status</th>
            </tr></thead>
            <tbody>
            <?php if ($requests->num_rows === 0): ?>
                <tr><td colspan="6" class="text-center">Belum ada permintaan perluasan.</td></tr>
            <?php else: ?>
                <?php while ($request = $requests->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?= coverageAdminEscape($request['nomor_pengajuan']) ?></strong><br>
                            <small><?= coverageAdminEscape($request['created_at']) ?></small>
                        </td>
                        <td>
                            <?= coverageAdminEscape($request['nama']) ?><br>
                            <a href="tel:<?= coverageAdminEscape($request['telepon']) ?>"><?= coverageAdminEscape($request['telepon']) ?></a><br>
                            <?= coverageAdminEscape($request['email'] ?: '-') ?>
                        </td>
                        <td>
                            <?= nl2br(coverageAdminEscape($request['alamat'])) ?><br>
                            <small>GPS: <?= coverageAdminEscape($request['latitude'] ?? '-') ?>, <?= coverageAdminEscape($request['longitude'] ?? '-') ?></small><br>
                            <small>Catatan customer: <?= coverageAdminEscape($request['catatan'] ?: '-') ?></small>
                        </td>
                        <td><?= coverageAdminEscape($statusLabels[$request['status']] ?? $request['status']) ?></td>
                        <td><?= nl2br(coverageAdminEscape($request['catatan_teknisi'] ?: '-')) ?></td>
                        <td style="min-width:250px">
                            <?php if (in_array($request['status'], ['menunggu', 'evaluasi', 'disetujui'], true)): ?>
                                <form method="post" class="d-grid gap-2">
                                    <input type="hidden" name="csrf" value="<?= coverageAdminEscape($_SESSION['coverage_admin_csrf']) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <textarea class="form-control" name="note" maxlength="500" rows="2" placeholder="Catatan admin (opsional)"></textarea>
                                    <?php if ($request['status'] === 'menunggu'): ?>
                                        <button class="btn btn-sm btn-primary" name="action" value="evaluate">Mulai evaluasi</button>
                                    <?php elseif ($request['status'] === 'evaluasi'): ?>
                                        <button class="btn btn-sm btn-primary" name="action" value="survey">Teruskan ke survei teknisi</button>
                                    <?php elseif (empty($request['network_available'])): ?>
                                        <button class="btn btn-sm btn-success" name="action" value="network_ready">Tandai jaringan sudah tersedia</button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span class="text-secondary">Tidak ada aksi admin pada tahap ini.</span>
                            <?php endif; ?>
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
