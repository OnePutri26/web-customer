<?php
/*
| Layout publik. Variabel opsional sebelum include:
| $pageTitle (string), $step (0-5, posisi di stepper alur berlangganan)
*/
$pageTitle = $pageTitle ?? 'YesNet';
$step = $step ?? null;
$stepLabel = ['Coverage', 'Paket', 'Daftar & Verifikasi', 'Order & Bayar', 'Instalasi', 'Aktif'];

/*
| URL stylesheet dihitung dari lokasi folder project terhadap document root,
| bukan relatif terhadap halaman yang sedang dibuka, sehingga CSS tetap termuat
| walau halaman diakses dari folder/URL yang berbeda (mis. /project/detail_paket.php).
*/
$cssFile = realpath(__DIR__ . '/../assets/css/yesnet.css');
$rootDir = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
$docRaw = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$docRoot = $docRaw !== '' ? rtrim(str_replace('\\', '/', (string) realpath($docRaw)), '/') : '';
$cssUrl = ($docRoot !== '' && stripos($rootDir, $docRoot) === 0)
    ? substr($rootDir, strlen($docRoot)) . '/assets/css/yesnet.css'
    : 'assets/css/yesnet.css';
$cssVer = $cssFile ? (int) @filemtime($cssFile) : 0;
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