<?php
/*
|--------------------------------------------------------------------------
| YESNET - HEADER PUBLIK
|--------------------------------------------------------------------------
| Variabel opsional sebelum include:
| $pageTitle : judul halaman
| $step      : langkah 0-5
| $narrow    : true jika halaman memang perlu dibatasi lebarnya
|--------------------------------------------------------------------------
*/

$pageTitle = $pageTitle ?? 'YesNet';
$step = isset($step) ? (int) $step : null;
$narrow = $narrow ?? false;

$stepLabel = [
    'Coverage',
    'Paket',
    'Daftar & Verifikasi',
    'Order & Bayar',
    'Instalasi',
    'Aktif',
];

/*
|--------------------------------------------------------------------------
| Cari file CSS utama
|--------------------------------------------------------------------------
*/

$rootReal = realpath(__DIR__ . '/..');
$rootDir = $rootReal !== false
    ? str_replace('\\', '/', $rootReal)
    : '';

$cssCandidates = [];

if ($rootDir !== '') {
    $cssCandidates = [
        $rootDir . '/assets/css/yesnet.css',
        $rootDir . '/assets/yesnet.css',
        $rootDir . '/css/yesnet.css',
        $rootDir . '/yesnet.css',
        $rootDir . '/public/assets/css/yesnet.css',
        $rootDir . '/customer/assets/css/yesnet.css',
    ];

    foreach (glob($rootDir . '/*/assets/css/yesnet.css') ?: [] as $file) {
        $cssCandidates[] = str_replace('\\', '/', $file);
    }
}

$cssFile = null;

foreach (array_unique($cssCandidates) as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $realFile = realpath($candidate);

        if ($realFile !== false) {
            $cssFile = str_replace('\\', '/', $realFile);
            break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ubah lokasi file menjadi URL
|--------------------------------------------------------------------------
| Jangan menganggap folder proyek selalu sama dengan document root.
|--------------------------------------------------------------------------
*/

$docRootRaw = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$docRootReal = $docRootRaw !== '' ? realpath($docRootRaw) : false;

$docRoot = $docRootReal !== false
    ? rtrim(str_replace('\\', '/', $docRootReal), '/')
    : '';

$cssUrl = null;

if ($cssFile !== null && $docRoot !== '') {
    $rootPrefix = $docRoot . '/';

    if (stripos($cssFile, $rootPrefix) === 0) {
        $relativeCss = substr($cssFile, strlen($rootPrefix));

        $segments = array_map(
            'rawurlencode',
            explode('/', $relativeCss)
        );

        $cssUrl = '/' . implode('/', $segments);
    } elseif (strcasecmp($cssFile, $docRoot) === 0) {
        $cssUrl = '/';
    }
}

/*
|--------------------------------------------------------------------------
| Versi CSS untuk menghindari cache lama
|--------------------------------------------------------------------------
*/

$cssVersion = $cssFile !== null
    ? (string) (@filemtime($cssFile) ?: time())
    : '';

/*
|--------------------------------------------------------------------------
| URL halaman publik
|--------------------------------------------------------------------------
| Gunakan URL relatif dari lokasi halaman yang memanggil header.
|--------------------------------------------------------------------------
*/

$homeUrl = 'index.php';
$coverageUrl = 'coverage.php';
$packageUrl = 'paket.php?mode=direct';
$loginUrl = 'login.php';
$dashboardUrl = 'redirect.php';

$isLoggedInCustomer = function_exists('isCustomer')
    && isCustomer();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> - YesNet</title>

    <meta name="theme-color" content="#f4f8ff">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    >

    <?php if ($cssUrl !== null): ?>
        <link
            rel="stylesheet"
            href="<?= e($cssUrl) ?>?v=<?= e($cssVersion) ?>"
        >
    <?php endif; ?>
</head>
<body>

<?php if ($cssUrl === null): ?>
    <div style="
        padding:12px 16px;
        background:#fff0f0;
        color:#a4262c;
        border:1px solid #ffd0d2;
        font:14px/1.5 system-ui,sans-serif
    ">
        <strong>CSS YesNet belum dapat dimuat.</strong>
        Periksa lokasi file <code>yesnet.css</code> dan pastikan
        file tersebut berada di dalam document root server web.
    </div>
<?php endif; ?>

<header class="navbar">
    <a href="<?= e($homeUrl) ?>" class="brand">
        <?= logoHtml() ?>
    </a>

    <nav class="nav-links" aria-label="Navigasi utama">
        <a href="<?= e($homeUrl) ?>">Beranda</a>
        <a href="<?= e($coverageUrl) ?>">Cek Coverage</a>
        <a href="<?= e($packageUrl) ?>">Paket</a>
    </nav>

    <?php if ($isLoggedInCustomer): ?>
        <a href="<?= e($dashboardUrl) ?>" class="btn btn-primary">
            Dashboard
        </a>
    <?php else: ?>
        <a href="<?= e($loginUrl) ?>" class="btn btn-primary">
            Login Customer
        </a>
    <?php endif; ?>
</header>

<main class="wrap<?= $narrow ? ' narrow' : '' ?>">

<?php if ($step !== null && $step >= 0 && $step < count($stepLabel)): ?>
    <ol
        class="stepper"
        aria-label="Progres berlangganan"
    >
        <?php foreach ($stepLabel as $index => $label): ?>
            <?php
            $stepClass = '';

            if ($index < $step) {
                $stepClass = 'done';
            } elseif ($index === $step) {
                $stepClass = 'now';
            }
            ?>

            <li
                class="<?= e($stepClass) ?>"
                aria-label="<?= e($label) ?>"
                <?= $index === $step ? 'aria-current="step"' : '' ?>
            ></li>
        <?php endforeach; ?>
    </ol>

    <div class="stepper-label">
        Langkah <?= $step + 1 ?> dari <?= count($stepLabel) ?>:
        <?= e($stepLabel[$step]) ?>
    </div>
<?php endif; ?>
