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

// Alamat pemasangan: pakai dari alur coverage, cadangan alamat saat daftar.
$alamat = (string) (flow('alamat') ?: ($cust['alamat'] ?? ''));

$cssFile = __DIR__ . '/assets/css/verifikasi.css';
$cssVer  = is_file($cssFile) ? filemtime($cssFile) : 1;

$pageTitle = 'Verifikasi Data';
$step = 2;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<link rel="stylesheet" href="/assets/css/verifikasi.css?v=<?= (int) $cssVer ?>">

<div class="verif">

    <div class="page-head enter">
        <span class="v-eyebrow"><i></i> Langkah 2 · Verifikasi</span>
        <h1>Verifikasi Data</h1>
        <p>Periksa kembali data Anda sebelum order dibuat.</p>
    </div>

    <div class="card enter">
        <div class="v-body">

            <?php if ($error): ?>
                <div class="alert err" role="alert">⚠️ <span><?= e($error) ?></span></div>
            <?php endif; ?>

            <div class="v-title"><span>Data Pelanggan</span></div>

            <div class="v-list">
                <div class="v-row">
                    <div class="v-ico">👤</div>
                    <div class="v-txt"><small>Nama</small><b><?= e($cust['nama']) ?></b></div>
                    <div class="v-ok">✓</div>
                </div>
                <div class="v-row">
                    <div class="v-ico">✉️</div>
                    <div class="v-txt"><small>Email</small><b><?= e($cust['email']) ?></b></div>
                    <div class="v-ok">✓</div>
                </div>
                <div class="v-row">
                    <div class="v-ico">💬</div>
                    <div class="v-txt"><small>WhatsApp</small><b><?= e($cust['telephone']) ?></b></div>
                    <div class="v-ok">✓</div>
                </div>
                <div class="v-row">
                    <div class="v-ico">🪪</div>
                    <div class="v-txt"><small>NIK</small><b class="v-mono"><?= e($nikMask) ?></b></div>
                    <div class="v-ok">✓</div>
                </div>
                <div class="v-row">
                    <div class="v-ico">📍</div>
                    <div class="v-txt"><small>Alamat pemasangan</small><b><?= e($alamat) ?></b></div>
                    <div class="v-ok">✓</div>
                </div>
            </div>

            <div class="v-title"><span>Paket Dipilih</span></div>

            <div class="v-plan">
                <small>Paket internet</small>
                <div class="v-plan-main">
                    <div class="v-plan-name"><?= e($p['nama_paket']) ?></div>
                    <div class="v-plan-speed"><b><?= (int) $p['speed_mbps'] ?></b><span>Mbps</span></div>
                </div>
            </div>

            <div class="v-note">
                <span>🔒</span>
                <div>NIK ditampilkan sebagian demi keamanan. Data Anda hanya dipakai untuk proses pemasangan.</div>
            </div>

            <form method="post" class="v-form">
                <?= csrf_field() ?>

                <label class="v-check">
                    <input type="checkbox" name="benar" value="1">
                    <span class="v-box" aria-hidden="true"></span>
                    <span>Saya menyatakan data di atas sudah benar.</span>
                </label>

                <button class="btn btn-primary btn-block btn-go" type="submit">
                    Data Benar, Lanjutkan <span class="arr">→</span>
                </button>

                <a class="v-back" href="paket.php?mode=coverage">← Ganti paket</a>
            </form>

        </div>
    </div>

</div>
<?php require __DIR__ . '/inc/footer.php'; ?>