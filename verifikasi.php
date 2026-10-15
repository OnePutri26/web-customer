<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

/*
|--------------------------------------------------------------------------
| YESNET - VERIFIKASI DATA PELANGGAN
|--------------------------------------------------------------------------
| File       : verifikasi.php
| CSS        : assets/css/yesnet.css
|              assets/css/verifikasi.css
| Berikutnya : order.php
|--------------------------------------------------------------------------
*/



/*
|--------------------------------------------------------------------------
| 1. Validasi pelanggan
|--------------------------------------------------------------------------
*/
if (!isCustomer()) {
    redirect(flow('paket_id') ? 'register.php' : 'login.php');
}

/*
|--------------------------------------------------------------------------
| 2. Validasi alur coverage dan paket
|--------------------------------------------------------------------------
*/
$paketId = (int) flow('paket_id');

if (!flow('tersedia') || $paketId <= 0) {
    redirect('paket.php?mode=direct');
}

/*
|--------------------------------------------------------------------------
| 3. Ambil data pelanggan dan paket
|--------------------------------------------------------------------------
*/
$cust = currentCustomer($conn);
$p = getPaket($conn, $paketId);

if (!$cust || !$p) {
    redirect('paket.php?mode=coverage');
}

/*
|--------------------------------------------------------------------------
| 4. Pelanggan aktif tidak perlu mendaftar ulang
|--------------------------------------------------------------------------
*/
if (isAktif($cust['status_langganan'] ?? '')) {
    redirect('customer/dashboard.php');
}

/*
|--------------------------------------------------------------------------
| 5. Siapkan data tampilan
|--------------------------------------------------------------------------
*/
$error = '';

$nama = trim((string) ($cust['nama'] ?? ''));
$email = trim((string) ($cust['email'] ?? ''));
$telephone = trim((string) ($cust['telephone'] ?? ''));

$nik = preg_replace(
    '/\D/',
    '',
    (string) ($cust['nik'] ?? '')
) ?? '';

if (strlen($nik) >= 8) {
    $nikMask = substr($nik, 0, 4)
        . str_repeat('•', strlen($nik) - 8)
        . substr($nik, -4);
} elseif ($nik !== '') {
    $nikMask = str_repeat('•', strlen($nik));
} else {
    $nikMask = 'Belum tersedia';
}

/*
 * Alamat dari alur coverage diprioritaskan.
 * Jika kosong, gunakan alamat dari data pelanggan.
 */
$alamatFlow = trim((string) flow('alamat'));
$alamatCustomer = trim((string) ($cust['alamat'] ?? ''));

$alamat = $alamatFlow !== ''
    ? $alamatFlow
    : $alamatCustomer;

/*
 * Deteksi sederhana jika nilai yang tersimpan adalah koordinat.
 */
$isCoordinate = preg_match(
    '/^\s*-?\d{1,3}(?:\.\d+)?\s*,?\s+-?\d{1,3}(?:\.\d+)?\s*$/',
    $alamat
) === 1;

/*
|--------------------------------------------------------------------------
| 6. Validasi persetujuan
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
    } elseif (($_POST['benar'] ?? '') !== '1') {
        $error = 'Centang pernyataan bahwa data sudah benar.';
    } elseif (
        $nama === '' ||
        $nik === '' ||
        $alamat === ''
    ) {
        $error = 'Data pelanggan belum lengkap. Periksa data Anda.';
    } elseif ($paketId !== (int) flow('paket_id')) {
        $error = 'Paket tidak valid. Silakan pilih paket kembali.';
    } else {
        /*
         * Status persetujuan disimpan di sesi alur.
         * order.php wajib memeriksa ulang status ini
         * sebelum membuat order.
         */
        flowSet(['verified' => true]);

        redirect('order.php');
    }
}

/*
|--------------------------------------------------------------------------
| 7. Konfigurasi halaman
|--------------------------------------------------------------------------
*/
$pageTitle = 'Verifikasi Data';
$step = 3;
$narrow = true;

require __DIR__ . '/inc/header.php';

/*
|--------------------------------------------------------------------------
| 8. Muat CSS dari file lokal
|--------------------------------------------------------------------------
| CSS tetap terpisah sehingga lebih mudah dikelola.
| File tidak dibuat otomatis jika tidak ditemukan.
|--------------------------------------------------------------------------
*/
$cssDir = __DIR__ . '/assets/css/';
$cssFiles = ['yesnet.css', 'verifikasi.css'];
$cssMissing = [];

foreach ($cssFiles as $cssFile) {
    $cssPath = $cssDir . $cssFile;

    if (is_file($cssPath) && is_readable($cssPath)) {
        echo '<style>' . "\n";

        /*
         * Baca file hanya dari direktori CSS yang ditentukan.
         */
        readfile($cssPath);

        echo "\n</style>\n";
    } else {
        $cssMissing[] = $cssFile;
    }
}

if ($cssMissing !== []) {
    echo '<div class="verif-css-warning" role="alert">';
    echo '<strong>File CSS tidak ditemukan:</strong> ';
    echo e(implode(', ', $cssMissing));
    echo '<br>Periksa folder: <code>';
    echo e($cssDir);
    echo '</code></div>';
}
?>

<main class="verif">

    <header class="page-head enter">
        <span class="v-eyebrow">
            <i aria-hidden="true"></i>
            Langkah 3 dari 6 · Verifikasi
        </span>

        <h1>Verifikasi Data</h1>
        <p>Periksa kembali data Anda sebelum order dibuat.</p>
    </header>

    <section class="card enter" aria-label="Verifikasi data pelanggan">
        <div class="v-body">

            <?php if ($error !== ''): ?>
                <div class="alert err" role="alert">
                    <span aria-hidden="true">⚠️</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="v-title">
                <span>Data Pelanggan</span>
            </div>

            <div class="v-list">

                <div class="v-row">
                    <div class="v-ico" aria-hidden="true">👤</div>
                    <div class="v-txt">
                        <small>Nama</small>
                        <b><?= e($nama ?: 'Belum tersedia') ?></b>
                    </div>
                    <div class="v-ok" aria-hidden="true">✓</div>
                </div>

                <div class="v-row">
                    <div class="v-ico" aria-hidden="true">✉️</div>
                    <div class="v-txt">
                        <small>Email</small>
                        <b><?= e($email ?: 'Belum tersedia') ?></b>
                    </div>
                    <div class="v-ok" aria-hidden="true">✓</div>
                </div>

                <div class="v-row">
                    <div class="v-ico" aria-hidden="true">💬</div>
                    <div class="v-txt">
                        <small>WhatsApp</small>
                        <b><?= e($telephone ?: 'Belum tersedia') ?></b>
                    </div>
                    <div class="v-ok" aria-hidden="true">✓</div>
                </div>

                <div class="v-row">
                    <div class="v-ico" aria-hidden="true">🪪</div>
                    <div class="v-txt">
                        <small>NIK</small>
                        <b class="v-mono"><?= e($nikMask) ?></b>
                    </div>
                    <div class="v-ok" aria-hidden="true">✓</div>
                </div>

                <div class="v-row">
                    <div class="v-ico" aria-hidden="true">📍</div>
                    <div class="v-txt">
                        <small>
                            <?= $isCoordinate
                                ? 'Koordinat pemasangan'
                                : 'Alamat pemasangan' ?>
                        </small>
                        <b><?= e($alamat ?: 'Belum tersedia') ?></b>
                    </div>
                    <div class="v-ok" aria-hidden="true">✓</div>
                </div>

            </div>

            <div class="v-title">
                <span>Paket Dipilih</span>
            </div>

            <div class="v-plan">
                <small>Paket internet</small>

                <div class="v-plan-main">
                    <div class="v-plan-name">
                        <?= e((string) ($p['nama_paket'] ?? 'Paket internet')) ?>
                    </div>

                    <div class="v-plan-speed">
                        <b><?= (int) ($p['speed_mbps'] ?? 0) ?></b>
                        <span>Mbps</span>
                    </div>
                </div>
            </div>

            <div class="v-note">
                <span aria-hidden="true">🔒</span>
                <div>
                    NIK ditampilkan sebagian demi keamanan.
                    Data Anda hanya digunakan untuk proses pemasangan.
                </div>
            </div>

            <form method="post" action="" class="v-form">
                <?= csrf_field() ?>

                <label class="v-check">
                    <input
                        type="checkbox"
                        name="benar"
                        value="1"
                        required
                    >

                    <span class="v-box" aria-hidden="true"></span>

                    <span>
                        Saya menyatakan data di atas sudah benar.
                    </span>
                </label>

                <button
                    class="btn btn-primary btn-block btn-go"
                    type="submit"
                >
                    Data Benar, Lanjutkan
                    <span class="arr" aria-hidden="true">→</span>
                </button>

                <a
                    class="v-back"
                    href="paket.php?mode=coverage"
                >
                    ← Ganti paket
                </a>
            </form>

        </div>
    </section>

</main>

<?php require __DIR__ . '/inc/footer.php'; ?>
