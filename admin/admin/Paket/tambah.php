<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../../config/database.php';

if (empty($_SESSION['package_admin_csrf'])) {
    $_SESSION['package_admin_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$name = '';
$speed = '';
$price = '';
$description = '';
$status = 'aktif';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['nama_paket'] ?? ''));
    $speed = trim((string) ($_POST['speed_mbps'] ?? ''));
    $price = trim((string) ($_POST['harga'] ?? ''));
    $description = trim((string) ($_POST['deskripsi'] ?? ''));
    $status = (string) ($_POST['status'] ?? '');
    $token = (string) ($_POST['csrf'] ?? '');

    if (!hash_equals((string) $_SESSION['package_admin_csrf'], $token)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang lalu coba lagi.';
    } elseif (
        $name === ''
        || strlen($name) > 120
        || filter_var($speed, FILTER_VALIDATE_INT) === false
        || (int) $speed < 1
        || !is_numeric($price)
        || (float) $price <= 0
        || strlen($description) > 1000
        || !in_array($status, ['aktif', 'nonaktif'], true)
    ) {
        $error = 'Periksa kembali nama, kecepatan, harga, deskripsi, dan status paket.';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO paket_wifi (nama_paket, speed_mbps, harga, deskripsi, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $speedValue = (int) $speed;
            $priceValue = (float) $price;
            $stmt->bind_param('sidss', $name, $speedValue, $priceValue, $description, $status);
            $stmt->execute();
            $stmt->close();

            header('Location: index.php', true, 303);
            exit;
        } catch (mysqli_sql_exception $e) {
            error_log('Admin package insert failed: ' . $e->getMessage());
            $error = 'Paket gagal disimpan karena layanan sedang mengalami gangguan.';
        }
    }
}

function paketTambahEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Paket - YesNet Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container py-4" style="max-width: 760px">
    <h1 class="h3 mb-4">Tambah Paket WiFi</h1>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= paketTambahEscape($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= paketTambahEscape($_SESSION['package_admin_csrf']) ?>">
        <div class="mb-3">
            <label class="form-label" for="nama-paket">Nama paket</label>
            <input class="form-control" id="nama-paket" name="nama_paket" maxlength="120" required value="<?= paketTambahEscape($name) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="speed">Kecepatan (Mbps)</label>
            <input class="form-control" id="speed" type="number" name="speed_mbps" min="1" required value="<?= paketTambahEscape($speed) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="harga">Harga per bulan (Rp)</label>
            <input class="form-control" id="harga" type="number" name="harga" min="1" step="0.01" required value="<?= paketTambahEscape($price) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="deskripsi">Deskripsi</label>
            <textarea class="form-control" id="deskripsi" name="deskripsi" maxlength="1000" rows="4"><?= paketTambahEscape($description) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status" required>
                <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $status === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Simpan Paket</button>
        <a class="btn btn-outline-secondary" href="index.php">Batal</a>
    </form>
</main>
</body>
</html>
