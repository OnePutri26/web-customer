<?php

require_once __DIR__ . '/auth_check.php';

function coverageAdminEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function coverageAdminLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

$statuses = [
    'menunggu' => 'Menunggu',
    'perlu_survei' => 'Perlu survei',
    'dalam_perencanaan' => 'Dalam perencanaan',
    'terjadwal' => 'Terjadwal',
    'disetujui' => 'Disetujui',
    'ditolak' => 'Ditolak',
];

if (empty($_SESSION['coverage_admin_csrf'])) {
    $_SESSION['coverage_admin_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $status = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $token = (string) ($_POST['csrf'] ?? '');
    $noteLength = coverageAdminLength($note);
    $networkAvailable = (
        $status === 'disetujui'
        && (string) ($_POST['network_available'] ?? '') === '1'
    ) ? 1 : 0;

    if (!hash_equals((string) $_SESSION['coverage_admin_csrf'], $token)) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!$requestId || !isset($statuses[$status]) || $noteLength > 1000) {
        $error = 'Status atau catatan tindak lanjut tidak valid.';
    } else {
        $adminId = (int) $_SESSION['user_id'];
        try {
            $stmt = $conn->prepare("
                UPDATE coverage_expansion_requests
                SET status = ?, catatan_admin = ?, reviewed_by = ?,
                    network_available = ?,
                    network_ready_at = IF(? = 1, COALESCE(network_ready_at, NOW()), NULL)
                WHERE id = ?
            ");
            $stmt->bind_param('ssiiii', $status, $note, $adminId, $networkAvailable, $networkAvailable, $requestId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed < 0) {
                $error = 'Perubahan status belum dapat dikonfirmasi.';
            } else {
                $success = 'Status dan catatan tindak lanjut berhasil disimpan.';
            }
        } catch (Throwable $e) {
            error_log('Admin coverage request update failed: ' . $e->getMessage());
            $error = 'Perubahan gagal disimpan karena layanan sedang mengalami gangguan.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
if (!isset($statuses[$statusFilter])) {
    $statusFilter = '';
}
if (coverageAdminLength($search) > 100) {
    $search = substr($search, 0, 100);
}

try {
    $searchLike = '%' . $search . '%';
    if ($search !== '' && $statusFilter !== '') {
        $stmt = $conn->prepare("
            SELECT id, nomor_pengajuan, nama, telepon, alamat, kelurahan, kecamatan,
                   patokan, latitude, longitude, maps_url, catatan, catatan_admin,
                   catatan_teknisi, status, created_at, updated_at
            FROM coverage_expansion_requests
            WHERE (nama LIKE ? OR telepon LIKE ? OR alamat LIKE ?)
              AND status = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 500
        ");
        $stmt->bind_param('ssss', $searchLike, $searchLike, $searchLike, $statusFilter);
        $stmt->execute();
        $requests = $stmt->get_result();
    } elseif ($search !== '') {
        $stmt = $conn->prepare("
            SELECT id, nomor_pengajuan, nama, telepon, alamat, kelurahan, kecamatan,
                   patokan, latitude, longitude, maps_url, catatan, catatan_admin,
                   catatan_teknisi, status, created_at, updated_at
            FROM coverage_expansion_requests
            WHERE nama LIKE ? OR telepon LIKE ? OR alamat LIKE ?
            ORDER BY created_at DESC, id DESC
            LIMIT 500
        ");
        $stmt->bind_param('sss', $searchLike, $searchLike, $searchLike);
        $stmt->execute();
        $requests = $stmt->get_result();
    } elseif ($statusFilter !== '') {
        $stmt = $conn->prepare("
            SELECT id, nomor_pengajuan, nama, telepon, alamat, kelurahan, kecamatan,
                   patokan, latitude, longitude, maps_url, catatan, catatan_admin,
                   catatan_teknisi, status, created_at, updated_at
            FROM coverage_expansion_requests
            WHERE status = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 500
        ");
        $stmt->bind_param('s', $statusFilter);
        $stmt->execute();
        $requests = $stmt->get_result();
    } else {
        $requests = $conn->query("
            SELECT id, nomor_pengajuan, nama, telepon, alamat, kelurahan, kecamatan,
                   patokan, latitude, longitude, maps_url, catatan, catatan_admin,
                   catatan_teknisi, status, created_at, updated_at
            FROM coverage_expansion_requests
            ORDER BY created_at DESC, id DESC
            LIMIT 500
        ");
    }
} catch (Throwable $e) {
    error_log('Admin coverage request list failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Daftar permintaan coverage belum dapat dimuat. Periksa migrasi database dan coba kembali.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Perluasan Coverage - YesNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h3 mb-0">Permintaan Perluasan Coverage</h1>
        <a class="btn btn-outline-secondary" href="dashboard.php">Kembali ke Dashboard</a>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= coverageAdminEscape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= coverageAdminEscape($success) ?></div><?php endif; ?>

    <form method="get" class="row g-2 mb-4">
        <div class="col-12 col-md-6">
            <label class="form-label" for="q">Cari nama, nomor WhatsApp, atau alamat</label>
            <input class="form-control" id="q" name="q" maxlength="100" value="<?= coverageAdminEscape($search) ?>">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label" for="status-filter">Filter status</label>
            <select class="form-select" id="status-filter" name="status">
                <option value="">Semua status</option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= coverageAdminEscape($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= coverageAdminEscape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex align-items-end gap-2">
            <button class="btn btn-primary" type="submit">Cari</button>
            <a class="btn btn-outline-secondary" href="coverage_requests.php">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead><tr>
                <th>Nomor / Tanggal</th><th>Customer</th><th>Alamat dan lokasi</th>
                <th>Status</th><th>Catatan tindak lanjut</th><th>Ubah status</th>
            </tr></thead>
            <tbody>
            <?php if ($requests->num_rows === 0): ?>
                <tr><td colspan="6" class="text-center">Tidak ada permintaan yang cocok.</td></tr>
            <?php else: ?>
                <?php while ($request = $requests->fetch_assoc()): ?>
                    <?php
                    $mapLink = trim((string) ($request['maps_url'] ?? ''));
                    if ($mapLink === '' && $request['latitude'] !== null && $request['longitude'] !== null) {
                        $mapLink = 'https://www.google.com/maps?q=' . rawurlencode($request['latitude'] . ',' . $request['longitude']);
                    }
                    $mapHost = strtolower((string) parse_url($mapLink, PHP_URL_HOST));
                    $mapPath = (string) parse_url($mapLink, PHP_URL_PATH);
                    $safeMapLink = strtolower((string) parse_url($mapLink, PHP_URL_SCHEME)) === 'https'
                        && (
                            $mapHost === 'maps.app.goo.gl'
                            || (
                                ($mapHost === 'google.com' || str_ends_with($mapHost, '.google.com'))
                                && str_contains($mapPath, '/maps')
                            )
                            || ($mapHost === 'goo.gl' && str_starts_with($mapPath, '/maps'))
                        );
                    ?>
                    <tr>
                        <td>
                            <strong><?= coverageAdminEscape($request['nomor_pengajuan']) ?></strong><br>
                            <small>Diajukan: <?= coverageAdminEscape($request['created_at']) ?></small><br>
                            <small>Diperbarui: <?= coverageAdminEscape($request['updated_at']) ?></small>
                        </td>
                        <td>
                            <?= coverageAdminEscape($request['nama']) ?><br>
                            <a href="tel:<?= coverageAdminEscape($request['telepon']) ?>"><?= coverageAdminEscape($request['telepon']) ?></a>
                        </td>
                        <td>
                            <details>
                                <summary>Lihat alamat dan detail lokasi</summary>
                                <p class="mt-2 mb-1"><?= nl2br(coverageAdminEscape($request['alamat'])) ?></p>
                                <p class="mb-1">Kelurahan/desa: <?= coverageAdminEscape($request['kelurahan'] ?: '-') ?></p>
                                <p class="mb-1">Kecamatan: <?= coverageAdminEscape($request['kecamatan'] ?: '-') ?></p>
                                <p class="mb-1">Patokan: <?= coverageAdminEscape($request['patokan'] ?: '-') ?></p>
                                <p class="mb-1">Koordinat: <?= coverageAdminEscape($request['latitude'] ?? '-') ?>, <?= coverageAdminEscape($request['longitude'] ?? '-') ?></p>
                                <?php if ($safeMapLink): ?>
                                    <a href="<?= coverageAdminEscape($mapLink) ?>" target="_blank" rel="noopener noreferrer">Buka lokasi di Google Maps</a>
                                <?php endif; ?>
                                <p class="mt-2 mb-1">Catatan customer: <?= nl2br(coverageAdminEscape($request['catatan'] ?: '-')) ?></p>
                                <p class="mb-1">Catatan admin: <?= nl2br(coverageAdminEscape($request['catatan_admin'] ?: '-')) ?></p>
                                <p class="mb-1">Catatan teknisi: <?= nl2br(coverageAdminEscape($request['catatan_teknisi'] ?: '-')) ?></p>
                            </details>
                        </td>
                        <td><?= coverageAdminEscape($statuses[$request['status']] ?? $request['status']) ?></td>
                        <td><?= nl2br(coverageAdminEscape($request['catatan_admin'] ?: '-')) ?></td>
                        <td style="min-width:260px">
                            <form method="post" class="d-grid gap-2">
                                <input type="hidden" name="csrf" value="<?= coverageAdminEscape($_SESSION['coverage_admin_csrf']) ?>">
                                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                <label class="visually-hidden" for="status-<?= (int) $request['id'] ?>">Status</label>
                                <select class="form-select form-select-sm" id="status-<?= (int) $request['id'] ?>" name="status" required>
                                    <?php foreach ($statuses as $value => $label): ?>
                                        <option value="<?= coverageAdminEscape($value) ?>" <?= $request['status'] === $value ? 'selected' : '' ?>><?= coverageAdminEscape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="network_available" value="1"
                                        <?= !empty($request['network_available']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">Jaringan fisik sudah tersedia (izinkan lanjut pilih paket)</span>
                                </label>
                                <label class="visually-hidden" for="note-<?= (int) $request['id'] ?>">Catatan tindak lanjut</label>
                                <textarea class="form-control form-control-sm" id="note-<?= (int) $request['id'] ?>" name="note" maxlength="1000" rows="2" placeholder="Catatan tindak lanjut"><?= coverageAdminEscape($request['catatan_admin']) ?></textarea>
                                <button class="btn btn-sm btn-primary" type="submit">Simpan perubahan</button>
                            </form>
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
