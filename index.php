<?php
require_once __DIR__ . '/config/app.php';

try {
    $pakets = listPaket($conn, 4);
} catch (Throwable $e) {
    $pakets = [];
}
$heroFoto = file_exists(__DIR__ . '/assets/img/hero.jpg') ? 'assets/img/hero.jpg' : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="YesNet - Internet fiber optic cepat, stabil dan terjangkau untuk rumah, keluarga, dan bisnis.">
    <title>YesNet - Internet Cepat &amp; Stabil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,700;0,800;1,500&display=swap">
    <link rel="stylesheet" href="assets/css/yesnet.css?v=<?= @filemtime(__DIR__ . '/assets/css/yesnet.css') ?>">
    <link rel="stylesheet" href="landing.css?v=<?= @filemtime(__DIR__ . '/landing.css') ?>">
</head>
<body class="landing">

<header class="navbar">
    <a href="#home" class="brand"><?= logoHtml() ?></a>
    <nav class="nav-links">
        <a href="#home" class="active">Home</a>
        <a href="#keunggulan">Keunggulan</a>
        <a href="#paket">Paket</a>
        <a href="#kontak">Kontak</a>
    </nav>
    <?php if (isCustomer()): ?>
        <a href="redirect.php" class="btn btn-primary">Dashboard</a>
    <?php else: ?>
        <a href="login.php" class="btn btn-primary">Login Customer</a>
    <?php endif; ?>
</header>

<!-- ================= HERO ================= -->
<section id="home" class="hero">
    <div class="hero-text">
        <span class="chip hero-in" style="--d:0s">Internet untuk Hidup yang Lebih Baik</span>
        <h1 class="hero-in" style="--d:.1s">YesNet - Internet <span>Cepat &amp; Stabil</span></h1>
        <p class="hero-in" style="--d:.2s">Nikmati koneksi internet fiber optic dengan kecepatan tinggi, stabil, dan harga terjangkau. Untuk rumah, keluarga, dan bisnis Anda.</p>
        <div class="hero-actions hero-in" style="--d:.3s">
            <a class="btn btn-primary" href="coverage.php">Cek Coverage</a>
            <a class="btn" href="paket.php?mode=direct">Lihat Paket</a>
            <a class="btn" href="login.php">Login Customer</a>
        </div>
        <div class="hero-stats hero-in" style="--d:.4s">
            <span><b>99.9%</b>Uptime Jaringan</span>
            <span><b>Layanan 24/7</b>Siap membantu</span>
            <span><b>Tim Teknis</b>Profesional</span>
        </div>
    </div>

    <div class="hero-art hero-in" style="--d:.2s">
        <p class="hero-note">Koneksi Stabil<br>untuk Masa Depan<br>Lebih Baik</p>
        <?php if ($heroFoto): ?>
            <img src="<?= e($heroFoto) ?>" alt="Rumah dengan koneksi internet YesNet" class="hero-photo">
        <?php else: ?>
            <svg viewBox="0 0 520 380" role="img" aria-label="Rumah dengan sinyal WiFi">
                <g fill="none" stroke="#2f8bff" stroke-width="7" stroke-linecap="round">
                    <path class="arc" d="M200 120a85 85 0 01120 0"/>
                    <path class="arc" d="M222 142a52 52 0 0176 0"/>
                    <path class="arc" d="M244 164a20 20 0 0132 0"/>
                </g>
                <circle cx="260" cy="190" r="6" fill="#2f8bff"/>
                <ellipse class="ring" cx="260" cy="322" rx="232" ry="38" fill="none" stroke="#7a5cff" stroke-width="4"/>
                <rect x="60" y="250" width="50" height="68" rx="3" fill="#9fb4de"/>
                <rect x="415" y="235" width="42" height="84" rx="3" fill="#9fb4de"/>
                <rect x="150" y="215" width="220" height="104" rx="4" fill="#f4e9d6"/>
                <polygon points="128,222 260,160 392,222 372,234 148,234" fill="#2a3f6e"/>
                <rect class="win" x="170" y="244" width="60" height="50" rx="2" fill="#ffc861"/>
                <rect class="win" x="290" y="244" width="60" height="50" rx="2" fill="#ffc861"/>
                <rect x="240" y="258" width="30" height="61" rx="2" fill="#5b4a3a"/>
            </svg>
        <?php endif; ?>
    </div>
</section>

<!-- ================= KEUNGGULAN ================= -->
<section id="keunggulan" class="section">
    <div class="features">
        <?php foreach ([
            ['⚡', 'Kecepatan Tinggi', 'Streaming, gaming, dan bekerja lebih lancar tanpa hambatan.'],
            ['🛡️', 'Jaringan Stabil', 'Koneksi tetap stabil di segala aktivitas Anda.'],
            ['📶', 'Fiber Optic', 'Teknologi modern untuk internet terbaik.'],
            ['🏷️', 'Harga Terjangkau', 'Paket lengkap dengan harga bersahabat.'],
            ['🎧', 'Layanan Pelanggan', 'Siap membantu kapan saja saat Anda membutuhkan.'],
        ] as $f): ?>
            <div class="feature reveal">
                <span class="ic"><?= $f[0] ?></span>
                <b><?= e($f[1]) ?></b>
                <small><?= e($f[2]) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ================= CEK COVERAGE ================= -->
<section id="coverage" class="section">
    <div class="coverage reveal">
        <div>
            <div class="coverage-title">
                <span class="ic">📍</span>
                <div>
                    <h2>Cek Coverage</h2>
                    <p>Pastikan alamat Anda sudah terjangkau jaringan YesNet.</p>
                </div>
            </div>
            <form action="coverage.php" method="get" class="coverage-form">
                <input type="hidden" name="auto" value="1">
                <input class="in" type="text" name="alamat" placeholder="Masukkan alamat lengkap Anda..." required minlength="10" aria-label="Alamat lengkap">
                <button class="btn btn-primary" type="submit">Cek Sekarang</button>
            </form>
            <ol class="coverage-steps">
                <li><b>Masukkan Alamat</b><small>Ketik alamat lengkap rumah Anda</small></li>
                <li><b>Cek Ketersediaan</b><small>Kami akan mengecek jaringan di lokasi Anda</small></li>
                <li><b>Pilih Paket</b><small>Dapatkan rekomendasi paket terbaik</small></li>
            </ol>
        </div>
        <div class="coverage-art" aria-hidden="true">
            <span class="coverage-badge">✓ Jaringan YesNet<br><small>Tersedia di Lokasi Anda</small></span>
            <span class="coverage-pin">📍</span>
        </div>
    </div>
</section>

<!-- ================= PAKET ================= -->
<section id="paket" class="section">
    <div class="section-head">
        <span class="chip">Paket Internet</span>
        <h2>Pilih Paket yang Sesuai dengan Kebutuhan Anda</h2>
        <p>Berbagai pilihan paket dengan kecepatan terbaik untuk rumah, keluarga, dan bisnis.</p>
    </div>
    <?php if ($pakets): ?>
        <?= renderPaketCards($pakets, 'paket.php?mode=direct') ?>
    <?php else: ?>
        <p class="center muted" style="margin-top:24px">Paket belum tersedia. Silakan hubungi kami.</p>
    <?php endif; ?>
</section>

<!-- ================= PROSES ================= -->
<section class="section proses">
    <div class="section-head">
        <h2>Proses Berlangganan Mudah &amp; Cepat</h2>
        <p>Dari pendaftaran hingga instalasi, semua bisa dilakukan dengan mudah.</p>
    </div>
    <ol class="proses-list">
        <?php foreach ([
            ['🔍', 'Cek Coverage', 'Pastikan alamat Anda terjangkau jaringan YesNet'],
            ['📝', 'Daftar & Pilih Paket', 'Isi data diri dan pilih paket internet yang diinginkan'],
            ['💳', 'Lakukan Pembayaran', 'Pilih metode pembayaran yang tersedia'],
            ['🔧', 'Instalasi & Aktivasi', 'Teknisi kami akan datang ke lokasi Anda'],
            ['📶', 'Nikmati Internet', 'Koneksi aktif, siap digunakan sepanjang hari'],
        ] as $i => $s): ?>
            <li class="reveal" style="--d:<?= $i * .08 ?>s">
                <span class="ic"><?= $s[0] ?></span>
                <b><?= ($i + 1) . '. ' . e($s[1]) ?></b>
                <small><?= e($s[2]) ?></small>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<!-- ================= FOOTER ================= -->
<footer id="kontak" class="footer">
    <div>
        <a href="#home" class="brand" style="color:#fff"><?= logoHtml() ?></a>
        <p>Internet Cepat &amp; Stabil</p>
    </div>
    <div>
        <b>Tautan Cepat</b>
        <a href="#home">Home</a><a href="#keunggulan">Keunggulan</a><a href="#paket">Paket</a><a href="#kontak">Kontak</a>
    </div>
    <div>
        <b>Hubungi Kami</b>
        <span>📞 0812 3456 7890</span>
        <span>✉️ cs@yesnet.my.id</span>
        <span>📍 Jl. Merdeka No. 123, Kota Anda</span>
    </div>
    <div class="footer-copy">© <?= date('Y') ?> YesNet. All rights reserved.</div>
</footer>

<script src="assets/js/landing.js"></script>
</body>
</html>
