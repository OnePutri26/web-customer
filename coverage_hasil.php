<?php
$configFile = null;
$docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

/* Folder kandidat: folder file ini + semua folder di atasnya (maks. 6 tingkat) + document root */
$bases = [];
$walk = __DIR__;
for ($lvl = 0; $lvl < 6; $lvl++) {
    $bases[] = $walk;
    $up = dirname($walk);
    if ($up === $walk) {
        break;
    }
    $walk = $up;
}
if ($docRoot !== '') {
    $bases[] = $docRoot;
}
$bases = array_values(array_unique($bases));

/* 1) config/app.php langsung di salah satu folder kandidat */
foreach ($bases as $base) {
    if (is_file($base . '/config/app.php')) {
        $configFile = $base . '/config/app.php';
        break;
    }
}

/* 2) config/app.php di subfolder langsung (mis. customer/config, app/config) */
if ($configFile === null) {
    foreach ($bases as $base) {
        $found = glob($base . '/*/config/app.php') ?: [];
        if ($found !== []) {
            $configFile = $found[0];
            break;
        }
    }
}

if ($configFile === null) {
    error_log('YesNet coverage_hasil: config/app.php tidak ditemukan dari ' . __DIR__);
    http_response_code(500);
    // DIAGNOSTIK SEMENTARA: hapus blok ini setelah halaman berjalan normal.
    header('Content-Type: text/plain; charset=utf-8');
    $listing = static function (string $dir): string {
        $items = @scandir($dir);
        return $items === false ? '(tidak bisa dibaca)' : implode(', ', array_diff($items, ['.', '..']));
    };
    $sub = static function (string $name) use ($listing, $docRoot): string {
        $dir = ($docRoot !== '' ? $docRoot : __DIR__) . '/' . $name;
        return is_dir($dir) ? $listing($dir) : '(folder tidak ada)';
    };
    exit(
        "config/app.php tidak ditemukan.\n\n" .
        "Folder file ini : " . __DIR__ . "\n" .
        "Document root   : " . $docRoot . "\n\n" .
        "Isi config/     : " . $sub('config') . "\n" .
        "Isi customer/   : " . $sub('customer') . "\n" .
        "Isi inc/        : " . $sub('inc') . "\n" .
        "Isi assets/     : " . $sub('assets')
    );
}

require_once $configFile;
$baseDir = dirname($configFile, 2);
unset($bases, $walk, $up, $lvl, $base, $found);

$flow = flow();

// Tanpa hasil pengecekan di session, kembali ke form cek coverage.
if (empty($flow['alamat'])) {
    redirect('coverage.php');
}

$tersedia = !empty($flow['tersedia']);
$odp      = $flow['odp'] ?? null;
$jarak    = $flow['jarak'] ?? null;

$pageTitle = $tersedia ? 'Jaringan Tersedia' : 'Jaringan Belum Tersedia';
$step = 0;
require $baseDir . '/inc/header.php';
?>
<div class="card center enter">
<?php if ($tersedia): ?>
    <?= stateIcon('ok') ?>
    <h2>Jaringan tersedia di lokasi Anda</h2>
    <p class="muted" style="margin-top:8px"><?= e($flow['alamat']) ?></p>
    <?php if (!empty($odp)): ?>
        <p style="margin-top:12px"><span class="chip">
            <?= e($odp) ?><?= $jarak !== null ? ' · ' . number_format((float) $jarak, 2, ',', '.') . ' km' : '' ?>
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
    <p style="margin-top:12px">
        Jaringan YesNet belum menjangkau area ini. Ajukan perluasan jaringan,
        alamat dan titik lokasi Anda akan kami evaluasi dan disurvei teknisi.
        Setelah jaringan tersedia, Anda dapat memilih paket.
    </p>
    <div class="actions">
        <a class="btn btn-primary" href="request_coverage.php">Ajukan Perluasan Jaringan</a>
        <a class="btn" href="coverage.php">Cek alamat lain</a>
    </div>
<?php endif; ?>
</div>
<?php require $baseDir . '/inc/footer.php'; ?>