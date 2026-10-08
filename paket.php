<?php
require_once __DIR__ . '/config/app.php';

/*
| mode=coverage : datang dari cek coverage (tersedia) -> detail paket -> register -> ...
| mode=direct   : "Lihat Paket" -> langsung ke pembayaran
*/
$mode = ($_GET['mode'] ?? flow('mode', 'direct')) === 'coverage' ? 'coverage' : 'direct';
if ($mode === 'coverage' && !flow('tersedia')) {
    redirect('coverage.php');
}

// Customer yang sudah aktif tidak perlu memilih paket lagi
if (isCustomer()) {
    $c = currentCustomer($conn);
    if ($c && isAktif($c['status_langganan'])) {
        redirect('customer/dashboard.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['paket_id'] ?? 0);
    $p = csrf_ok() ? getPaket($conn, $pid) : null;
    if (!$p) {
        $error = 'Paket tidak ditemukan atau sesi kedaluwarsa. Silakan pilih ulang.';
    } else {
        flowSet(['mode' => $mode, 'paket_id' => $pid]);
        redirect($mode === 'coverage' ? 'detail_paket.php' : 'pembayaran.php?paket=' . $pid);
    }
}

try {
    $pakets = listPaket($conn);
} catch (Throwable $e) {
    $pakets = [];
    $error = 'Daftar paket gagal dimuat.';
}

$pageTitle = 'Pilih Paket';
$step = $mode === 'coverage' ? 1 : null;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter">
    <h1>Pilih Paket Internet</h1>
    <p><?= $mode === 'coverage'
        ? 'Jaringan tersedia di alamat Anda. Pilih paket yang sesuai kebutuhan.'
        : 'Pilih paket, lalu langsung lanjut ke pembayaran.' ?></p>
</div>
<?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="enter"><?= renderPaketCards($pakets, 'paket.php?mode=' . $mode) ?></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
