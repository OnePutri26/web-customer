<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session: jangan dobel start (config/app.php bisa saja sudah memulainya)
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Load konfigurasi utama
|--------------------------------------------------------------------------
*/
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
    error_log('YesNet coverage: config/app.php tidak ditemukan dari ' . __DIR__);
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

/*
|--------------------------------------------------------------------------
| Helper escaping (didefinisikan sebelum dipakai, hanya jika belum ada)
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$error = '';

$alamat = trim((string) ($_GET['alamat'] ?? ''));

$addressLength = static function (string $value): int {
    $length = preg_match_all('/./us', $value);
    return $length === false ? 0 : $length;
};

/*
 * Parse koordinat: hanya angka valid dan masuk rentang yang diterima.
 * Nilai kosong / tidak valid menjadi null (bukan 0.0, yang titiknya di
 * tengah Samudra Atlantik dan akan merusak perhitungan jarak ODP).
 */
$parseCoord = static function ($raw, float $min, float $max): ?float {
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value >= $min && $value <= $max) ? $value : null;
};

$auto = isset($_GET['auto']) && $addressLength($alamat) >= 10;


/*
|--------------------------------------------------------------------------
| Proses pengecekan coverage
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $alamat = trim((string) ($_POST['alamat'] ?? ''));

    $lat = $parseCoord($_POST['latitude']  ?? '', -90.0, 90.0);
    $lng = $parseCoord($_POST['longitude'] ?? '', -180.0, 180.0);

    // Koordinat harus berpasangan; kalau salah satu tidak valid, buang keduanya.
    if ($lat === null || $lng === null) {
        $lat = null;
        $lng = null;
    }

    if (function_exists('csrf_ok') && !csrf_ok()) {

        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';

    } elseif ($addressLength($alamat) < 10) {

        $error = 'Masukkan alamat lengkap minimal 10 karakter.';

    } else {

        try {

            $h = cekCoverage($conn, $alamat, $lat, $lng);
            unset($_SESSION['coverage_request_id']);

            /*
             * Struktur session 'flow' dipertahankan sama persis, karena
             * coverage_hasil.php, paket.php, request_coverage.php dan halaman
             * sesudahnya membacanya.
             */
            $_SESSION['flow'] = [
                'mode'     => 'coverage',
                'alamat'   => $alamat,
                'lat'      => $lat,
                'lng'      => $lng,
                'tersedia' => !empty($h['tersedia']),
                'odp_id'   => $h['odp_id'] ?? null,
                'odp'      => $h['odp'] ?? null,
                'jarak'    => $h['jarak'] ?? null,
            ];

            if (function_exists('redirect')) {
                redirect('coverage_hasil.php');
            } else {
                header('Location: coverage_hasil.php');
            }
            exit;

        } catch (Throwable $e) {

            // Detail error hanya ke log server, bukan ke customer.
            error_log('Coverage error: ' . $e->getMessage());

            $error = 'Pengecekan coverage gagal diproses. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Cek Coverage';

/*
 * URL aset: pakai folder file ini bila ada, jika tidak pakai folder root project
 * (relatif, mis. ../assets/...). Versi dari waktu modifikasi file agar tidak tertahan cache.
 */
$assetUrl = static function (string $relative) use ($baseDir): string {
    $names = array_values(array_unique([$relative, basename($relative)]));
    $here  = str_replace('\\', '/', __DIR__);
    $root  = str_replace('\\', '/', $baseDir);
    $rel   = (strpos($here, $root) === 0) ? trim(substr($here, strlen($root)), '/') : '';
    $up    = str_repeat('../', $rel === '' ? 0 : count(explode('/', $rel)));

    foreach ($names as $name) {
        if (is_file(__DIR__ . '/' . $name)) {
            return $name . '?v=' . (string) filemtime(__DIR__ . '/' . $name);
        }
        if (is_file($baseDir . '/' . $name)) {
            return $up . $name . '?v=' . (string) filemtime($baseDir . '/' . $name);
        }
    }

    return $relative;
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | YesNet</title>

    <link rel="stylesheet" href="<?= e($assetUrl('assets/css/coverage.css')) ?>">
</head>

<body>

<header class="simple-header">
    <div class="container">
        <a href="index.php" class="logo">YES<span>NET</span></a>
        <a href="index.php" class="back-link">← Kembali</a>
    </div>
</header>

<main class="coverage-page">

    <div class="coverage-container">

        <div class="page-head enter">
            <div class="icon-circle">📍</div>
            <div>
                <h1>Cek Coverage</h1>
                <p>
                    Masukkan alamat pemasangan untuk mengetahui
                    apakah jaringan YesNet tersedia di lokasi Anda.
                </p>
            </div>
        </div>

        <div class="card enter">

            <?php if ($error): ?>
                <div class="alert err" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form
                id="covForm"
                method="post"
                action="coverage.php"
                data-auto="<?= $auto ? '1' : '0' ?>"
            >

                <?php
                if (function_exists('csrf_field')) {
                    echo csrf_field();
                }
                ?>

                <input type="hidden" name="latitude" id="latitude" value="">
                <input type="hidden" name="longitude" id="longitude" value="">

                <label for="alamat">Alamat lengkap</label>

                <textarea
                    class="in"
                    id="alamat"
                    name="alamat"
                    required
                    minlength="10"
                    placeholder="Contoh: Jl. Merdeka No. 123, Kel. Sidokare, Sidoarjo"
                ><?= e($alamat) ?></textarea>

                <p id="covInfo" class="muted" aria-live="polite"></p>

                <button
                    class="btn btn-primary btn-block"
                    type="submit"
                    id="covSubmit"
                >
                    Cek Ketersediaan
                </button>

                <button class="btn btn-block" type="button" id="covGps" hidden>
                    📍 Gunakan lokasi saya
                </button>

            </form>

        </div>

        <div class="coverage-note">
            <strong>💡 Tips</strong>
            <p>
                Gunakan alamat lengkap agar hasil pengecekan
                jaringan lebih akurat.
            </p>
        </div>

    </div>

</main>

<script src="<?= e($assetUrl('assets/js/cek-coverage.js')) ?>"></script>

<footer class="simple-footer">
    <p>© <?= date('Y') ?> YesNet. All rights reserved.</p>
</footer>

</body>
</html>