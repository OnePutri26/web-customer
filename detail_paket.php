<?php
require_once __DIR__ . '/config/app.php';

if (!flow('tersedia') || !flow('paket_id')) {
    redirect(flow('tersedia') ? 'paket.php?mode=coverage' : 'coverage.php');
}
$p = getPaket($conn, (int) flow('paket_id'));
if (!$p) {
    redirect('paket.php?mode=coverage');
}

$pageTitle = 'Detail Paket';
$step = 1;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter"><h1>Detail Paket <?= e(preg_replace('/^Paket\s+/i', '', $p['nama_paket'])) ?></h1></div>
<div class="card enter">
    <div class="paket-speed" style="font-size:34px;margin-top:0"><?= (int) $p['speed_mbps'] ?> Mbps</div>
    <div class="paket-price"><?= rupiah($p['harga']) ?> <small>/bulan</small></div>
    <p class="muted" style="margin-bottom:10px"><?= e($p['deskripsi']) ?></p>
    <div class="sum"><span>Kuota</span><b>Unlimited</b></div>
    <div class="sum"><span>Perangkat</span><b>WiFi Router</b></div>
    <div class="sum"><span>Biaya instalasi</span><b>Gratis</b></div>
    <div class="sum"><span>Alamat pemasangan</span><b><?= e(flow('alamat')) ?></b></div>
    <div class="actions" style="justify-content:stretch">
        <a class="btn btn-primary" style="flex:1" href="<?= isCustomer() ? 'verifikasi.php' : 'register.php' ?>">
            <?= isCustomer() ? 'Lanjut Verifikasi Data' : 'Lanjut Daftar Customer' ?>
        </a>
        <a class="btn" href="paket.php?mode=coverage">Ganti Paket</a>
    </div>
    <?php if (!isCustomer()): ?>
        <p class="muted center" style="margin-top:14px;font-size:13px">Sudah punya akun? <a href="login.php">Login Customer</a></p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
