<?php

require_once __DIR__ . "/../../auth_check.php";
require_once __DIR__ . "/../../../config/app.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $areaId = (int) ($_POST['area_id'] ?? 0);
    $judul = trim((string) ($_POST['judul'] ?? ''));
    $deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
    $estimasi = trim((string) ($_POST['estimasi_selesai'] ?? ''));

    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($areaId <= 0) {
        $error = 'Area wajib dipilih.';
    } elseif ($judul === '') {
        $error = 'Judul gangguan wajib diisi.';
    } else {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO network_incidents
                (area_id, judul, deskripsi, mulai, estimasi_selesai)
                SELECT id, ?, ?, NOW(), NULLIF(?, '')
                FROM network_areas
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param('sssi', $judul, $deskripsi, $estimasi, $areaId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Selected network area does not exist.');
            }

            $stmt->close();
            $stmt = $conn->prepare("UPDATE network_areas SET status = 'gangguan' WHERE id = ?");
            $stmt->bind_param('i', $areaId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header('Location: ../../overview.php?section=network');
            exit;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Network incident rollback failed: ' . $rollbackError->getMessage());
            }
            error_log('Failed to record network incident: ' . $e->getMessage());
            $error = 'Gangguan gagal disimpan. Silakan coba lagi.';
        }
    }
}

$areas = $conn->query("
    SELECT id, nama_area
    FROM network_areas
    ORDER BY nama_area
");

$pageTitle = 'Catat Gangguan Jaringan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - YesNet Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <a href="../../overview.php?section=network" class="btn btn-outline-secondary">Kembali</a>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($areas->num_rows === 0): ?>
                <div class="alert alert-warning" role="status">Belum ada area jaringan untuk dipilih.</div>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="area_id" class="form-label">Area jaringan</label>
                        <select class="form-select" id="area_id" name="area_id" required>
                            <option value="">Pilih area</option>
                            <?php while ($area = $areas->fetch_assoc()): ?>
                                <option value="<?= (int) $area['id'] ?>"><?= e($area['nama_area']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="judul" class="form-label">Judul gangguan</label>
                        <input class="form-control" id="judul" name="judul" maxlength="150" required>
                    </div>
                    <div class="mb-3">
                        <label for="deskripsi" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="deskripsi" name="deskripsi" rows="4"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="estimasi_selesai" class="form-label">Estimasi selesai (opsional)</label>
                        <input class="form-control" type="datetime-local" id="estimasi_selesai" name="estimasi_selesai">
                    </div>
                    <button class="btn btn-primary" type="submit">Simpan Gangguan</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>