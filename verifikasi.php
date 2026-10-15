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

$pageTitle = 'Verifikasi Data';
$step = 2;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<?php
/*
 * CSS dibaca langsung dari disk lalu ditanam ke halaman, sehingga tampilan
 * tidak bergantung pada URL /assets/..., .htaccess, atau cache.
 * Kalau file tidak ada di server, muncul peringatan merah.
 */
$cssDir = __DIR__ . '/assets/css/';
$cssMissing = [];
echo "<style>\n";
foreach (['yesnet.css', 'verifikasi.css'] as $cssName) {
    if (is_file($cssDir . $cssName)) {
        readfile($cssDir . $cssName);
        echo "\n";
    } else {
        $cssMissing[] = $cssName;
    }
}
echo "</style>\n";
if ($cssMissing) {
    echo '<div style="max-width:560px;margin:16px auto;padding:14px 16px;border-radius:12px;'
       . 'background:#fff0f0;color:#a4262c;border:1px solid #ffd0d2;font:600 14px system-ui,sans-serif">'
       . 'File CSS tidak ditemukan di server: <b>' . e(implode(', ', $cssMissing)) . '</b><br>'
       . 'Seharusnya ada di: <code>' . e($cssDir) . '</code></div>';
}
?>

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