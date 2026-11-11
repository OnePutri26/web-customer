
<?php
/**
 * YESNET - Daftar Paket Internet
 * Database: paket_wifi
 * Kolom: id, nama_paket, speed_mbps, harga, wilayah, status, created_at
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/config/database.php';

/* =========================================================
   HELPER
========================================================= */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah')) {
    function rupiah($nominal): string
    {
        return 'Rp ' . number_format((float)$nominal, 0, ',', '.');
    }
}

function statusPaket($status): array
{
    $value = strtolower(trim((string)$status));

    if (in_array($value, ['aktif', 'active', 'tersedia', 'available', '1'], true)) {
        return ['label' => 'Aktif', 'class' => 'status-active'];
    }

    if (in_array($value, ['nonaktif', 'non-aktif', 'inactive', 'tidak aktif', '0'], true)) {
        return ['label' => 'Nonaktif', 'class' => 'status-inactive'];
    }

    if ($value === '') {
        return ['label' => 'Status belum diatur', 'class' => 'status-unknown'];
    }

    return ['label' => ucwords($value), 'class' => 'status-unknown'];
}

/**
 * Normalisasi nilai wilayah database.
 * JATIM, SURABAYA, SIDOARJO, dan JAWA TIMUR dianggap satu wilayah.
 */
function normalisasiWilayah($wilayah): string
{
    $value = strtoupper(trim((string)$wilayah));

    if (in_array($value, [
        'JATIM',
        'JAWA TIMUR',
        'SURABAYA',
        'KOTA SURABAYA',
        'SIDOARJO'
    ], true)) {
        return 'surabaya';
    }

    if (in_array($value, ['JAKARTA', 'DKI JAKARTA'], true)) {
        return 'jakarta';
    }

    return '';
}

function formatWilayahPaket($wilayah): string
{
    $region = normalisasiWilayah($wilayah);

    if ($region === 'jakarta') {
        return 'Jakarta';
    }

    if ($region === 'surabaya') {
        return 'Surabaya';
    }

    return trim((string)$wilayah) !== ''
        ? ucwords(strtolower(trim((string)$wilayah)))
        : 'Wilayah belum ditentukan';
}

function wilayahUntukUrl($wilayah): string
{
    $region = normalisasiWilayah($wilayah);

    if ($region !== '') {
        return $region;
    }

    return strtolower(trim((string)$wilayah));
}

/* =========================================================
   PILIH WILAYAH
========================================================= */

$wilayahDipilih = strtolower(trim((string)($_GET['wilayah'] ?? 'jakarta')));

if (!in_array($wilayahDipilih, ['jakarta', 'surabaya'], true)) {
    $wilayahDipilih = 'jakarta';
}

$wilayahDb = $wilayahDipilih === 'jakarta' ? 'JAKARTA' : 'JATIM';
$wilayahLabel = $wilayahDipilih === 'jakarta' ? 'Jakarta' : 'Surabaya';

$modeCoverage = (string)($_GET['mode'] ?? '') === 'coverage';
$modeQuery = $modeCoverage ? '&mode=coverage' : '';

/* =========================================================
   DATA COVERAGE DARI SESSION
========================================================= */

$coverageFlow = $modeCoverage && !empty($_SESSION['flow']['tersedia']);

$flowAlamat = $coverageFlow
    ? trim((string)($_SESSION['flow']['alamat'] ?? ''))
    : '';

$flowOdp = $coverageFlow
    ? trim((string)($_SESSION['flow']['odp'] ?? ''))
    : '';

$flowJarak = (
    $coverageFlow &&
    isset($_SESSION['flow']['jarak']) &&
    is_numeric($_SESSION['flow']['jarak'])
) ? (float)$_SESSION['flow']['jarak'] : null;

/* =========================================================
   AMBIL PAKET BERDASARKAN WILAYAH
========================================================= */

$packages = [];
$loadError = false;
$errorMessage = '';

try {
    if ($wilayahDipilih === 'jakarta') {
        $wilayahAlias = ['JAKARTA', 'DKI JAKARTA'];
    } else {
        $wilayahAlias = [
            'JATIM',
            'JAWA TIMUR',
            'SURABAYA',
            'KOTA SURABAYA',
            'SIDOARJO'
        ];
    }

    $placeholders = implode(',', array_fill(0, count($wilayahAlias), '?'));

    $sql = "
        SELECT
            id,
            nama_paket,
            speed_mbps,
            harga,
            wilayah,
            status,
            created_at
        FROM paket_wifi
        WHERE UPPER(TRIM(COALESCE(wilayah, ''))) IN ($placeholders)
        ORDER BY harga ASC, speed_mbps ASC, id ASC
    ";

    $stmt = $conn->prepare($sql);

    $types = str_repeat('s', count($wilayahAlias));
    $stmt->bind_param($types, ...$wilayahAlias);

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $packages[] = $row;
    }

    $stmt->close();

} catch (Throwable $ex) {
    error_log('Paket YESNET gagal dimuat: ' . $ex->getMessage());

    $loadError = true;
    $errorMessage = 'Terjadi kesalahan saat mengambil data paket dari database.';
}

$totalPackages = count($packages);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta
        name="description"
        content="Daftar paket internet YESNET berdasarkan wilayah pemasangan."
    >

    <title>Paket Internet | YESNET</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           NAVBAR
        ===================================================== */

        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;

            display: flex;
            align-items: center;

            padding: 0 7%;

            background: rgba(255,255,255,.94);
            backdrop-filter: blur(18px);

            border-bottom: 1px solid #e2e8f0;
        }

        .logo {
            margin-right: auto;
            font-size: 25px;
            font-weight: 900;
            letter-spacing: -1px;
            color: #0f172a;
        }

        .logo span {
            color: #2563eb;
        }

        .nav-links {
            display: flex;
            gap: 30px;
            margin-right: 30px;
        }

        .nav-links a {
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
        }

        .nav-links a:hover {
            color: #2563eb;
        }

        .login-btn {
            padding: 11px 20px;
            border-radius: 11px;
            color: white;
            background: #2563eb;
            font-size: 14px;
            font-weight: 700;
        }

        /* =====================================================
           HERO
        ===================================================== */

        .hero {
            position: relative;
            overflow: hidden;

            padding: 95px 7% 70px;
            text-align: center;

            background:
                radial-gradient(
                    circle at 50% 0%,
                    rgba(37,99,235,.15),
                    transparent 40%
                ),
                #f8fafc;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            padding: 8px 15px;
            margin-bottom: 22px;

            border-radius: 50px;
            background: #dbeafe;
            color: #2563eb;

            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .hero h1 {
            max-width: 800px;
            margin: auto;

            font-size: clamp(42px, 6vw, 68px);
            line-height: 1.05;
            letter-spacing: -3px;
            font-weight: 900;
        }

        .hero h1 span {
            color: #2563eb;
        }

        .hero p {
            max-width: 650px;
            margin: 22px auto 0;

            color: #64748b;
            font-size: 17px;
            line-height: 1.8;
        }

        /* =====================================================
           PACKAGE SECTION
        ===================================================== */

        .package-section {
            max-width: 1200px;
            margin: auto;
            padding: 35px 20px 100px;
        }

        /* =====================================================
           REGION PICKER
           Desain mengikuti gambar referensi
        ===================================================== */

        .region-picker {
            display: flex;
            flex-direction: column;
            align-items: center;

            margin: 0 auto 45px;
            text-align: center;
        }

        .region-picker-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;

            margin-bottom: 13px;

            color: #102b50;
            font-size: 14px;
            font-weight: 800;
        }

        .region-picker-title i {
            color: #17477f;
            font-size: 14px;
        }

        .region-options {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .region-option {
            position: relative;
            isolation: isolate;
            overflow: hidden;

            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;

            min-width: 135px;
            height: 43px;
            padding: 0 23px;

            border: 1px solid #d3ddeb;
            border-radius: 50px;

            background: #fff;
            color: #17365e;

            font-size: 13px;
            font-weight: 700;

            transition:
                background .25s ease,
                color .25s ease,
                border-color .25s ease,
                box-shadow .25s ease,
                transform .25s ease;
        }

        .region-option i {
            position: relative;
            z-index: 2;
            font-size: 13px;
        }

        .region-option span {
            position: relative;
            z-index: 2;
        }

        /* Tombol wilayah aktif */
        .region-option.active {
            border-color: transparent;

            background: linear-gradient(
                110deg,
                #123f78 0%,
                #164c85 75%,
                #a51e4d 100%
            );

            color: #fff;

            box-shadow: 0 8px 18px rgba(15, 50, 91, .18);
        }

        .region-option:not(.active):hover {
            border-color: #17477f;
            background: #f8fbff;
            color: #17477f;
            transform: translateY(-1px);
        }

        .region-option.active:hover {
            box-shadow: 0 10px 22px rgba(15, 50, 91, .24);
            transform: translateY(-1px);
        }

        .region-hint {
            margin-top: 12px;
            color: #64748b;
            font-size: 12px;
        }

        .region-hint strong {
            color: #17477f;
        }

        /* =====================================================
           COVERAGE BANNER
        ===================================================== */

        .cov-banner {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;

            margin: 0 0 28px;
            padding: 16px 20px;

            border-radius: 16px;
            background: #ecfdf3;
            border: 1px solid #abefc6;
            color: #067647;
        }

        .cov-banner > i {
            font-size: 22px;
        }

        .cov-banner div {
            flex: 1 1 260px;
            min-width: 0;
        }

        .cov-banner strong {
            display: block;
            font-size: 15px;
        }

        .cov-banner span {
            display: block;
            font-size: 13px;
            color: #1d7a4d;
            overflow-wrap: anywhere;
        }

        .cov-banner a {
            font-size: 13px;
            font-weight: 700;
            color: #067647;

            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid #abefc6;
            background: #fff;
        }

        .cov-banner.warn {
            background: #fffaf0;
            border-color: #fedf89;
            color: #93370d;
        }

        .cov-banner.warn span {
            color: #b54708;
        }

        .cov-banner.warn a {
            color: #93370d;
            border-color: #fedf89;
        }

        /* =====================================================
           PACKAGE HEADING
        ===================================================== */

        .package-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;

            margin-bottom: 28px;
        }

        .package-heading h2 {
            font-size: 25px;
            margin-bottom: 6px;
        }

        .package-heading p,
        .package-count {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

        .package-count strong {
            color: #0f172a;
        }

        /* =====================================================
           PACKAGE CARD
        ===================================================== */

        .package-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .package-card {
            position: relative;

            display: flex;
            flex-direction: column;

            min-height: 440px;
            padding: 30px;

            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 25px;

            box-shadow: 0 15px 45px rgba(15,23,42,.06);

            transition: .3s;
        }

        .package-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 60px rgba(15,23,42,.12);
        }

        .package-icon {
            width: 58px;
            height: 58px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 22px;
            border-radius: 17px;

            background: #dbeafe;
            color: #2563eb;
            font-size: 25px;
        }

        .package-card h3 {
            margin: 12px 0 8px;
            font-size: 22px;
            overflow-wrap: anywhere;
        }

        .package-region {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;

            width: fit-content;
            padding: 6px 10px;

            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        .status-active {
            color: #067647;
            background: #dcfae6;
        }

        .status-inactive {
            color: #b42318;
            background: #fee4e2;
        }

        .status-unknown {
            color: #475467;
            background: #eaecf0;
        }

        .speed {
            margin: 22px 0 10px;
        }

        .speed strong {
            font-size: 50px;
            line-height: 1;
            letter-spacing: -2px;
        }

        .speed span {
            color: #64748b;
            font-size: 15px;
        }

        .description {
            min-height: 48px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }

        .price {
            padding: 20px 0;
            margin-top: 18px;
            border-top: 1px solid #e2e8f0;
        }

        .price small {
            display: block;
            color: #64748b;
            margin-bottom: 5px;
        }

        .price strong {
            font-size: 25px;
        }

        .price span {
            color: #64748b;
            font-size: 13px;
        }

        .choose-btn {
            display: block;
            width: 100%;

            margin-top: auto;
            padding: 14px;

            border: 0;
            border-radius: 12px;

            background: #2563eb;
            color: #fff;

            text-align: center;
            font-size: 14px;
            font-weight: 800;

            cursor: pointer;
            transition: .2s;
        }

        .choose-btn:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }

        .choose-btn.disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty {
            padding: 80px 20px;
            text-align: center;

            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
        }

        .empty > i {
            font-size: 50px;
            color: #94a3b8;
        }

        .empty h2 {
            margin: 20px 0 8px;
        }

        .empty p {
            color: #64748b;
            line-height: 1.7;
        }

        .retry-btn {
            display: inline-block;
            margin-top: 22px;
            padding: 12px 24px;

            border-radius: 12px;
            background: #2563eb;
            color: white;
            font-weight: 800;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        footer {
            padding: 50px 7%;
            background: #0f172a;
            color: white;
            text-align: center;
        }

        footer p {
            margin-top: 10px;
            color: #94a3b8;
            font-size: 13px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 900px) {
            .package-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .navbar {
                padding: 0 5%;
            }

            .nav-links {
                display: none;
            }

            .package-grid {
                grid-template-columns: 1fr;
            }

            .hero {
                padding-top: 70px;
            }

            .hero h1 {
                letter-spacing: -2px;
            }

            .package-card {
                padding: 26px;
            }

            .package-heading h2 {
                font-size: 22px;
            }

            .package-section {
                padding-left: 16px;
                padding-right: 16px;
            }

            .region-options {
                width: 100%;
                gap: 9px;
            }

            .region-option {
                flex: 1;
                min-width: 0;
                max-width: 160px;
                height: 43px;
                padding: 0 15px;
            }

            .region-picker {
                margin-bottom: 35px;
            }
        }

        @media (max-width: 360px) {
            .region-option {
                gap: 6px;
                padding: 0 10px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<header class="navbar">

    <div class="logo">
        YES<span>NET</span>
    </div>

    <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="coverage.php">Cek Coverage</a>
        <a href="paket.php">Paket</a>
        <a href="index.php#keunggulan">Keunggulan</a>
    </nav>

    <a href="login.php" class="login-btn">
        Login
    </a>

</header>

<!-- =========================================================
     HERO
========================================================= -->

<section class="hero">

    <div class="hero-badge">
        <i class="bi bi-wifi"></i>
        PAKET INTERNET YESNET
    </div>

    <h1>
        Pilih Internet yang
        <span>Cocok Untukmu</span>
    </h1>

    <p>
        Pilih paket internet sesuai kebutuhan rumah, bekerja,
        belajar, streaming, dan hiburan keluarga.
    </p>

</section>

<!-- =========================================================
     DAFTAR PAKET
========================================================= -->

<section class="package-section" id="paket">

    <!-- PILIH WILAYAH -->

    <div class="region-picker">

        <div class="region-picker-title">
            <i class="bi bi-geo-alt"></i>
            <span>Pilih wilayah pemasangan</span>
        </div>

        <div class="region-options">

            <a
                href="paket.php?wilayah=jakarta<?= e($modeQuery) ?>#paket"
                class="region-option <?= $wilayahDipilih === 'jakarta' ? 'active' : '' ?>"
                aria-pressed="<?= $wilayahDipilih === 'jakarta' ? 'true' : 'false' ?>"
            >
                <i class="bi bi-geo-alt"></i>
                <span>Jakarta</span>
            </a>

            <a
                href="paket.php?wilayah=surabaya<?= e($modeQuery) ?>#paket"
                class="region-option <?= $wilayahDipilih === 'surabaya' ? 'active' : '' ?>"
                aria-pressed="<?= $wilayahDipilih === 'surabaya' ? 'true' : 'false' ?>"
            >
                <i class="bi bi-geo-alt"></i>
                <span>Surabaya</span>
            </a>

        </div>

        <p class="region-hint">
            Menampilkan paket khusus wilayah
            <strong><?= e($wilayahLabel) ?></strong>.
        </p>

    </div>

    <!-- INFORMASI COVERAGE -->

    <?php if ($coverageFlow): ?>

        <div class="cov-banner" role="status">

            <i class="bi bi-geo-alt-fill"></i>

            <div>
                <strong>Jaringan tersedia di lokasi Anda</strong>

                <span>
                    <?= e($flowAlamat) ?>

                    <?php if ($flowOdp !== ''): ?>
                        · <?= e($flowOdp) ?>

                        <?php if ($flowJarak !== null): ?>
                            (<?= e(number_format($flowJarak * 1000, 0, ',', '.')) ?> m)
                        <?php endif; ?>

                    <?php endif; ?>
                </span>
            </div>

            <a href="coverage.php">
                Ganti alamat
            </a>

        </div>

    <?php elseif ($modeCoverage): ?>

        <div class="cov-banner warn" role="status">

            <i class="bi bi-exclamation-triangle-fill"></i>

            <div>
                <strong>Lokasi Anda belum dicek</strong>

                <span>
                    Cek coverage terlebih dahulu agar ketersediaan
                    jaringan di rumah dapat dipastikan.
                </span>
            </div>

            <a href="coverage.php">
                Cek coverage
            </a>

        </div>

    <?php endif; ?>

    <!-- HASIL PAKET -->

    <?php if ($loadError): ?>

        <div class="empty">

            <i class="bi bi-exclamation-circle"></i>

            <h2>Paket Belum Dapat Dimuat</h2>

            <p>
                <?= e($errorMessage) ?>
                Periksa konfigurasi database dan nama tabel.
            </p>

            <a
                class="retry-btn"
                href="paket.php?wilayah=<?= e($wilayahDipilih) ?><?= e($modeQuery) ?>#paket"
            >
                Muat Ulang
            </a>

        </div>

    <?php elseif ($totalPackages > 0): ?>

        <div class="package-heading">

            <div>
                <h2>
                    Paket Internet <?= e($wilayahLabel) ?>
                </h2>

                <p>
                    Pilih paket internet yang sesuai dengan kebutuhan Anda.
                </p>
            </div>

            <div class="package-count">
                Total
                <strong><?= (int)$totalPackages ?> paket</strong>
            </div>

        </div>

        <div class="package-grid">

            <?php foreach ($packages as $package): ?>

                <?php
                    $packageId = (int)($package['id'] ?? 0);

                    $packageName = trim(
                        (string)($package['nama_paket'] ?? 'Paket Internet')
                    );

                    $speed = (int)($package['speed_mbps'] ?? 0);
                    $harga = (float)($package['harga'] ?? 0);

                    $wilayahAsli = trim(
                        (string)($package['wilayah'] ?? '')
                    );

                    $wilayahLabelPaket = formatWilayahPaket($wilayahAsli);
                    $statusInfo = statusPaket($package['status'] ?? '');

                    /*
                     * URL pemilihan paket.
                     * Parameter wilayah tetap dikirim ke pilih_paket.php.
                     */
                    $pilihPaketUrl = 'pilih_paket.php?paket=' . $packageId;

                    $wilayahSlug = wilayahUntukUrl($wilayahAsli);

                    if ($wilayahSlug !== '') {
                        $pilihPaketUrl .= '&wilayah=' . rawurlencode($wilayahSlug);
                    }

                    if ($modeCoverage) {
                        $pilihPaketUrl .= '&mode=coverage';
                    }

                    $bisaDipilih = (
                        $packageId > 0 &&
                        $statusInfo['class'] === 'status-active'
                    );
                ?>

                <article class="package-card">

                    <div class="package-icon">
                        <i class="bi bi-wifi"></i>
                    </div>

                    <span class="package-region">
                        <i class="bi bi-geo-alt"></i>
                        <?= e($wilayahLabelPaket) ?>
                    </span>

                    <h3>
                        <?= e($packageName) ?>
                    </h3>

                    <span class="status-badge <?= e($statusInfo['class']) ?>">
                        <?= e($statusInfo['label']) ?>
                    </span>

                    <div class="speed">
                        <strong>
                            <?= e(number_format($speed, 0, ',', '.')) ?>
                        </strong>
                        <span>Mbps</span>
                    </div>

                    <p class="description">
                        Paket internet YESNET dengan kecepatan
                        hingga <?= e(number_format($speed, 0, ',', '.')) ?> Mbps.
                    </p>

                    <div class="price">

                        <small>Harga langganan</small>

                        <strong>
                            <?= e(rupiah($harga)) ?>
                        </strong>

                        <span>/bulan</span>

                    </div>

                    <?php if ($bisaDipilih): ?>

                        <a
                            href="<?= e($pilihPaketUrl) ?>"
                            class="choose-btn"
                        >
                            Pilih Paket Ini
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    <?php else: ?>

                        <span
                            class="choose-btn disabled"
                            aria-disabled="true"
                        >
                            <?= $packageId <= 0
                                ? 'ID Paket Tidak Valid'
                                : 'Paket Tidak Aktif'
                            ?>
                        </span>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">

            <i class="bi bi-wifi-off"></i>

            <h2>
                Paket <?= e($wilayahLabel) ?> Belum Tersedia
            </h2>

            <p>
                Belum ada paket internet untuk wilayah
                <strong><?= e($wilayahLabel) ?></strong>
                di database YESNET.
            </p>

        </div>

    <?php endif; ?>

</section>

<!-- =========================================================
     FOOTER
========================================================= -->

<footer>

    <strong>YESNET</strong>

    <p>
        Internet cepat dan stabil untuk kebutuhanmu.
    </p>

    <p>
        © <?= date('Y') ?> YESNET. All Rights Reserved.
    </p>

</footer>

</body>
</html>
