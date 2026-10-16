<?php
/* Config dimuat lebih dulu: biasanya sudah memulai session & koneksi database ($conn). */
$cfgFile = __DIR__ . '/config/app.php';
if (is_file($cfgFile)) {
    require_once $cfgFile;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Cari lokasi halaman aplikasi secara otomatis: di folder website (root) atau di folder customer/.
 * Jadi tautan tetap benar walau file coverage/paket/pembayaran/login ada di salah satu folder itu.
 */
if (!function_exists('appUrl')) {
    function appUrl(string $file): string
    {
        foreach (['', 'customer/'] as $dir) {
            if (is_file(__DIR__ . '/' . $dir . $file)) {
                return $dir . $file;
            }
        }
        return $file;
    }
}

$loggedIn   = !empty($_SESSION['user_id']);
$loginUrl   = appUrl($loggedIn ? 'redirect.php' : 'login.php');
$loginLabel = $loggedIn ? 'Dashboard Saya' : 'Login Customer';

/* Kontak: ganti dengan nomor asli. Format WhatsApp memakai kode negara tanpa "+" atau "0" di depan. */
$telp      = '6285604145030';
$waNumber  = '6285604145030';
$email     = 'cs@yesnet.my.id';
$alamat    = 'Gedung Cyber 1, Lantai 10, Jl. Kuningan Barat No. 8, Jakarta Selatan, 12710';
$alamat    = 'Jl. Nginden Intan Timur V/1A Kav. 26, Nginden Jangkungan, Sukolilo, Surabaya, Jawa Timur, 60118';
$waUrl     = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode('Halo YesNet, saya ingin berlangganan internet. Mohon info paket yang tersedia untuk alamat saya.');

$cssVer = @filemtime(__DIR__ . '/landing.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/landing.js') ?: 1;

/*
 * Paket diambil langsung dari tabel paket_wifi, sehingga ID, nama, kecepatan dan harga
 * SELALU sama dengan halaman pembayaran (pembayaran.php?paket=ID memakai ID angka ini).
 */
$daftarPaket = [];
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $res = $conn->query('SELECT * FROM paket_wifi ORDER BY speed_mbps ASC, id ASC');
        while ($res && ($row = $res->fetch_assoc())) {
            $off = false;
            foreach (['status', 'status_paket'] as $k) {
                if (isset($row[$k]) && in_array(strtolower((string) $row[$k]), ['nonaktif', 'non-aktif', 'tidak aktif', 'inactive', 'disabled', '0'], true)) {
                    $off = true;
                }
            }
            foreach (['aktif', 'is_active'] as $k) {
                if (array_key_exists($k, $row) && $row[$k] !== null && (string) $row[$k] === '0') {
                    $off = true;
                }
            }
            if (!$off) {
                $daftarPaket[] = $row;
            }
        }
    }
} catch (Throwable $e) {
    error_log('Landing paket: ' . $e->getMessage());
}

/* Cadangan jika tabel tidak terbaca (harga tidak ditampilkan, tombol tetap mengarah ke ID yang benar) */
if (!$daftarPaket) {
    $daftarPaket = [
        ['id' => 1, 'nama_paket' => 'Paket Basic',    'speed_mbps' => 10],
        ['id' => 2, 'nama_paket' => 'Paket Standard', 'speed_mbps' => 20],
        ['id' => 3, 'nama_paket' => 'Paket Premium',  'speed_mbps' => 50],
        ['id' => 4, 'nama_paket' => 'Paket Ultra',    'speed_mbps' => 100],
    ];
}

$descDefault = [
    'Untuk browsing dan sosmed sehari-hari',
    'Ideal untuk keluarga dan kerja dari rumah',
    'Untuk streaming dan gaming yang lebih lancar',
    'Performa terbaik untuk bisnis dan banyak perangkat',
];
$ikonRank = ['box', 'users', 'gem', 'tool'];

$paket = [];
$maxMbps = 1;
foreach ($daftarPaket as $i => $row) {
    $harga = null;
    foreach ($row as $k => $v) {
        if (preg_match('/^(harga|price|tarif|biaya)/i', (string) $k) && is_numeric($v) && (float) $v > 0) {
            $harga = number_format((float) $v, 0, ',', '.');
            break;
        }
    }
    $desc = '';
    foreach (['deskripsi', 'keterangan', 'deskripsi_paket'] as $k) {
        if (!empty($row[$k]) && is_string($row[$k])) {
            $desc = trim(mb_substr(strip_tags($row[$k]), 0, 70));
            break;
        }
    }
    $mbps = (int) ($row['speed_mbps'] ?? 0);
    $maxMbps = max($maxMbps, $mbps);
    $paket[] = [
        'id'    => (int) $row['id'],
        'nama'  => (string) ($row['nama_paket'] ?? ('Paket ' . ($i + 1))),
        'desc'  => $desc !== '' ? $desc : $descDefault[min($i, 3)],
        'mbps'  => $mbps,
        'harga' => $harga,
        'icon'  => $ikonRank[min($i, 3)],
        'kelas' => $i === 1 ? 'popular' : ($i === 2 ? 'premium' : ($i >= 3 ? 'business' : '')),
    ];
}

/* Pemilih kebutuhan -> paket menurut urutan kecepatan (terendah ke tertinggi) */
$pickId = static function (int $rank) use ($paket): int {
    return $paket[min($rank, count($paket) - 1)]['id'];
};
$kebutuhan = [
    [$pickId(0), 'Browsing & Sosmed',  'globe'],
    [$pickId(1), 'Keluarga',           'users'],
    [$pickId(2), 'Streaming & Gaming', 'play'],
    [$pickId(3), 'Bisnis & Kantor',    'briefcase'],
];

$fitur = [
    ['bolt',    'Kecepatan Tinggi',  'Streaming, gaming, dan bekerja lebih lancar tanpa hambatan.'],
    ['shield',  'Jaringan Stabil',   'Koneksi tetap stabil di segala aktivitas Anda.'],
    ['wifi',    'Fiber Optic',       'Teknologi modern untuk internet terbaik.'],
    ['tag',     'Harga Terjangkau',  'Paket lengkap dengan harga bersahabat.'],
    ['headset', 'Layanan Pelanggan', 'Siap membantu kapan saja saat Anda membutuhkan.'],
];

$alur = [
    ['Cek Coverage', appUrl('coverage.php'), 'pin', ['Masukkan alamat','Cek ketersediaan','Tersedia / tidak tersedia','Pilih paket / form request','Detail paket','Register customer','Verifikasi data','Order berlangganan','Pembayaran','Berhasil / gagal','Order diproses / bayar lagi','Survey / validasi','Jadwal instalasi','Teknisi datang','Instalasi selesai','Aktivasi customer','Customer aktif','Dashboard']],
    ['Lihat Paket', appUrl('paket.php'), 'box', ['Pilih paket','Pembayaran']],
    [$loginLabel, $loginUrl, 'user', ['Login','Dashboard']],
];

$faq = [
    ['Bagaimana cara berlangganan YesNet?', 'Cek coverage dengan memasukkan alamat Anda, pilih paket, daftar sebagai customer, lalu lakukan pembayaran. Setelah data diverifikasi, tim kami melakukan survei dan menjadwalkan instalasi.'],
    ['Apakah ada biaya instalasi?', 'Semua paket sudah termasuk instalasi gratis dan WiFi router.'],
    ['Bagaimana jika alamat saya belum terjangkau?', 'Anda dapat mengirim form request melalui halaman Cek Coverage agar area Anda dapat kami pertimbangkan.'],
    ['Paket mana yang cocok untuk saya?', 'Untuk browsing dan sosmed, paket dengan kecepatan lebih rendah sudah cukup. Untuk keluarga, streaming, dan gaming, pilih kecepatan yang lebih tinggi. Gunakan pemilih kebutuhan di bagian Paket untuk rekomendasi cepat.'],
    ['Bagaimana memantau layanan setelah aktif?', 'Masuk melalui menu Login Customer untuk membuka dashboard Anda.'],
    ['Ke mana saya bisa bertanya sebelum berlangganan?', 'Hubungi layanan pelanggan kami lewat WhatsApp, telepon, atau email. Kontaknya ada di bagian bawah halaman ini.'],
];

if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('ic')) { function ic($n, $c = '') { return '<svg class="ic' . ($c ? ' ' . $c : '') . '" aria-hidden="true"><use href="#i-' . $n . '"/></svg>'; } }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YesNet - Internet Cepat &amp; Stabil untuk Rumah dan Bisnis</title>
<meta name="description" content="YesNet - Internet fiber optic cepat, stabil dan terjangkau untuk rumah, keluarga dan bisnis. Cek coverage dan berlangganan online.">
<meta name="theme-color" content="#1f62ff">
<meta property="og:type" content="website">
<meta property="og:title" content="YesNet - Internet Cepat &amp; Stabil">
<meta property="og:description" content="Internet fiber optic untuk rumah, keluarga dan bisnis. Instalasi gratis. Cek coverage sekarang.">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%231f62ff'/%3E%3Cg fill='none' stroke='white' stroke-width='5' stroke-linecap='round'%3E%3Cpath d='M12 26a28 28 0 0 1 40 0'/%3E%3Cpath d='M20 34a17 17 0 0 1 24 0'/%3E%3Cpath d='M27 42a7 7 0 0 1 10 0'/%3E%3C/g%3E%3Ccircle cx='32' cy='50' r='3.5' fill='white'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="landing.css?v=<?= $cssVer ?>">
<script>
(function (d) {
  d.classList.add('js');
  try { if (!sessionStorage.getItem('yn_seen') && !location.hash) d.classList.add('loading'); } catch (e) {}
  /* jaga-jaga: jika landing.js gagal dimuat, tampilkan konten apa adanya */
  setTimeout(function () { if (!d.classList.contains('ready')) d.classList.remove('js', 'loading'); }, 7000);
})(document.documentElement);
</script>
</head>
<body>

<a class="skip-link" href="#paket">Lewati ke paket</a>

<!-- INTRO -->
<div id="loader" aria-hidden="true">
  <div class="ld-inner">
    <svg viewBox="0 0 34 34" class="ld-logo"><path d="M3 12a20 20 0 0128 0"/><path d="M8 18a13 13 0 0118 0"/><path d="M13 24a6 6 0 018 0"/><circle cx="17" cy="29" r="1.8"/></svg>
    <div class="ld-word"><?php foreach (str_split('YESNET') as $i => $c): ?><span style="--i:<?= $i ?>"><?= $c ?></span><?php endforeach; ?></div>
    <div class="ld-tag">Internet Cepat &amp; Stabil</div>
    <div class="ld-bar"><i></i></div>
    <div class="ld-pct">0%</div>
  </div>
</div>

<!-- SPRITE IKON -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
<symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 8.8a15 15 0 0 1 20 0M5.5 12.3a10 10 0 0 1 13 0M9 15.7a5 5 0 0 1 6 0"/><circle cx="12" cy="19" r="1" fill="currentColor"/></symbol>
<symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1"/></symbol>
<symbol id="i-headset" viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1"/><rect x="17" y="14" width="4" height="6" rx="1"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 22s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2A6.5 6.5 0 0 1 21.5 20"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5zM3 13l9 5 9-5"/></symbol>
<symbol id="i-gem" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 12L2 9zM2 9h20"/></symbol>
<symbol id="i-tool" viewBox="0 0 24 24"><path d="M14.5 6.5a4 4 0 0 0 5 5L21 13l-8 8-4-4 8-8zM3 21l5-5"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-5-5"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
<symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
<symbol id="i-chat" viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-5.8A8 8 0 1 1 21 12z"/><path d="M9 10c.5 2 2 3.500 4.500 4.500l1.500-1.500-2-1-1 .8a4 4 0 0 1-1.800-1.800l.8-1-1-2z"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.500 1.500a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
<symbol id="i-play" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M10 8.500v5l4-2.500zM8 21h8"/></symbol>
<symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/></symbol>
<symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></symbol>
<symbol id="i-chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
<symbol id="i-sparkle" viewBox="0 0 24 24"><path d="m12 3 2 5.500L19.500 10 14 12l-2 5.500L10 12 4.500 10 10 8.500z"/></symbol>
<symbol id="i-laptop" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 20h20"/></symbol>
<symbol id="i-mobile" viewBox="0 0 24 24"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></symbol>
<symbol id="i-game" viewBox="0 0 24 24"><path d="M7 8h10a4.500 4.500 0 0 1 4.400 5.400l-.7 3.200a2.500 2.500 0 0 1-4.300 1.100L14.500 16h-5l-1.900 1.700a2.500 2.500 0 0 1-4.300-1.100l-.7-3.200A4.500 4.500 0 0 1 7 8z"/><path d="M8 10.500v3M6.500 12h3"/><circle cx="15.500" cy="11" r=".6"/><circle cx="17.500" cy="13" r=".6"/></symbol>
</svg>

<!-- NAVBAR -->
<header class="navbar">
  <div class="nav-inner">
    <a href="#home" class="brand" aria-label="YesNet beranda"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>

    <nav class="nav-center" id="menu" aria-label="Menu utama">
      <a href="#home" class="active">Home</a>
      <a href="#keunggulan">Keunggulan</a>
      <a href="#paket">Paket</a>
      <a href="#alur">Cara Daftar</a>
      <a href="#faq">FAQ</a>
      <a href="#kontak">Kontak</a>
      <a href="<?= e($loginUrl) ?>" class="only-mobile"><?= ic('user') ?><?= e($loginLabel) ?></a>
    </nav>

    <div class="nav-actions">
      <a href="<?= e($loginUrl) ?>" class="nav-login"><?= ic('user') ?><?= e($loginLabel) ?></a>
      <a href="#paket" class="btn btn-primary btn-sm">Berlangganan</a>
      <button class="nav-toggle" type="button" aria-label="Buka menu" aria-expanded="false" aria-controls="menu"><?= ic('menu', 'i-open') ?><?= ic('close', 'i-shut') ?></button>
    </div>
  </div>
</header>

<main>
<!-- HERO -->
<section id="home" class="hero">
  <div class="hero-content container">
    <div class="hero-text">
      <div class="eyebrow" data-in style="--i:0"><span class="dot"></span>Internet Fiber Optic untuk Rumah &amp; Bisnis</div>
      <h1 data-in style="--i:1">Internet <span class="grad">Cepat &amp; Stabil</span> dengan Harga Terjangkau</h1>
      <p class="hero-description" data-in style="--i:2">Nikmati koneksi fiber optic berkecepatan tinggi untuk rumah, keluarga, dan bisnis Anda. Instalasi gratis di semua paket.</p>

      <form class="hero-check" action="<?= e(appUrl('coverage.php')) ?>" method="get" data-coverage data-in style="--i:3">
        <input type="hidden" name="auto" value="1">
        <label class="check-field"><?= ic('pin') ?>
          <span class="sr-only">Alamat pemasangan</span>
          <input type="text" name="alamat" placeholder="Masukkan alamat pemasangan Anda…" required minlength="10" autocomplete="street-address">
        </label>
        <button type="submit" class="btn btn-primary btn-lg"><span>Cek Coverage</span><?= ic('arrow') ?></button>
      </form>
      <p class="hero-hint" data-in style="--i:3">Masukkan alamat untuk melihat apakah jaringan YesNet sudah tersedia di lokasi Anda.</p>

      <div class="hero-actions" data-in style="--i:4">
        <a href="#paket" class="btn btn-outline"><?= ic('box') ?>Lihat Paket</a>
        <a href="<?= e($loginUrl) ?>" class="btn-link">Sudah jadi pelanggan? <?= e($loginLabel) ?> <?= ic('arrow') ?></a>
      </div>

      <ul class="hero-stats" data-in style="--i:5">
        <li class="hero-stat"><?= ic('wifi') ?><div><strong><span data-count="99.9" data-dec="1">99,9</span>%</strong><small>Uptime Jaringan</small></div></li>
        <li class="hero-stat"><?= ic('headset') ?><div><strong>Layanan</strong><small>24/7</small></div></li>
        <li class="hero-stat"><?= ic('shield') ?><div><strong>Tim Teknisi</strong><small>Profesional</small></div></li>
      </ul>
    </div>

    <div class="hero-visual">
      <div class="net-stage" data-in style="--i:2" role="img" aria-label="Ilustrasi jaringan YesNet yang menghubungkan laptop, ponsel, TV, dan konsol game">
        <div class="net-glow"></div>
        <div class="orbit o3" style="--t:46s"><i class="sat"></i></div>
        <div class="orbit o2" style="--t:34s">
          <div class="node n-laptop" style="--t:34s;left:0;top:50%"><?= ic('laptop') ?></div>
          <div class="node n-tv" style="--t:34s;left:100%;top:50%"><?= ic('play') ?></div>
        </div>
        <div class="orbit o1" style="--t:24s">
          <div class="node n-phone" style="--t:24s;left:50%;top:0"><?= ic('mobile') ?></div>
          <div class="node n-game" style="--t:24s;left:50%;top:100%"><?= ic('game') ?></div>
        </div>
        <div class="core"><?= ic('wifi') ?></div>

        <div class="speed-card">
          <div class="sc-top"><span>Kecepatan hingga</span><em>Fiber Optic</em></div>
          <div class="sc-num"><b data-count="<?= (int) $maxMbps ?>"><?= (int) $maxMbps ?></b><small>Mbps</small></div>
          <div class="sc-bars" aria-hidden="true">
            <?php foreach ([38,62,48,80,56,92,70,100,64,84,52,76] as $k => $h): ?><i style="--h:<?= $h ?>%;--d:<?= round($k * 0.12, 2) ?>s"></i><?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="float-chip c1"><i><?= ic('bolt') ?></i><div><strong>Hingga <?= (int) $maxMbps ?> Mbps</strong><small>Fiber optic</small></div></div>
      <div class="float-chip c2"><i class="ok"><?= ic('check') ?></i><div><strong>Instalasi Gratis</strong><small>Semua paket</small></div></div>
      <div class="float-chip c3"><i class="vi"><?= ic('shield') ?></i><div><strong>Jaringan Stabil</strong><small>Siap 24/7</small></div></div>
    </div>
  </div>
</section>

<!-- KEUNGGULAN -->
<section id="keunggulan" class="section advantages">
  <div class="container">
    <div class="section-heading reveal">
      <span class="section-label">Kenapa YesNet</span>
      <h2>Internet yang <span class="grad">Bisa Diandalkan</span> Setiap Hari</h2>
      <p>Dari kerja, belajar, sampai hiburan keluarga, semuanya lebih lancar.</p>
    </div>
    <div class="advantage-grid">
      <?php foreach ($fitur as $f): ?>
      <div class="advantage-card reveal tilt">
        <div class="advantage-icon"><?= ic($f[0]) ?></div>
        <h3><?= e($f[1]) ?></h3><p><?= e($f[2]) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- PAKET -->
<section id="paket" class="section packages-section">
  <div class="container">
    <div class="section-heading reveal">
      <span class="section-label">Paket Internet</span>
      <h2>Pilih Paket yang <span class="grad">Sesuai Kebutuhan</span> Anda</h2>
      <p>Semua paket sudah termasuk Internet Unlimited, WiFi Router, dan Instalasi Gratis.</p>
    </div>

    <div class="picker reveal">
      <div class="picker-title"><?= ic('sparkle') ?>Bingung memilih? Pilih kebutuhan Anda:</div>
      <div class="chips" role="group" aria-label="Kebutuhan internet">
        <?php foreach ($kebutuhan as $k): ?>
        <button type="button" class="chip" data-pick="<?= e($k[0]) ?>" aria-pressed="false"><?= ic($k[2]) ?><?= e($k[1]) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="picker-result" id="pick-result" aria-live="polite"></p>
    </div>

    <div class="packages-grid">
      <?php foreach ($paket as $p): ?>
      <article class="package-card reveal tilt <?= e($p['kelas']) ?>" data-id="<?= e($p['id']) ?>" data-name="<?= e($p['nama']) ?>" data-speed="<?= (int)$p['mbps'] ?>" data-price="<?= e($p['harga'] ?? '') ?>">
        <?php if ($p['kelas'] === 'popular'): ?><div class="popular-label">Paling Populer</div><?php endif; ?>
        <div class="package-top">
          <div class="package-icon"><?= ic($p['icon']) ?></div>
          <div><h3><?= e($p['nama']) ?></h3><small><?= e($p['desc']) ?></small></div>
        </div>
        <div class="package-speed"><?= ic('wifi') ?><span><b data-count="<?= (int)$p['mbps'] ?>"><?= (int)$p['mbps'] ?></b> Mbps</span></div>
        <div class="speed-bar" aria-hidden="true"><i style="--w:<?= round($p['mbps'] / $maxMbps * 100) ?>%"></i></div>
        <div class="package-price"><?php if ($p['harga'] !== null): ?><span class="cur">Rp</span><?= e($p['harga']) ?><small>/bulan</small><?php else: ?><span class="cur">Hubungi kami</span><?php endif; ?></div>
        <ul>
          <li><?= ic('check') ?>Internet Unlimited</li>
          <li><?= ic('check') ?>WiFi Router</li>
          <li><?= ic('check') ?>Instalasi Gratis</li>
        </ul>
        <a href="<?= e(appUrl('pembayaran.php')) ?>?paket=<?= e($p['id']) ?>" class="package-button" aria-label="Pilih paket <?= e($p['nama']) ?>">Pilih Paket <?= ic('arrow') ?></a>
      </article>
      <?php endforeach; ?>
    </div>

    <p class="packages-note reveal">Butuh bantuan memilih? <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener">Chat kami lewat WhatsApp</a></p>
  </div>
</section>

<!-- CARA BERLANGGANAN -->
<section id="alur" class="section steps-section">
  <div class="container">
    <div class="section-heading reveal">
      <span class="section-label">Cara Berlangganan</span>
      <h2>Berlangganan YesNet <span class="grad">Semudah 3 Langkah</span></h2>
      <p>Cukup cek alamat, pilih paket, lalu tunggu teknisi kami datang.</p>
    </div>

    <ol class="steps">
      <li class="step reveal">
        <span class="step-num">1</span>
        <div class="step-icon"><?= ic('pin') ?></div>
        <h3>Cek Coverage</h3>
        <p>Masukkan alamat Anda untuk memastikan jaringan YesNet tersedia di lokasi Anda.</p>
        <a href="<?= e(appUrl('coverage.php')) ?>" class="step-link">Cek sekarang <?= ic('arrow') ?></a>
      </li>
      <li class="step reveal">
        <span class="step-num">2</span>
        <div class="step-icon"><?= ic('box') ?></div>
        <h3>Pilih Paket &amp; Daftar</h3>
        <p>Pilih paket yang sesuai, daftar sebagai customer, lalu lakukan pembayaran.</p>
        <a href="#paket" class="step-link">Lihat paket <?= ic('arrow') ?></a>
      </li>
      <li class="step reveal">
        <span class="step-num">3</span>
        <div class="step-icon"><?= ic('wifi') ?></div>
        <h3>Instalasi &amp; Aktif</h3>
        <p>Teknisi memasang sesuai jadwal. Setelah aktif, pantau layanan lewat dashboard.</p>
        <a href="<?= e($loginUrl) ?>" class="step-link"><?= e($loginLabel) ?> <?= ic('arrow') ?></a>
      </li>
    </ol>

    <details class="flow-details reveal">
      <summary>Lihat detail alur lengkap <?= ic('chev', 'chev') ?></summary>
      <div class="flow-lanes">
        <?php foreach ($alur as $a): ?>
        <div class="flow-lane">
          <a href="<?= e($a[1]) ?>" class="flow-start"><?= ic($a[2]) ?><?= e($a[0]) ?><?= ic('arrow') ?></a>
          <ol class="flow-track">
            <?php foreach ($a[3] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?>
          </ol>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
</section>

<!-- COVERAGE -->
<section id="coverage" class="section coverage-section">
  <div class="container">
    <div class="coverage-box reveal">
      <div class="coverage-content">
        <div class="coverage-heading">
          <div class="coverage-icon"><?= ic('pin') ?></div>
          <div><h2>Cek Coverage</h2><p>Pastikan alamat Anda sudah terjangkau jaringan YesNet.</p></div>
        </div>
        <form action="<?= e(appUrl('coverage.php')) ?>" method="get" class="coverage-form" data-coverage>
          <input type="hidden" name="auto" value="1">
          <label class="coverage-input"><?= ic('search') ?>
            <span class="sr-only">Alamat lengkap</span>
            <input type="text" name="alamat" placeholder="Masukkan alamat lengkap Anda..." required minlength="10" autocomplete="street-address">
          </label>
          <button type="submit" class="btn btn-light"><span>Cek Sekarang</span><?= ic('arrow') ?></button>
        </form>
        <div class="coverage-steps">
          <div class="coverage-step"><b>1</b><div><strong>Masukkan Alamat</strong><small>Ketik alamat lengkap rumah Anda</small></div></div>
          <div class="coverage-step"><b>2</b><div><strong>Cek Ketersediaan</strong><small>Kami cek jaringan di lokasi Anda</small></div></div>
          <div class="coverage-step"><b>3</b><div><strong>Pilih Paket</strong><small>Dapatkan rekomendasi paket terbaik</small></div></div>
        </div>
      </div>
      <div class="coverage-map" aria-hidden="true">
        <div class="map-ring"></div><div class="map-ring r2"></div>
        <div class="map-pin"><?= ic('pin') ?></div>
        <div class="coverage-status"><i><?= ic('check') ?></i><div><strong>Jaringan YesNet</strong><small>Cek ketersediaan di lokasi Anda</small></div></div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section id="faq" class="section faq-section">
  <div class="container">
    <div class="section-heading reveal">
      <span class="section-label">FAQ</span>
      <h2>Pertanyaan yang <span class="grad">Sering Ditanyakan</span></h2>
      <p>Belum menemukan jawabannya? Tim kami siap membantu.</p>
    </div>
    <div class="faq-list">
      <?php foreach ($faq as $i => $q): ?>
      <details class="faq-item reveal"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= e($q[0]) ?><?= ic('chev', 'chev') ?></summary>
        <p><?= e($q[1]) ?></p>
      </details>
      <?php endforeach; ?>
    </div>

    <div class="cta-band reveal">
      <div><h3>Siap menikmati internet yang lebih cepat?</h3><p>Cek alamat Anda sekarang atau langsung pilih paket.</p></div>
      <div class="cta-actions">
        <a href="<?= e(appUrl('coverage.php')) ?>" class="btn btn-light"><?= ic('pin') ?>Cek Coverage</a>
        <a href="#paket" class="btn btn-ghost">Pilih Paket</a>
      </div>
    </div>
  </div>
</section>
</main>

<footer id="kontak">
  <div class="container">
    <div class="footer-inner">
      <div class="footer-brand">
        <a href="#home" class="brand footer-logo"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>
        <p>Internet Cepat &amp; Stabil untuk rumah, keluarga, dan bisnis Anda.</p>
        <a href="<?= e($waUrl) ?>" class="btn btn-wa btn-sm" target="_blank" rel="noopener"><?= ic('chat') ?>Chat WhatsApp</a>
      </div>
      <div class="footer-column"><h4>Tautan Cepat</h4>
        <a href="#home">Home</a><a href="#keunggulan">Keunggulan</a><a href="#paket">Paket</a><a href="#alur">Cara Daftar</a><a href="#faq">FAQ</a></div>
      <div class="footer-column"><h4>Hubungi Kami</h4>
        <a href="tel:<?= e($telp) ?>"><?= ic('phone') ?><?= e($telp) ?></a>
        <a href="mailto:<?= e($email) ?>"><?= ic('mail') ?><?= e($email) ?></a>
        <a href="<?= e(appUrl('coverage.php')) ?>"><?= ic('pin') ?><?= e($alamat) ?></a></div>
      <div class="footer-column"><h4>Ikuti Kami</h4>
        <div class="social-links"><a href="#" aria-label="Facebook">f</a>
<a href="https://www.instagram.com/yesnet.id/" target="_blank" rel="noopener noreferrer" aria-label="Instagram YesNet">ig</a>
<a href="#" aria-label="YouTube">yt</a><a href="<?= e($waUrl) ?>" aria-label="WhatsApp" target="_blank" rel="noopener">wa</a></div></div>
    </div>
    <div class="footer-bottom"><span>© <?= date('Y') ?> YesNet. All rights reserved.</span><span>Internet Cepat &amp; Stabil</span></div>
  </div>
</footer>

<!-- AKSES CEPAT -->
<a href="<?= e($waUrl) ?>" class="wa-float" target="_blank" rel="noopener" aria-label="Chat WhatsApp YesNet"><?= ic('chat') ?><span>Chat WhatsApp</span></a>
<div class="mbar" id="mbar">
  <a href="<?= e(appUrl('coverage.php')) ?>" class="btn btn-outline"><?= ic('pin') ?>Cek Coverage</a>
  <a href="#paket" class="btn btn-primary">Pilih Paket</a>
</div>

<script src="landing.js?v=<?= $jsVer ?>" defer></script>
</body>
</html>