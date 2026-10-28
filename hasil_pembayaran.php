<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/xendit.php';

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

if (($by['payment_provider'] ?? '') === 'xendit' && !empty($by['xendit_invoice_id'])) {
    try {
        $invoice = xenditGetInvoice((string) $by['xendit_invoice_id']);
        xenditApplyInvoiceStatus($conn, $invoice);

        $st = $conn->prepare('SELECT * FROM pembayaran WHERE id = ? AND id_customer = ? LIMIT 1');
        $st->bind_param('ii', $id, $cid);
        $st->execute();
        $by = $st->get_result()->fetch_assoc() ?: $by;
        $st->close();
    } catch (Throwable $e) {
        error_log('Gagal memperbarui status invoice Xendit: ' . $e->getMessage());
    }
}

$status = strtolower((string) ($by['status'] ?? 'pending'));
$ok = in_array($status, ['success', 'paid'], true);
$pending = $status === 'pending';
$expired = $status === 'expired';

$pageTitle = $ok ? 'Pembayaran Berhasil' : ($pending ? 'Pembayaran Menunggu' : 'Status Pembayaran');
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
<?php elseif ($pending): ?>
    <?= stateIcon('wait') ?>
    <h2>Pembayaran menunggu konfirmasi</h2>
    <p class="muted" style="margin-top:8px">Status akan diperbarui otomatis setelah Xendit mengonfirmasi pembayaran.</p>
    <div class="sum" style="max-width:380px;margin:18px auto 0"><span>No. pembayaran</span><b><?= e($by['nomor_pembayaran']) ?></b></div>
    <div class="sum" style="max-width:380px;margin:0 auto"><span>Total</span><b><?= rupiah($by['jumlah']) ?></b></div>
    <div class="actions">
        <?php if (!empty($by['xendit_invoice_url'])): ?>
            <a class="btn btn-primary" href="<?= e($by['xendit_invoice_url']) ?>">Lanjutkan pembayaran</a>
        <?php endif; ?>
        <a class="btn" href="hasil_pembayaran.php?id=<?= (int) $by['id'] ?>">Periksa status lagi</a>
    </div>
<?php else: ?>
    <?= stateIcon('err') ?>
    <h2><?= $expired ? 'Invoice pembayaran kedaluwarsa' : 'Pembayaran tidak berhasil' ?></h2>
    <p class="muted" style="margin-top:8px">Tagihan Anda masih tersimpan. Anda dapat membuat invoice pembayaran baru.</p>
    <div class="actions">
        <a class="btn btn-primary" href="pembayaran.php?tagihan=<?= (int) $by['id_tagihan'] ?>">Bayar Lagi</a>
        <a class="btn" href="status_order.php">Lihat Status Order</a>
    </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
