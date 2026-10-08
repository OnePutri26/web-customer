<?php
require_once __DIR__ . '/config/app.php';

$flow = flow();
if (empty($flow['alamat']) || !empty($flow['tersedia'])) {
    redirect('coverage.php');
}

$error = '';
$terkirim = false;
$nama = trim((string) ($_POST['nama'] ?? ($_SESSION['nama'] ?? '')));
$telp = trim((string) ($_POST['telephone'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (mb_strlen($nama) < 3) {
        $error = 'Nama lengkap minimal 3 karakter.';
    } elseif (!preg_match('/^[0-9+\-\s]{9,20}$/', $telp)) {
        $error = 'Nomor WhatsApp tidak valid.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } else {
        try {
            $alamat = (string) $flow['alamat'];
            $lat = $flow['lat'] !== null ? (float) $flow['lat'] : null;
            $lng = $flow['lng'] !== null ? (float) $flow['lng'] : null;
            $emailDb = $email !== '' ? $email : null;
            $st = $conn->prepare('INSERT INTO coverage_requests (nama, telephone, email, alamat, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)');
            $st->bind_param('ssssdd', $nama, $telp, $emailDb, $alamat, $lat, $lng);
            $st->execute();
            $st->close();
            $terkirim = true;
            unset($_SESSION['flow']);
        } catch (Throwable $e) {
            $error = 'Request gagal dikirim. Pastikan migrasi database sudah dijalankan.';
        }
    }
}

$pageTitle = 'Request Pemasangan';
$step = 0;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<?php if ($terkirim): ?>
    <div class="card center enter">
        <?= stateIcon('ok') ?>
        <h2>Request terkirim</h2>
        <p class="muted" style="margin-top:8px">Tim kami akan menghubungi Anda lewat WhatsApp.</p>
        <div class="actions"><a class="btn btn-primary" href="index.php">Kembali ke Beranda</a></div>
    </div>
<?php else: ?>
    <div class="page-head enter">
        <h1>Form Request Pemasangan</h1>
        <p>Alamat Anda belum terjangkau. Tinggalkan kontak agar kami bisa mengabari Anda.</p>
    </div>
    <div class="card enter">
        <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label for="nama">Nama lengkap</label>
            <input class="in" id="nama" name="nama" required value="<?= e($nama) ?>">
            <label for="telephone">Nomor WhatsApp</label>
            <input class="in" id="telephone" name="telephone" type="tel" required value="<?= e($telp) ?>" placeholder="08xxxxxxxxxx">
            <label for="email">Email (opsional)</label>
            <input class="in" id="email" name="email" type="email" value="<?= e($email) ?>">
            <label>Alamat</label>
            <div class="in" style="background:var(--bg)"><?= e($flow['alamat']) ?></div>
            <button class="btn btn-primary btn-block" type="submit" style="margin-top:20px">Kirim Request</button>
        </form>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
