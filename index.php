<?php
session_start();
$loginUrl = !empty($_SESSION['customer_id']) ? 'dashboard.php' : 'login.php';

$paket = [
    ['basic',   'Basic',    'Cocok untuk browsing dan sosmed',  20,  '200.000', 'box',  ''],
    ['family',  'Family',   'Ideal untuk keluarga modern',      50,  '350.000', 'box',  'popular'],
    ['premium', 'Premium',  'Untuk streaming & gaming',         100, '500.000', 'gem',  'premium'],
    ['business','Business', 'Solusi terbaik untuk bisnis Anda', 200, '750.000', 'tool', 'business'],
];
$fitur = [
    ['bolt','Kecepatan Tinggi','Streaming, gaming, dan bekerja lebih lancar tanpa hambatan.'],
    ['shield','Jaringan Stabil','Koneksi tetap stabil di segala aktivitas Anda.'],
    ['wifi','Fiber Optic','Teknologi modern untuk internet terbaik.'],
    ['tag','Harga Terjangkau','Paket lengkap dengan harga bersahabat.'],
    ['headset','Layanan Pelanggan','Siap membantu kapan saja saat Anda membutuhkan.'],
];
$alur = [
    ['Cek Coverage', 'coverage.php', 'pin', ['Masukkan alamat','Cek ketersediaan','Tersedia / tidak tersedia','Pilih paket / form request','Detail paket','Register customer','Verifikasi data','Order berlangganan','Pembayaran','Berhasil / gagal','Order diproses / bayar lagi','Survey / validasi','Jadwal instalasi','Teknisi datang','Instalasi selesai','Aktivasi customer','Customer aktif','Dashboard']],
    ['Lihat Paket', 'paket.php', 'box', ['Pilih paket','Pembayaran']],
    ['Login Customer', $loginUrl, 'user', ['Login','Dashboard']],
];
function ic($n){ return '<svg class="ic"><use href="#i-'.$n.'"/></svg>'; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="YesNet - Internet cepat, stabil dan terjangkau untuk rumah, keluarga dan bisnis.">
<title>YesNet - Internet Cepat & Stabil</title>
<link rel="stylesheet" href="landing.css?v=<?= time() ?>">
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
<g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 8.8a15 15 0 0 1 20 0M5.5 12.3a10 10 0 0 1 13 0M9 15.7a5 5 0 0 1 6 0"/><circle cx="12" cy="19" r="1" fill="currentColor"/></symbol>
<symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1"/></symbol>
<symbol id="i-headset" viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1"/><rect x="17" y="14" width="4" height="6" rx="1"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 22s7-6.5 7-12a7 7 0 0 0-14 0c0 5.500 7 12 7 12z"/><circle cx="12" cy="10" r="2.500"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5zM3 13l9 5 9-5"/></symbol>
<symbol id="i-gem" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 12L2 9zM2 9h20"/></symbol>
<symbol id="i-tool" viewBox="0 0 24 24"><path d="M14.500 6.500a4 4 0 0 0 5 5L21 13l-8 8-4-4 8-8zM3 21l5-5"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-5-5"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
</g></svg>

<header class="navbar">
  <a href="#home" class="brand"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>
  <nav class="nav-center">
    <a href="#home" class="active">Home</a>
    <a href="#keunggulan">Keunggulan</a>
    <a href="#paket">Paket</a>
    <a href="#alur">Alur</a>
    <a href="#kontak">Kontak</a>
  </nav>
  <a href="<?= $loginUrl ?>" class="nav-login"><?= ic('user') ?>Login Customer</a>
</header>

<section id="home" class="hero">
  <div class="hero-content">
    <div class="hero-text">
      <div class="eyebrow">Internet untuk Hidup yang Lebih Baik</div>
      <h1>YesNet - Internet <span>Cepat &amp; Stabil</span></h1>
      <p class="hero-description">Nikmati koneksi internet fiber optic dengan kecepatan tinggi, stabil, dan harga terjangkau. Untuk rumah, keluarga, dan bisnis Anda.</p>
      <div class="hero-actions">
        <a href="coverage.php" class="btn btn-primary"><?= ic('pin') ?>Cek Coverage</a>
        <a href="paket.php" class="btn btn-outline"><?= ic('box') ?>Lihat Paket</a>
        <a href="<?= $loginUrl ?>" class="btn btn-outline"><?= ic('user') ?>Login Customer</a>
      </div>
      <div class="hero-stats">
        <div class="hero-stat"><?= ic('wifi') ?><div><strong>99,9%</strong><small>Uptime Jaringan</small></div></div>
        <div class="hero-stat"><?= ic('bolt') ?><div><strong>Layanan</strong><small>24/7</small></div></div>
        <div class="hero-stat"><?= ic('shield') ?><div><strong>Tim Teknisi</strong><small>Profesional</small></div></div>
      </div>
    </div>

    <div class="hero-visual">
      <svg viewBox="0 0 560 420" class="scene" role="img" aria-label="Rumah dengan sinyal WiFi">
        <defs>
          <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8fb4f5"/><stop offset=".7" stop-color="#e3d3ee"/><stop offset="1" stop-color="#ffd9b8"/></linearGradient>
          <linearGradient id="glow" x1="0" x2="1"><stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#a855f7"/></linearGradient>
        </defs>
        <rect width="560" height="420" fill="url(#sky)"/>
        <g fill="#6b7fa8" opacity=".55"><rect x="250" y="190" width="26" height="120"/><rect x="280" y="215" width="22" height="95"/><rect x="215" y="235" width="30" height="75"/><rect x="305" y="205" width="28" height="105"/></g>
        <path d="M0 300 Q140 270 280 305 T560 290V420H0z" fill="#2c4a3e"/>
        <g class="house">
          <polygon points="150,170 280,120 430,160 430,185 150,195" fill="#2a3556"/>
          <rect x="160" y="190" width="260" height="120" fill="#f1ece4"/>
          <rect x="185" y="215" width="70" height="75" fill="#ffd27a"/><rect x="285" y="215" width="95" height="75" fill="#ffc861"/>
          <rect x="262" y="215" width="14" height="95" fill="#3a4466"/>
          <rect x="150" y="305" width="290" height="10" fill="#3a4466"/>
        </g>
        <path class="trail" d="M10 372 C140 335 300 395 550 330" fill="none" stroke="url(#glow)" stroke-width="5" stroke-linecap="round"/>
        <g class="signal" fill="none" stroke="#22d3ee" stroke-width="6" stroke-linecap="round">
          <path d="M215 85a90 90 0 0 1 130 0"/><path d="M237 108a58 58 0 0 1 86 0"/><path d="M258 130a28 28 0 0 1 44 0"/>
        </g>
        <circle cx="280" cy="140" r="6" fill="#22d3ee" class="pulse"/>
        <g fill="#1f3d2c"><ellipse cx="500" cy="230" rx="38" ry="90"/><ellipse cx="70" cy="290" rx="34" ry="46"/></g>
      </svg>
      <div class="hero-note">Koneksi Stabil<br>untuk Masa Depan<br>Lebih Baik</div>
    </div>
  </div>
</section>

<section id="keunggulan" class="advantages">
  <div class="advantage-grid">
    <?php foreach ($fitur as $f): ?>
    <div class="advantage-card reveal">
      <div class="advantage-icon"><?= ic($f[0]) ?></div>
      <h3><?= $f[1] ?></h3><p><?= $f[2] ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="coverage-section">
  <div class="coverage-box reveal">
    <div class="coverage-content">
      <div class="coverage-heading">
        <div class="coverage-icon"><?= ic('pin') ?></div>
        <div><h2>Cek Coverage</h2><p>Pastikan alamat Anda sudah terjangkau jaringan YesNet.</p></div>
      </div>
      <form action="coverage.php" method="get" class="coverage-form">
        <label class="coverage-input"><?= ic('search') ?>
          <input type="text" name="alamat" placeholder="Masukkan alamat lengkap Anda..." required>
        </label>
        <button type="submit" class="btn btn-primary"><?= ic('search') ?>Cek Sekarang</button>
      </form>
      <div class="coverage-steps">
        <div class="coverage-step"><b>1</b><div><strong>Masukkan Alamat</strong><small>Ketik alamat lengkap rumah Anda</small></div></div>
        <div class="coverage-step"><b>2</b><div><strong>Cek Ketersediaan</strong><small>Kami akan mengecek jaringan di lokasi Anda</small></div></div>
        <div class="coverage-step"><b>3</b><div><strong>Pilih Paket</strong><small>Dapatkan rekomendasi paket terbaik</small></div></div>
      </div>
    </div>
    <div class="coverage-map" aria-hidden="true">
      <div class="map-ring"></div><div class="map-ring r2"></div>
      <div class="map-pin"><?= ic('pin') ?></div>
      <div class="coverage-status"><i><?= ic('check') ?></i><div><strong>Jaringan YesNet</strong><small>Tersedia di Lokasi Anda</small></div></div>
    </div>
  </div>
</section>

<section id="paket" class="packages-section">
  <div class="section-heading">
    <span class="section-label">Paket Internet</span>
    <h2>Pilih Paket yang Sesuai dengan Kebutuhan Anda</h2>
    <p>Berbagai pilihan paket dengan kecepatan terbaik untuk rumah, keluarga, dan bisnis.</p>
  </div>
  <div class="packages-grid">
    <?php foreach ($paket as $p): ?>
    <div class="package-card reveal <?= $p[6] ?>">
      <?php if ($p[6]==='popular'): ?><div class="popular-label">Paling Populer</div><?php endif; ?>
      <div class="package-top"><div class="package-icon"><?= ic($p[5]) ?></div>
        <div><strong><?= $p[1] ?></strong><small><?= $p[2] ?></small></div></div>
      <div class="package-speed"><?= ic('wifi') ?><?= $p[3] ?> Mbps</div>
      <div class="package-price">Rp <?= $p[4] ?> <small>/bulan</small></div>
      <ul>
        <li><?= ic('check') ?>Internet Unlimited</li>
        <li><?= ic('check') ?>WiFi Router</li>
        <li><?= ic('check') ?>Instalasi Gratis</li>
        <?php if ($p[6]==='business'): ?><li><?= ic('check') ?>IP Publik (Opsional)</li><?php endif; ?>
      </ul>
      <a href="pembayaran.php?paket=<?= $p[0] ?>" class="package-button">Pilih Paket</a>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section id="alur" class="flow-section">
  <div class="section-heading">
    <span class="section-label">Alur Layanan</span>
    <h2>Tiga Jalur, Satu Tujuan: Dashboard</h2>
    <p>Pilih jalur dari halaman ini sesuai kebutuhan Anda.</p>
  </div>
  <div class="flow-lanes">
    <?php foreach ($alur as $a): ?>
    <div class="flow-lane reveal">
      <a href="<?= $a[1] ?>" class="flow-start"><?= ic($a[2]) ?><?= $a[0] ?><?= ic('arrow') ?></a>
      <ol class="flow-track">
        <?php foreach ($a[3] as $s): ?><li><?= $s ?></li><?php endforeach; ?>
      </ol>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<footer id="kontak">
  <div class="footer-inner">
    <div class="footer-brand">
      <a href="#home" class="brand footer-logo"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>
      <p>Internet Cepat &amp; Stabil</p>
    </div>
    <div class="footer-column"><h4>Tautan Cepat</h4>
      <a href="#home">Home</a><a href="#keunggulan">Keunggulan</a><a href="#paket">Paket</a><a href="#kontak">Kontak</a></div>
    <div class="footer-column"><h4>Hubungi Kami</h4>
      <a href="tel:081234567890">0812 3456 7890</a><a href="mailto:cs@yesnet.my.id">cs@yesnet.my.id</a><a href="#">Jl. Merdeka No. 123, Kota Anda</a></div>
    <div class="footer-column"><h4>Ikuti Kami</h4>
      <div class="social-links"><a href="#" aria-label="Facebook">f</a><a href="#" aria-label="Instagram">ig</a><a href="#" aria-label="YouTube">yt</a><a href="#" aria-label="WhatsApp">wa</a></div></div>
  </div>
  <div class="footer-bottom"><span>© <?= date('Y') ?> YesNet. All rights reserved.</span><span>Internet Cepat &amp; Stabil</span></div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var nav = document.querySelector('.navbar');
  var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 30); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  var els = document.querySelectorAll('.reveal');
  var links = document.querySelectorAll('.nav-center a');
  var secs = document.querySelectorAll('section[id]');

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-visible');
        if (e.target.classList.contains('flow-lane')) runTrack(e.target.querySelector('.flow-track'));
        io.unobserve(e.target);
      });
    }, { threshold: .15 });
    els.forEach(function (el, i) { el.style.transitionDelay = (i % 5) * 70 + 'ms'; io.observe(el); });

    var so = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('href') === '#' + e.target.id); });
      });
    }, { rootMargin: '-30% 0px -60% 0px' });
    secs.forEach(function (s) { so.observe(s); });
  } else {
    els.forEach(function (el) { el.classList.add('is-visible'); });
  }

  // animasi alur: langkah menyala berurutan, lalu mengulang
  function runTrack(track) {
    var steps = track.children, i = 0;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
      [].forEach.call(steps, function (s) { s.classList.add('on'); }); return;
    }
    setInterval(function () {
      if (i >= steps.length + 2) { [].forEach.call(steps, function (s) { s.classList.remove('on'); }); i = 0; }
      if (steps[i]) steps[i].classList.add('on');
      i++;
    }, 650);
  }
});
</script>
</body>
</html>