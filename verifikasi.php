<?php
require_once __DIR__ . '/config/app.php';

if (!isCustomer()) {
    redirect(flow('paket_id') ? 'register.php' : 'login.php');
}
if (!flow('tersedia') || !flow('paket_id')) {
    redirect('paket.php?mode=direct');
}
$cust = currentCustomer($conn);
$p = getPaket($conn, (int) flow('paket_id'));
if (!$cust || !$p) {
    redirect('paket.php?mode=coverage');
}
if (isAktif($cust['status_langganan'])) {
    redirect('customer/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } elseif (empty($_POST['benar'])) {
        $error = 'Centang pernyataan bahwa data sudah benar.';
    } else {
        flowSet(['verified' => true]);
        redirect('order.php');
    }
}

$nik = (string) $cust['nik'];
$nikMask = strlen($nik) > 8 ? substr($nik, 0, 4) . str_repeat('•', strlen($nik) - 8) . substr($nik, -4) : $nik;

$pageTitle = 'Verifikasi Data';
$step = 2;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter">
    <h1>Verifikasi Data</h1>
    <p>Periksa kembali data Anda sebelum membuat order.</p>
</div>
<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="sum"><span>Nama</span><b><?= e($cust['nama']) ?></b></div>
    <div class="sum"><span>Email</span><b><?= e($cust['email']) ?></b></div>
    <div class="sum"><span>WhatsApp</span><b><?= e($cust['telephone']) ?></b></div>
    <div class="sum"><span>NIK</span><b><?= e($nikMask) ?></b></div>
    <div class="sum"><span>Alamat pemasangan</span><b><?= e(flow('alamat')) ?></b></div>
    <div class="sum"><span>Paket</span><b><?= e($p['nama_paket']) ?> · <?= (int) $p['speed_mbps'] ?> Mbps</b></div>
    <form method="post">
        <?= csrf_field() ?>
        <label style="display:flex;gap:10px;align-items:flex-start;font-weight:500;margin-top:18px">
            <input type="checkbox" name="benar" value="1" style="margin-top:4px">
            Saya menyatakan data di atas sudah benar.
        </label>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:18px">Data Benar, Lanjutkan</button>
    </form>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
