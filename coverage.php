<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Load konfigurasi utama
|--------------------------------------------------------------------------
| File coverage.php dan direktori config berada di root project yang sama.
*/
require_once __DIR__ . '/config/app.php';

$error = '';

$alamat = trim((string) ($_GET['alamat'] ?? ''));
$addressLength = static function (string $value): int {
    $length = preg_match_all('/./us', $value);
    return $length === false ? 0 : $length;
};

$auto = isset($_GET['auto'])
    && $addressLength($alamat) >= 10;


/*
|--------------------------------------------------------------------------
| Proses pengecekan coverage
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $alamat = trim((string) ($_POST['alamat'] ?? ''));

    $lat = ($_POST['latitude'] ?? '') !== ''
        ? (float) $_POST['latitude']
        : null;

    $lng = ($_POST['longitude'] ?? '') !== ''
        ? (float) $_POST['longitude']
        : null;


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */
    if (function_exists('csrf_ok') && !csrf_ok()) {

        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';

    } elseif ($addressLength($alamat) < 10) {

        $error = 'Masukkan alamat lengkap minimal 10 karakter.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Cek coverage
            |--------------------------------------------------------------------------
            */
            $h = cekCoverage(
                $conn,
                $alamat,
                $lat,
                $lng
            );


            /*
            |--------------------------------------------------------------------------
            | Simpan hasil ke session
            |--------------------------------------------------------------------------
            */
            $_SESSION['flow'] = [
                'mode'     => 'coverage',
                'alamat'   => $alamat,
                'lat'      => $lat,
                'lng'      => $lng,
                'tersedia' => $h['tersedia'] ?? false,
                'odp_id'   => $h['odp_id'] ?? null,
                'odp'      => $h['odp'] ?? null,
                'jarak'    => $h['jarak'] ?? null,
            ];


            /*
            |--------------------------------------------------------------------------
            | Redirect ke halaman hasil
            |--------------------------------------------------------------------------
            */
            if (function_exists('redirect')) {

                redirect('coverage_hasil.php');

            } else {

                header('Location: coverage_hasil.php');
                exit;

            }

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Jangan tampilkan detail error database ke customer
            |--------------------------------------------------------------------------
            */
            error_log(
                'Coverage error: ' . $e->getMessage()
            );

            $error = 'Pengecekan coverage gagal diproses. Silakan coba lagi.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Helper escaping jika belum tersedia
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {

    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


$pageTitle = 'Cek Coverage';


/*
|--------------------------------------------------------------------------
| Header halaman standalone
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | YesNet</title>

    <link
        rel="stylesheet"
        href="assets/css/coverage.css?v=1.0"
    >
</head>

<body>

<header class="simple-header">

    <div class="container">

        <a href="index.php" class="logo">
            YES<span>NET</span>
        </a>

        <a href="index.php" class="back-link">
            ← Kembali
        </a>

    </div>

</header>

<main class="coverage-page">

    <div class="coverage-container">

        <div class="page-head enter">

            <div class="icon-circle">
                📍
            </div>

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

                <div
                    class="alert err"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>

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


                <input
                    type="hidden"
                    name="latitude"
                    id="latitude"
                    value=""
                >

                <input
                    type="hidden"
                    name="longitude"
                    id="longitude"
                    value=""
                >


                <label for="alamat">
                    Alamat lengkap
                </label>


                <textarea
                    class="in"
                    id="alamat"
                    name="alamat"
                    required
                    minlength="10"
                    placeholder="Contoh: Jl. Merdeka No. 123, Kel. Sidokare, Sidoarjo"
                ><?= e($alamat) ?></textarea>


                <p
                    id="covInfo"
                    class="muted"
                    aria-live="polite"
                ></p>


                <button
                    class="btn btn-primary btn-block"
                    type="submit"
                >
                    Cek Ketersediaan
                </button>


                <button
                    class="btn btn-block"
                    type="button"
                    id="covGps"
                    hidden
                >
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


<script src="assets/js/cek-coverage.js"></script>

<footer class="simple-footer">

    <p>
        © <?= date('Y') ?> YesNet. All rights reserved.
    </p>

</footer>

</body>
</html>
