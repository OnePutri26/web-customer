<?php
require_once __DIR__ . '/config/app.php';

$flow = flow();
if (empty($flow['alamat'])) {
    redirect('coverage.php');
}
$tersedia = !empty($flow['tersedia']);

$pageTitle = $tersedia ? 'Jaringan Tersedia' : 'Jaringan Belum Tersedia';
$step = 0;
require __DIR__ . '/inc/header.php';
?>
<div class="card center enter">
<?php if ($tersedia): ?>
    <?= stateIcon('ok') ?>
    <h2>Jaringan tersedia di lokasi Anda</h2>
    <p class="muted" style="margin-top:8px"><?= e($flow['alamat']) ?></p>
    <?php if (!empty($flow['odp'])): ?>
        <p style="margin-top:12px"><span class="chip">
            <?= e($flow['odp']) ?><?= $flow['jarak'] !== null ? ' · ' . number_format((float) $flow['jarak'], 2, ',', '.') . ' km' : '' ?>
        </span></p>
    <?php endif; ?>
    <div class="actions">
        <a class="btn btn-primary" href="paket.php?mode=coverage">Pilih Paket</a>
        <a class="btn" href="coverage.php">Cek alamat lain</a>
    </div>
<?php else: ?>
    <?= stateIcon('warn') ?>
    <h2>Jaringan belum tersedia di lokasi Anda</h2>
    <p class="muted" style="margin-top:8px"><?= e($flow['alamat']) ?></p>
    <p style="margin-top:12px">Kirim permintaan pemasangan. Kami akan menghubungi Anda begitu jaringan masuk ke area ini.</p>
    <div class="actions">
        <a class="btn btn-primary" href="request_coverage.php">Isi Form Request</a>
        <a class="btn" href="coverage.php">Cek alamat lain</a>
    </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
