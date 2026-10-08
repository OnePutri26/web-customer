<?php
require_once __DIR__ . '/config/app.php';

if (!isCustomer()) {
    redirect('login.php');
}
if (!flow('verified') || !flow('paket_id') || !flow('alamat')) {
    redirect('verifikasi.php');
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
    } else {
        try {
            $tagihanId = ensureOrder($conn, $cust, $p, (string) flow('alamat'));
            redirect('pembayaran.php?tagihan=' . $tagihanId);
        } catch (Throwable $e) {
            $error = 'Order gagal dibuat. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Order Berlangganan';
$step = 3;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter"><h1>Order Berlangganan</h1><p>Periksa ringkasan order Anda.</p></div>
<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="sum"><span>Paket</span><b><?= e($p['nama_paket']) ?> · <?= (int) $p['speed_mbps'] ?> Mbps</b></div>
    <div class="sum"><span>Biaya bulanan</span><b><?= rupiah($p['harga']) ?></b></div>
    <div class="sum"><span>Biaya instalasi</span><b>Gratis</b></div>
    <div class="sum"><span>Alamat pemasangan</span><b><?= e(flow('alamat')) ?></b></div>
    <div class="sum"><span>Total pembayaran</span><b style="font-size:18px;color:var(--blue)"><?= rupiah($p['harga']) ?></b></div>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:18px">Buat Order &amp; Bayar</button>
    </form>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
