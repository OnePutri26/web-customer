<?php
require_once __DIR__ . '/config/app.php';

$error = '';
$alamat = trim((string) ($_GET['alamat'] ?? ''));
$auto = isset($_GET['auto']) && mb_strlen($alamat) >= 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alamat = trim((string) ($_POST['alamat'] ?? ''));
    $lat = ($_POST['latitude'] ?? '') !== '' ? (float) $_POST['latitude'] : null;
    $lng = ($_POST['longitude'] ?? '') !== '' ? (float) $_POST['longitude'] : null;

    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (mb_strlen($alamat) < 10) {
        $error = 'Masukkan alamat lengkap (minimal 10 karakter).';
    } else {
        try {
            $h = cekCoverage($conn, $alamat, $lat, $lng);
            $_SESSION['flow'] = [
                'mode'     => 'coverage',
                'alamat'   => $alamat,
                'lat'      => $lat,
                'lng'      => $lng,
                'tersedia' => $h['tersedia'],
                'odp_id'   => $h['odp_id'],
                'odp'      => $h['odp'],
                'jarak'    => $h['jarak'],
            ];
            redirect('coverage_hasil.php');
        } catch (Throwable $e) {
            $error = 'Pengecekan gagal diproses. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Cek Coverage';
$step = 0;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter">
    <h1>📍 Cek Coverage</h1>
    <p>Masukkan alamat pemasangan untuk mengetahui apakah jaringan YesNet tersedia di lokasi Anda.</p>
</div>

<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form id="covForm" method="post" action="coverage.php" data-auto="<?= $auto ? '1' : '0' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="latitude" value="">
        <input type="hidden" name="longitude" value="">
        <label for="alamat">Alamat lengkap</label>
        <textarea class="in" id="alamat" name="alamat" required minlength="10" placeholder="Contoh: Jl. Merdeka No. 123, Kel. Sidokare, Sidoarjo"><?= e($alamat) ?></textarea>
        <p id="covInfo" class="muted" style="margin-top:10px;min-height:22px" aria-live="polite"></p>
        <button class="btn btn-primary btn-block" type="submit">Cek Ketersediaan</button>
        <button class="btn btn-block" type="button" id="covGps" hidden style="margin-top:10px">Gunakan lokasi saya</button>
    </form>
</div>
<script src="assets/js/cek-coverage.js"></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
