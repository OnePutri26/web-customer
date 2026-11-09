<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../../config/database.php';

if (empty($_SESSION['package_admin_csrf'])) {
    $_SESSION['package_admin_csrf'] = bin2hex(random_bytes(32));
}

$packageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
}
if (!$packageId || $packageId < 1) {
    header('Location: index.php', true, 302);
    exit;
}

$error = '';
try {
    $stmt = $conn->prepare("
        SELECT id, nama_paket, speed_mbps, harga, deskripsi, status,
               promo_jenis, promo_nilai, promo_mulai, promo_berakhir
        FROM paket_wifi
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $packageId);
    $stmt->execute();
    $package = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('Admin package lookup failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Data paket belum dapat dimuat. Silakan coba kembali nanti.');
}

if (!$package) {
    http_response_code(404);
    exit('Paket tidak ditemukan.');
}

$name = (string) $package['nama_paket'];
$speed = (string) $package['speed_mbps'];
$price = (string) $package['harga'];
$description = (string) ($package['deskripsi'] ?? '');
$status = strtolower(trim((string) $package['status'])) === 'nonaktif' ? 'nonaktif' : 'aktif';
$promoType = (string) ($package['promo_jenis'] ?? '');
$promoValue = (string) ($package['promo_nilai'] ?? '');
$promoStart = (string) ($package['promo_mulai'] ?? '');
$promoEnd = (string) ($package['promo_berakhir'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['nama_paket'] ?? ''));
    $speed = trim((string) ($_POST['speed_mbps'] ?? ''));
    $price = trim((string) ($_POST['harga'] ?? ''));
    $description = trim((string) ($_POST['deskripsi'] ?? ''));
    $status = (string) ($_POST['status'] ?? '');
    $promoType = (string) ($_POST['promo_jenis'] ?? '');
    $promoValue = trim((string) ($_POST['promo_nilai'] ?? ''));
    $promoStart = trim((string) ($_POST['promo_mulai'] ?? ''));
    $promoEnd = trim((string) ($_POST['promo_berakhir'] ?? ''));
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
        || !in_array($promoType, ['', 'nominal', 'persentase'], true)
        || ($promoType !== '' && (
            !is_numeric($promoValue)
            || (float) $promoValue <= 0
            || ($promoType === 'persentase' && (float) $promoValue >= 100)
            || ($promoType === 'nominal' && (float) $promoValue >= (float) $price)
        ))
        || !paketEditValidDate($promoStart)
        || !paketEditValidDate($promoEnd)
        || ($promoStart !== '' && $promoEnd !== '' && $promoStart > $promoEnd)
    ) {
        $error = 'Periksa kembali data paket, nilai promo, dan periode promo.';
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE paket_wifi
                SET nama_paket = ?, speed_mbps = ?, harga = ?, deskripsi = ?, status = ?,
                    promo_jenis = ?, promo_nilai = ?, promo_mulai = ?, promo_berakhir = ?
                WHERE id = ?
            ");
            $speedValue = (int) $speed;
            $priceValue = (float) $price;
            $promoTypeDb = $promoType !== '' ? $promoType : null;
            $promoValueDb = $promoType !== '' ? (float) $promoValue : null;
            $promoStartDb = $promoType !== '' && $promoStart !== '' ? $promoStart : null;
            $promoEndDb = $promoType !== '' && $promoEnd !== '' ? $promoEnd : null;
            $stmt->bind_param('sidsssdssi', $name, $speedValue, $priceValue, $description, $status, $promoTypeDb, $promoValueDb, $promoStartDb, $promoEndDb, $packageId);
            $stmt->execute();
            $stmt->close();

            header('Location: index.php', true, 303);
            exit;
        } catch (mysqli_sql_exception $e) {
            error_log('Admin package update failed: ' . $e->getMessage());
            $error = 'Paket gagal diperbarui karena layanan sedang mengalami gangguan.';
        }
    }
}

function paketEditValidDate(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function paketEditEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Paket - YesNet Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<main class="container py-4" style="max-width: 760px">
    <h1 class="h3 mb-4">Ubah Paket WiFi</h1>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= paketEditEscape($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= paketEditEscape($_SESSION['package_admin_csrf']) ?>">
        <input type="hidden" name="id" value="<?= (int) $packageId ?>">
        <div class="mb-3">
            <label class="form-label" for="nama-paket">Nama paket</label>
            <input class="form-control" id="nama-paket" name="nama_paket" maxlength="120" required value="<?= paketEditEscape($name) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="speed">Kecepatan (Mbps)</label>
            <input class="form-control" id="speed" type="number" name="speed_mbps" min="1" required value="<?= paketEditEscape($speed) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="harga">Harga per bulan (Rp)</label>
            <input class="form-control" id="harga" type="number" name="harga" min="1" step="0.01" required value="<?= paketEditEscape($price) ?>">
        </div>
        <fieldset class="border rounded p-3 mb-3">
            <legend class="float-none w-auto px-2 fs-6">Promo (opsional)</legend>
            <div class="mb-3">
                <label class="form-label" for="promo-jenis">Jenis potongan</label>
                <select class="form-select" id="promo-jenis" name="promo_jenis">
                    <option value="" <?= $promoType === '' ? 'selected' : '' ?>>Tidak ada promo</option>
                    <option value="nominal" <?= $promoType === 'nominal' ? 'selected' : '' ?>>Nominal (Rp)</option>
                    <option value="persentase" <?= $promoType === 'persentase' ? 'selected' : '' ?>>Persentase (%)</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="promo-nilai">Besaran potongan</label>
                <input class="form-control" id="promo-nilai" type="number" name="promo_nilai" min="0.01" step="0.01" value="<?= paketEditEscape($promoValue) ?>" aria-describedby="promo-help">
                <div class="form-text" id="promo-help">Nominal harus lebih kecil dari harga paket; persentase di bawah 100%. Kosongkan periode untuk promo tanpa batas tanggal.</div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="promo-mulai">Mulai</label>
                    <input class="form-control" id="promo-mulai" type="date" name="promo_mulai" value="<?= paketEditEscape($promoStart) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="promo-berakhir">Berakhir</label>
                    <input class="form-control" id="promo-berakhir" type="date" name="promo_berakhir" value="<?= paketEditEscape($promoEnd) ?>">
                </div>
            </div>
        </fieldset>
        <div class="mb-3">
            <label class="form-label" for="deskripsi">Deskripsi</label>
            <textarea class="form-control" id="deskripsi" name="deskripsi" maxlength="1000" rows="4"><?= paketEditEscape($description) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status" required>
                <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $status === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
        <a class="btn btn-outline-secondary" href="index.php">Batal</a>
    </form>
</main>
</body>
</html>
