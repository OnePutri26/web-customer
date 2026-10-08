<?php
require_once __DIR__ . '/config/app.php';

if (!isCustomer()) {
    redirect('login.php');
}
$cust = currentCustomer($conn);
$id = (int) ($_GET['id'] ?? 0);
if (!$cust) {
    redirect('login.php');
}
$cid = (int) $cust['id'];
$st = $conn->prepare('SELECT * FROM pembayaran WHERE id = ? AND id_customer = ? LIMIT 1');
$st->bind_param('ii', $id, $cid);
$st->execute();
$by = $st->get_result()->fetch_assoc();
$st->close();
if (!$by) {
    redirect('paket.php?mode=direct');
}
$ok = $by['status'] === 'success';

$pageTitle = $ok ? 'Pembayaran Berhasil' : 'Pembayaran Gagal';
$step = 3;
require __DIR__ . '/inc/header.php';
?>
<div class="card center enter">
<?php if ($ok): ?>
    <?= stateIcon('ok') ?>
    <h2>Pembayaran berhasil</h2>
    <p class="muted" style="margin-top:8px">Order Anda sedang diproses. Tim kami akan melakukan survey dan validasi lokasi.</p>
    <div class="sum" style="max-width:380px;margin:18px auto 0"><span>No. pembayaran</span><b><?= e($by['nomor_pembayaran']) ?></b></div>
    <div class="sum" style="max-width:380px;margin:0 auto"><span>Total</span><b><?= rupiah($by['jumlah']) ?></b></div>
    <div class="actions"><a class="btn btn-primary" href="status_order.php">Lihat Status Order</a></div>
<?php else: ?>
    <?= stateIcon('err') ?>
    <h2>Pembayaran gagal</h2>
    <p class="muted" style="margin-top:8px">Transaksi tidak berhasil diproses. Tagihan Anda masih aktif, silakan bayar lagi.</p>
    <div class="actions">
        <a class="btn btn-primary" href="pembayaran.php?tagihan=<?= (int) $by['id_tagihan'] ?>">Bayar Lagi</a>
        <a class="btn" href="status_order.php">Lihat Status Order</a>
    </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
