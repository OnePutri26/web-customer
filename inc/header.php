<?php
/*
| Layout publik. Variabel opsional sebelum include:
| $pageTitle (string), $step (0-5, posisi di stepper alur berlangganan)
*/
$pageTitle = $pageTitle ?? 'YesNet';
$step = $step ?? null;
$stepLabel = ['Coverage', 'Paket', 'Daftar & Verifikasi', 'Order & Bayar', 'Instalasi', 'Aktif'];

/*
| Cari yesnet.css di beberapa lokasi umum, lalu buat URL dari posisinya terhadap
| document root (bukan relatif terhadap halaman yang sedang dibuka).
*/
$rootDir = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
$cssCandidates = [
    $rootDir . '/assets/css/yesnet.css',
    $rootDir . '/assets/yesnet.css',
    $rootDir . '/css/yesnet.css',
    $rootDir . '/public/assets/css/yesnet.css',
    $rootDir . '/customer/assets/css/yesnet.css',
];
foreach (glob($rootDir . '/*/assets/css/yesnet.css') ?: [] as $g) {
    $cssCandidates[] = str_replace('\\', '/', $g);
}
$cssFile = null;
foreach ($cssCandidates as $c) {
    if (is_file($c)) {
        $cssFile = $c;
        break;
    }
}

$docRaw = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$docRoot = $docRaw !== '' ? rtrim(str_replace('\\', '/', (string) realpath($docRaw)), '/') : '';
$cssUrl = 'assets/css/yesnet.css';
if ($cssFile !== null && $docRoot !== '' && stripos($cssFile, $docRoot) === 0) {
    $cssUrl = substr($cssFile, strlen($docRoot));
}
$cssVer = $cssFile !== null ? (int) @filemtime($cssFile) : 0;

/* Info diagnostik hanya untuk akses dari komputer lokal: tambahkan ?debugcss=1 di URL */
$isLocal = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$debugCss = $isLocal && isset($_GET['debugcss']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - YesNet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;700;800&display=swap">
    <link rel="stylesheet" href="<?= e($cssUrl) ?><?= $cssVer ? '?v=' . $cssVer : '' ?>">
</head>
<body>
<?php if ($cssFile === null): ?>
    <div style="background:#fef3f2;border:1px solid #fecdca;color:#b42318;padding:12px 16px;font:14px/1.5 system-ui,sans-serif">
        File CSS <b>yesnet.css</b> tidak ditemukan. Letakkan di folder <b>assets/css/</b> pada folder utama project.
    </div>
<?php endif; ?>
<?php if ($debugCss): ?>
    <pre style="background:#0a1a33;color:#d6e4ff;padding:14px 16px;margin:0;font:12.5px/1.6 monospace;white-space:pre-wrap">DIAGNOSTIK CSS
folder project : <?= e($rootDir) ?>

document root  : <?= e($docRoot) ?>

URL CSS        : <?= e($cssUrl) ?>

file CSS       : <?= e($cssFile ?? '(tidak ditemukan)') ?>

Lokasi yang dicek:
<?php foreach ($cssCandidates as $c): ?>
 - <?= is_file($c) ? '[ADA]   ' : '[tidak] ' ?><?= e($c) ?>

<?php endforeach; ?></pre>
<?php endif; ?>
<header class="navbar">
    <a href="index.php" class="brand"><?= logoHtml() ?></a>
    <nav class="nav-links">
        <a href="index.php">Beranda</a>
        <a href="coverage.php">Cek Coverage</a>
        <a href="paket.php?mode=direct">Paket</a>
    </nav>
    <?php if (isCustomer()): ?>
        <a href="redirect.php" class="btn btn-primary">Dashboard</a>
    <?php else: ?>
        <a href="login.php" class="btn btn-primary">Login Customer</a>
    <?php endif; ?>
</header>
<main class="wrap<?= !empty($narrow) ? ' narrow' : '' ?>">
<?php if ($step !== null): ?>
    <ol class="stepper" aria-hidden="true">
        <?php foreach ($stepLabel as $i => $l): ?>
            <li class="<?= $i < $step ? 'done' : ($i === $step ? 'now' : '') ?>"></li>
        <?php endforeach; ?>
    </ol>
    <div class="stepper-label">Langkah <?= $step + 1 ?> dari 6: <?= e($stepLabel[$step]) ?></div>
<?php endif; ?>