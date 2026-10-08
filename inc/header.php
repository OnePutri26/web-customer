<?php
/*
| Layout publik. Variabel opsional sebelum include:
| $pageTitle (string), $step (0-5, posisi di stepper alur berlangganan)
*/
$pageTitle = $pageTitle ?? 'YesNet';
$step = $step ?? null;
$stepLabel = ['Coverage', 'Paket', 'Daftar & Verifikasi', 'Order & Bayar', 'Instalasi', 'Aktif'];
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
    <link rel="stylesheet" href="assets/css/yesnet.css?v=<?= @filemtime(__DIR__ . '/../assets/css/yesnet.css') ?>">
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
