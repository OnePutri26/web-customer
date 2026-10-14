<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('teknisi');

$namaTeknisi = trim((string) ($_SESSION['nama'] ?? ''));
if ($namaTeknisi === '') {
    http_response_code(403);
    exit('Data akun teknisi tidak lengkap.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pengajuanId = filter_input(INPUT_POST, 'id_pengajuan', FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    $coverageRequestId = filter_input(INPUT_POST, 'coverage_request_id', FILTER_VALIDATE_INT);
    $surveyNote = trim((string) ($_POST['survey_note'] ?? ''));

    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (in_array($action, ['approve_expansion', 'reject_expansion'], true)) {
        $surveyLength = preg_match_all('/./us', $surveyNote);
        if (!$coverageRequestId || $surveyLength === false || $surveyLength > 500) {
            $error = 'Data hasil survei tidak valid.';
        } else {
            $decision = $action === 'approve_expansion' ? 'disetujui' : 'ditolak';
            try {
                $technicianId = (int) $_SESSION['user_id'];
                $stmt = $conn->prepare("
                    UPDATE coverage_expansion_requests
                    SET status = ?, catatan_teknisi = ?, surveyed_by = ?, surveyed_at = NOW()
                    WHERE id = ? AND status = 'survei'
                ");
                $stmt->bind_param('ssii', $decision, $surveyNote, $technicianId, $coverageRequestId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed !== 1) {
                    throw new RuntimeException('Coverage request is not waiting for a technician survey.');
                }
                header('Location: dashboard.php');
                exit;
            } catch (Throwable $e) {
                error_log('Technician coverage survey update failed: ' . $e->getMessage());
                $error = 'Keputusan survei gagal disimpan. Pastikan pengajuan masih berstatus survei.';
            }
        }
    } elseif (!$pengajuanId || !in_array($action, ['start', 'complete'], true)) {
        $error = 'Permintaan perubahan status tidak valid.';
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("
                SELECT status
                FROM instalasi
                WHERE id_pengajuan = ? AND teknisi = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('is', $pengajuanId, $namaTeknisi);
            $stmt->execute();
            $installation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $expectedStatus = $action === 'start' ? 'dijadwalkan' : 'proses';
            if (!$installation || $installation['status'] !== $expectedStatus) {
                throw new RuntimeException('Installation is not assigned or cannot change from its current state.');
            }

            if ($action === 'start') {
                $stmt = $conn->prepare("
                    UPDATE instalasi SET status = 'proses'
                    WHERE id_pengajuan = ? AND teknisi = ? AND status = 'dijadwalkan'
                ");
                $stmt->bind_param('is', $pengajuanId, $namaTeknisi);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    UPDATE instalasi
                    SET status = 'selesai', tanggal_pemasangan = NOW()
                    WHERE id_pengajuan = ? AND teknisi = ? AND status = 'proses'
                ");
                $stmt->bind_param('is', $pengajuanId, $namaTeknisi);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE pengajuan_pemasangan
                    SET status = 'installed'
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $pengajuanId);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Technician installation rollback failed: ' . $rollbackError->getMessage());
            }
            error_log('Technician installation update failed: ' . $e->getMessage());
            $error = 'Status instalasi gagal diperbarui. Pastikan pekerjaan masih ditugaskan kepada Anda.';
        }
    }
}

$stmt = $conn->prepare("
    SELECT i.id_pengajuan, i.alamat, i.tanggal_jadwal, i.status, c.nama AS nama_pelanggan
    FROM instalasi i
    LEFT JOIN customers c ON c.id = i.id_customer
    WHERE i.teknisi = ?
    ORDER BY i.tanggal_jadwal DESC
");
$stmt->bind_param('s', $namaTeknisi);
$stmt->execute();
$installations = $stmt->get_result();

$coverageRequests = $conn->query("
    SELECT id, nomor_pengajuan, nama, telepon, email, alamat, latitude, longitude,
           catatan, status, created_at
    FROM coverage_expansion_requests
    WHERE status = 'survei'
    ORDER BY created_at ASC
");

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Teknisi - YesNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container py-4">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Dashboard Teknisi</h1>
            <p class="text-secondary mb-0">Halo, <?= e($namaTeknisi) ?></p>
        </div>
        <a href="../logout.php" class="btn btn-outline-secondary">Keluar</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead><tr>
                <th>Nomor Pengajuan</th><th>Pelanggan</th><th>Alamat</th><th>Jadwal</th><th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
                <?php if ($installations->num_rows === 0): ?>
                    <tr><td colspan="6" class="text-center">Belum ada instalasi yang ditugaskan kepada Anda.</td></tr>
                <?php else: ?>
                    <?php while ($installation = $installations->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int) $installation['id_pengajuan'] ?></td>
                            <td><?= e($installation['nama_pelanggan'] ?? '-') ?></td>
                            <td><?= e($installation['alamat'] ?? '-') ?></td>
                            <td><?= e($installation['tanggal_jadwal'] ?? '-') ?></td>
                            <td><?= e($installation['status'] ?? '-') ?></td>
                            <td>
                                <?php if ($installation['status'] === 'dijadwalkan' || $installation['status'] === 'proses'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id_pengajuan" value="<?= (int) $installation['id_pengajuan'] ?>">
                                        <?php if ($installation['status'] === 'dijadwalkan'): ?>
                                            <input type="hidden" name="action" value="start">
                                            <button class="btn btn-sm btn-primary" type="submit">Mulai</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="complete">
                                            <button class="btn btn-sm btn-success" type="submit">Selesaikan</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="text-secondary">Tidak ada aksi</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <section class="mt-5">
        <h2 class="h4 mb-3">Survei Perluasan Jaringan</h2>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead><tr>
                    <th>Nomor</th><th>Customer</th><th>Kontak</th><th>Alamat / Lokasi</th><th>Catatan customer</th><th>Hasil survei</th>
                </tr></thead>
                <tbody>
                <?php if ($coverageRequests->num_rows === 0): ?>
                    <tr><td colspan="6" class="text-center">Belum ada pengajuan yang menunggu survei teknisi.</td></tr>
                <?php else: ?>
                    <?php while ($coverageRequest = $coverageRequests->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($coverageRequest['nomor_pengajuan']) ?></td>
                            <td><?= e($coverageRequest['nama']) ?></td>
                            <td>
                                <a href="tel:<?= e($coverageRequest['telepon']) ?>"><?= e($coverageRequest['telepon']) ?></a><br>
                                <?= e($coverageRequest['email'] ?: '-') ?>
                            </td>
                            <td>
                                <?= nl2br(e($coverageRequest['alamat'])) ?><br>
                                <small>GPS: <?= e($coverageRequest['latitude'] ?? '-') ?>, <?= e($coverageRequest['longitude'] ?? '-') ?></small>
                            </td>
                            <td><?= e($coverageRequest['catatan'] ?: '-') ?></td>
                            <td style="min-width:260px">
                                <form method="post" class="d-grid gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="coverage_request_id" value="<?= (int) $coverageRequest['id'] ?>">
                                    <textarea class="form-control" name="survey_note" maxlength="500" rows="2" placeholder="Catatan hasil survei (opsional)"></textarea>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-success" type="submit" name="action" value="approve_expansion">Setujui</button>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="reject_expansion">Tolak</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
