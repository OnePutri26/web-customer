<?php
/*
 * biodata.php - Langkah 2 & 3 alur pendaftaran YesNet
 *   Landing -> [Biodata -> Data tersimpan (status: lead)] -> Cek Coverage -> Hasil -> Order
 *
 * Opsional dari landing: biodata.php?paket=ID&wilayah=jakarta|surabaya
 * Data disimpan ke tabel `calon_pelanggan` (lihat calon_pelanggan.sql). ID lead disimpan
 * di $_SESSION['bd_lead_id'] supaya coverage.php bisa memperbarui statusnya.
 */
$cfgFile = __DIR__ . '/config/app.php';
if (is_file($cfgFile)) {
    require_once $cfgFile;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('ic')) { function ic($n, $c = '') { return '<svg class="ic' . ($c ? ' ' . $c : '') . '" aria-hidden="true"><use href="#i-' . $n . '"/></svg>'; } }

/* ---------- Helper ---------- */
function bdDdl(): string
{
    return "CREATE TABLE IF NOT EXISTS `calon_pelanggan` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `nama` VARCHAR(100) NOT NULL,
      `whatsapp` VARCHAR(20) NOT NULL,
      `email` VARCHAR(150) DEFAULT NULL,
      `provinsi` VARCHAR(100) NOT NULL,
      `kota` VARCHAR(100) NOT NULL,
      `kecamatan` VARCHAR(100) NOT NULL,
      `kelurahan` VARCHAR(100) NOT NULL,
      `alamat_lengkap` TEXT NOT NULL,
      `latitude` DECIMAL(10,7) DEFAULT NULL,
      `longitude` DECIMAL(10,7) DEFAULT NULL,
      `paket_id` INT DEFAULT NULL,
      `wilayah` VARCHAR(30) DEFAULT NULL,
      `status` ENUM('lead','coverage_tersedia','coverage_tidak_tersedia','prospek','order','batal') NOT NULL DEFAULT 'lead',
      `odp_terdekat` VARCHAR(100) DEFAULT NULL,
      `jarak_odp_m` INT UNSIGNED DEFAULT NULL,
      `port_tersedia` SMALLINT UNSIGNED DEFAULT NULL,
      `coverage_checked_at` DATETIME DEFAULT NULL,
      `user_id` INT DEFAULT NULL,
      `persetujuan` TINYINT(1) NOT NULL DEFAULT 0,
      `persetujuan_at` DATETIME DEFAULT NULL,
      `sumber` VARCHAR(30) NOT NULL DEFAULT 'landing',
      `ip_address` VARCHAR(45) DEFAULT NULL,
      `user_agent` VARCHAR(255) DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_whatsapp` (`whatsapp`),
      KEY `idx_status` (`status`),
      KEY `idx_wilayah` (`wilayah`),
      KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function bdEnsureTable(mysqli $conn): void
{
    $r = $conn->query("SHOW TABLES LIKE 'calon_pelanggan'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    $conn->query(bdDdl());
}

/* 0812... / 812... / +62812... -> 62812... */
function bdNormalizePhone(string $v): string
{
    $d = preg_replace('/\D+/', '', $v);
    if ($d === '' || $d === null) return '';
    if (strpos($d, '62') === 0) return $d;
    if ($d[0] === '0') return '62' . substr($d, 1);
    if ($d[0] === '8') return '62' . $d;
    return $d;
}

function bdPhoneDisplay(string $p): string
{
    return strpos($p, '62') === 0 ? '0' . substr($p, 2) : $p;
}

function bdDetectRegion(string $kota, string $prov): string
{
    $h = strtolower($kota . ' ' . $prov);
    if (strpos($h, 'jakarta') !== false) return 'jakarta';
    foreach (['surabaya', 'sidoarjo', 'jawa timur'] as $w) {
        if (strpos($h, $w) !== false) return 'surabaya';
    }
    return '';
}

function bdPaket(?mysqli $conn, int $id): ?array
{
    if (!$conn || $id <= 0) return null;
    try {
        $r = $conn->query('SELECT * FROM paket_wifi WHERE id = ' . (int)$id . ' LIMIT 1');
        $row = $r ? $r->fetch_assoc() : null;
        if ($row) {
            return ['id' => (int)$row['id'], 'nama' => (string)($row['nama_paket'] ?? 'Paket'), 'mbps' => (int)($row['speed_mbps'] ?? 0)];
        }
    } catch (Throwable $e) {
        error_log('Biodata paket: ' . $e->getMessage());
    }
    return null;
}

/* ---------- Tautan & konfigurasi ---------- */
$loggedIn   = !empty($_SESSION['user_id']);
$loginUrl   = appUrl($loggedIn ? 'redirect.php' : 'login.php');
$loginLabel = $loggedIn ? 'Dashboard Saya' : 'Login Customer';
$homeUrl    = appUrl('index.php');
$coverageUrl = appUrl('coverage.php');
$cssVer = @filemtime(__DIR__ . '/css/biodata.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/js/biodata.js') ?: 1;
$dbConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
$selfUrl = strtok((string)($_SERVER['REQUEST_URI'] ?? 'biodata.php'), '?');

if (empty($_SESSION['bd_csrf'])) {
    $_SESSION['bd_csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Paket pilihan (opsional) ---------- */
$paketId = (int)($_GET['paket'] ?? $_POST['paket'] ?? 0);
$wilayahReq = strtolower(trim((string)($_GET['wilayah'] ?? $_POST['wilayah'] ?? '')));
if (!in_array($wilayahReq, ['jakarta', 'surabaya'], true)) $wilayahReq = '';
$paketInfo = bdPaket($dbConn, $paketId);
if (!$paketInfo) $paketId = 0;

/* ---------- Proses form ---------- */
$old = ['nama' => '', 'whatsapp' => '', 'email' => '', 'provinsi' => '', 'kota' => '', 'kecamatan' => '',
        'kelurahan' => '', 'alamat' => '', 'koordinat' => '', 'setuju' => ''];
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $v = (string)($_POST[$k] ?? '');
        $v = preg_replace('/\s+/u', ' ', $v);
        $old[$k] = mb_substr(trim((string)$v), 0, $k === 'alamat' ? 500 : 150);
    }

    $csrfOk = hash_equals($_SESSION['bd_csrf'], (string)($_POST['csrf'] ?? ''));
    $isBot  = trim((string)($_POST['website'] ?? '')) !== '';
    $tooFast = isset($_SESSION['bd_last']) && (time() - (int)$_SESSION['bd_last']) < 3;

    if (!$csrfOk || $isBot) {
        $formError = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($tooFast) {
        $formError = 'Mohon tunggu beberapa detik sebelum mengirim ulang.';
    } else {
        if ($old['nama'] === '') $errors['nama'] = 'Nama lengkap wajib diisi.';
        elseif (!preg_match('/^[\p{L}\p{M}\s.\'’-]{3,100}$/u', $old['nama'])) $errors['nama'] = 'Nama hanya boleh berisi huruf (minimal 3 karakter).';

        $wa = bdNormalizePhone($old['whatsapp']);
        if ($old['whatsapp'] === '') $errors['whatsapp'] = 'Nomor WhatsApp wajib diisi.';
        elseif (!preg_match('/^628\d{7,12}$/', $wa)) $errors['whatsapp'] = 'Gunakan nomor WhatsApp yang valid, contoh 081234567890.';

        if ($old['email'] !== '' && (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150)) {
            $errors['email'] = 'Format email tidak valid.';
        }

        foreach (['provinsi' => 'provinsi', 'kota' => 'kota/kabupaten', 'kecamatan' => 'kecamatan', 'kelurahan' => 'kelurahan'] as $k => $lbl) {
            if ($old[$k] === '') $errors[$k] = 'Pilih ' . $lbl . '.';
            elseif (mb_strlen($old[$k]) > 100) $errors[$k] = 'Isian ' . $lbl . ' terlalu panjang.';
        }

        if ($old['alamat'] === '') $errors['alamat'] = 'Alamat lengkap wajib diisi.';
        elseif (mb_strlen($old['alamat']) < 10) $errors['alamat'] = 'Tulis alamat lebih lengkap (jalan, nomor, RT/RW).';

        $lat = null; $lng = null;
        if ($old['koordinat'] !== '') {
            if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)$/', $old['koordinat'], $m)) {
                $lat = (float)$m[1]; $lng = (float)$m[2];
                if ($lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) {
                    $errors['koordinat'] = 'Koordinat berada di luar wilayah Indonesia.';
                    $lat = $lng = null;
                }
            } else {
                $errors['koordinat'] = 'Format koordinat: latitude, longitude. Contoh: -7.257472, 112.752088';
            }
        }

        if ($old['setuju'] === '') $errors['setuju'] = 'Anda perlu menyetujui kebijakan privasi untuk melanjutkan.';

        if (!$errors) {
            if (!$dbConn) {
                $formError = 'Layanan penyimpanan sedang tidak tersedia. Silakan coba lagi nanti atau hubungi kami lewat WhatsApp.';
            } else {
                try {
                    bdEnsureTable($dbConn);

                    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
                    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                    $emailDb  = $old['email'] !== '' ? $old['email'] : null;
                    $paketDb  = $paketId > 0 ? $paketId : null;
                    $detected = bdDetectRegion($old['kota'], $old['provinsi']);
                    $wilDb    = $detected !== '' ? $detected : ($wilayahReq !== '' ? $wilayahReq : null);
                    $nama = $old['nama']; $prov = $old['provinsi']; $kota = $old['kota'];
                    $kec = $old['kecamatan']; $kel = $old['kelurahan']; $alamat = $old['alamat'];

                    /* Nomor yang sama dengan lead berstatus "lead" dalam 24 jam terakhir diperbarui, bukan digandakan */
                    $existingId = 0;
                    $st = $dbConn->prepare("SELECT id FROM calon_pelanggan WHERE whatsapp = ? AND status = 'lead' AND created_at > (NOW() - INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1");
                    $st->bind_param('s', $wa);
                    $st->execute();
                    $st->bind_result($existingId);
                    if (!$st->fetch()) $existingId = 0;
                    $st->close();

                    if ($existingId > 0) {
                        $st = $dbConn->prepare('UPDATE calon_pelanggan SET nama=?, email=?, provinsi=?, kota=?, kecamatan=?, kelurahan=?, alamat_lengkap=?, latitude=?, longitude=?, paket_id=COALESCE(?, paket_id), wilayah=COALESCE(?, wilayah), ip_address=?, user_agent=?, persetujuan=1, persetujuan_at=NOW() WHERE id=?');
                        $st->bind_param('sssssssddisssi', $nama, $emailDb, $prov, $kota, $kec, $kel, $alamat, $lat, $lng, $paketDb, $wilDb, $ip, $ua, $existingId);
                        if (!$st->execute()) throw new RuntimeException($st->error);
                        $st->close();
                        $leadId = $existingId;
                    } else {
                        $st = $dbConn->prepare("INSERT INTO calon_pelanggan (nama, whatsapp, email, provinsi, kota, kecamatan, kelurahan, alamat_lengkap, latitude, longitude, paket_id, wilayah, ip_address, user_agent, status, persetujuan, persetujuan_at, sumber) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'lead', 1, NOW(), 'landing')");
                        $st->bind_param('ssssssssddisss', $nama, $wa, $emailDb, $prov, $kota, $kec, $kel, $alamat, $lat, $lng, $paketDb, $wilDb, $ip, $ua);
                        if (!$st->execute()) throw new RuntimeException($st->error);
                        $leadId = (int)$dbConn->insert_id;
                        $st->close();
                    }

                    session_regenerate_id(true);
                    $_SESSION['bd_lead_id'] = (int)$leadId;
                    $_SESSION['bd_last'] = time();
                    $_SESSION['bd_csrf'] = bin2hex(random_bytes(32));
                    header('Location: ' . $selfUrl . '?sukses=1');
                    exit;
                } catch (Throwable $ex) {
                    error_log('Biodata simpan: ' . $ex->getMessage());
                    $formError = 'Data belum berhasil disimpan. Silakan coba lagi beberapa saat lagi.';
                }
            }
        }
    }
}

/* ---------- Halaman sukses (setelah redirect) ---------- */
$lead = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['sukses']) && !empty($_SESSION['bd_lead_id']) && $dbConn) {
    try {
        $r = $dbConn->query('SELECT * FROM calon_pelanggan WHERE id = ' . (int)$_SESSION['bd_lead_id'] . ' LIMIT 1');
        $lead = $r ? $r->fetch_assoc() : null;
    } catch (Throwable $ex) {
        error_log('Biodata baca: ' . $ex->getMessage());
    }
}

$lanjutUrl = $coverageUrl;
$paketLead = null;
if ($lead) {
    $paketLead = bdPaket($dbConn, (int)($lead['paket_id'] ?? 0));
    $fullAddr = implode(', ', array_filter([$lead['alamat_lengkap'], $lead['kelurahan'], $lead['kecamatan'], $lead['kota'], $lead['provinsi']]));
    $q = ['auto' => 1, 'alamat' => $fullAddr];
    if ($lead['latitude'] !== null && $lead['longitude'] !== null) {
        $q['lat'] = $lead['latitude'];
        $q['lng'] = $lead['longitude'];
    }
    $lanjutUrl = $coverageUrl . (strpos($coverageUrl, '?') === false ? '?' : '&') . http_build_query($q);
}

$hasErr = $errors || $formError !== '';
$fe = static function (string $k) use ($errors): string {
    return '<small class="bd-err" id="err-' . e($k) . '" role="alert">' . e($errors[$k] ?? '') . '</small>';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lead ? 'Data Berhasil Disimpan' : 'Isi Biodata & Cek Coverage' ?> - YesNet</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0a1d4d">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="css/biodata.css?v=<?= $cssVer ?>">
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
<symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 8.8a15 15 0 0 1 20 0M5.5 12.3a10 10 0 0 1 13 0M9 15.7a5 5 0 0 1 6 0"/><circle cx="12" cy="19" r="1" fill="currentColor"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 22s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
<symbol id="i-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="8"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18v.5"/></symbol>
</svg>

<header class="bd-header">
  <div class="bd-header-in">
    <a href="<?= e($homeUrl) ?>" class="bd-brand" aria-label="YesNet beranda"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>
    <a href="<?= e($loginUrl) ?>" class="bd-login"><?= ic('user') ?><?= e($loginLabel) ?></a>
  </div>
</header>

<main class="bd-main">
<div class="bd-wrap">

<?php if ($lead): ?>
  <!-- ===== Data berhasil disimpan ===== -->
  <section class="bd-card bd-success" aria-labelledby="judul-sukses">
    <svg class="bd-illu" viewBox="0 0 200 160" aria-hidden="true">
      <circle cx="100" cy="80" r="70" fill="#e8f0ff"/>
      <rect x="55" y="38" width="90" height="72" rx="10" fill="#fff" stroke="#bcd0f7" stroke-width="2"/>
      <rect x="68" y="54" width="40" height="6" rx="3" fill="#cfe0ff"/>
      <rect x="68" y="68" width="64" height="6" rx="3" fill="#e1ebff"/>
      <rect x="68" y="82" width="50" height="6" rx="3" fill="#e1ebff"/>
      <circle cx="135" cy="104" r="20" fill="#1f62ff"/>
      <path d="m126 104 7 7 12-14" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <h1 id="judul-sukses">Data Anda Berhasil Disimpan!</h1>
    <p>Terima kasih telah mengisi biodata kami. Kami akan segera melanjutkan pengecekan ketersediaan jaringan di lokasi Anda.</p>

    <div class="bd-summary">
      <h2>Ringkasan Data</h2>
      <dl>
        <dt>Nama</dt><dd><?= e($lead['nama']) ?></dd>
        <dt>No. WhatsApp</dt><dd><?= e(bdPhoneDisplay((string)$lead['whatsapp'])) ?></dd>
        <dt>Alamat</dt><dd><?= e(implode(', ', array_filter([$lead['alamat_lengkap'], $lead['kelurahan'], $lead['kecamatan'], $lead['kota']]))) ?></dd>
        <dt>Paket Pilihan</dt><dd><?= $paketLead ? e($paketLead['nama'] . ($paketLead['mbps'] ? ' - ' . $paketLead['mbps'] . ' Mbps' : '')) : 'Belum dipilih' ?></dd>
      </dl>
    </div>

    <div class="bd-actions">
      <a href="<?= e($lanjutUrl) ?>" class="bd-btn bd-btn-primary bd-btn-block">Lanjut ke Pengecekan Coverage <?= ic('arrow') ?></a>
      <a href="<?= e($homeUrl) ?>" class="bd-btn bd-btn-ghost">Kembali ke Beranda</a>
    </div>
  </section>

<?php else: ?>
  <!-- ===== Form biodata ===== -->
  <ol class="bd-stepper" aria-label="Tahapan pendaftaran">
    <li class="is-active" aria-current="step"><span class="bd-sn">1</span><span class="bd-sl">Biodata</span></li>
    <li><span class="bd-sn">2</span><span class="bd-sl">Coverage</span></li>
    <li><span class="bd-sn">3</span><span class="bd-sl">Hasil</span></li>
    <li><span class="bd-sn">4</span><span class="bd-sl">Order</span></li>
  </ol>

  <section class="bd-card">
    <noscript><div class="bd-noscript">Aktifkan JavaScript agar pilihan provinsi sampai kelurahan dapat dimuat.</div></noscript>

    <?php if ($hasErr): ?>
    <div class="bd-alert" role="alert"><?= ic('alert') ?><span><?= $formError !== '' ? e($formError) : 'Periksa kembali isian yang ditandai merah.' ?></span></div>
    <?php endif; ?>

    <?php if ($paketInfo): ?>
    <div class="bd-note">
      <span>Paket pilihan: <strong><?= e($paketInfo['nama']) ?><?= $paketInfo['mbps'] ? ' - ' . (int)$paketInfo['mbps'] . ' Mbps' : '' ?></strong></span>
      <a href="<?= e($homeUrl) ?>#paket">Ubah paket</a>
    </div>
    <?php endif; ?>

    <form id="bd-form" method="post" action="<?= e($selfUrl) ?>" novalidate autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['bd_csrf']) ?>">
      <input type="hidden" name="paket" value="<?= (int)$paketId ?>">
      <input type="hidden" name="wilayah" value="<?= e($wilayahReq) ?>">
      <div class="bd-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="bd-sec-head">
        <div class="bd-sec-ic"><?= ic('user') ?></div>
        <div><h2>Data Calon Pelanggan</h2><p>Lengkapi data diri Anda untuk melanjutkan proses pengecekan coverage.</p></div>
      </div>

      <div class="bd-grid">
        <div class="bd-field bd-full">
          <label for="nama">Nama Lengkap <span class="req">*</span></label>
          <input type="text" id="nama" name="nama" value="<?= e($old['nama']) ?>" placeholder="Masukkan nama lengkap" autocomplete="name" maxlength="100" required class="<?= isset($errors['nama']) ? 'is-invalid' : '' ?>">
          <?= $fe('nama') ?>
        </div>
        <div class="bd-field">
          <label for="whatsapp">Nomor WhatsApp <span class="req">*</span></label>
          <input type="tel" id="whatsapp" name="whatsapp" value="<?= e($old['whatsapp']) ?>" placeholder="08xxxxxxxxxx" inputmode="tel" autocomplete="tel" maxlength="20" required class="<?= isset($errors['whatsapp']) ? 'is-invalid' : '' ?>">
          <?= $fe('whatsapp') ?>
        </div>
        <div class="bd-field">
          <label for="email">Email <span class="opt">(opsional)</span></label>
          <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" placeholder="nama@email.com" autocomplete="email" maxlength="150" class="<?= isset($errors['email']) ? 'is-invalid' : '' ?>">
          <?= $fe('email') ?>
        </div>
      </div>

      <hr class="bd-divider">

      <div class="bd-sec-head">
        <div class="bd-sec-ic"><?= ic('pin') ?></div>
        <div><h2>Alamat Pemasangan</h2><p>Pastikan alamat sesuai dengan lokasi pemasangan internet.</p></div>
      </div>

      <div class="bd-grid">
        <div class="bd-field">
          <label for="provinsi">Provinsi <span class="req">*</span></label>
          <select id="provinsi" name="provinsi" data-old="<?= e($old['provinsi']) ?>" required class="<?= isset($errors['provinsi']) ? 'is-invalid' : '' ?>"><option value="">Pilih provinsi</option></select>
          <?= $fe('provinsi') ?>
        </div>
        <div class="bd-field">
          <label for="kota">Kota/Kabupaten <span class="req">*</span></label>
          <select id="kota" name="kota" data-old="<?= e($old['kota']) ?>" required disabled class="<?= isset($errors['kota']) ? 'is-invalid' : '' ?>"><option value="">Pilih kota/kabupaten</option></select>
          <?= $fe('kota') ?>
        </div>
        <div class="bd-field">
          <label for="kecamatan">Kecamatan <span class="req">*</span></label>
          <select id="kecamatan" name="kecamatan" data-old="<?= e($old['kecamatan']) ?>" required disabled class="<?= isset($errors['kecamatan']) ? 'is-invalid' : '' ?>"><option value="">Pilih kecamatan</option></select>
          <?= $fe('kecamatan') ?>
        </div>
        <div class="bd-field">
          <label for="kelurahan">Kelurahan <span class="req">*</span></label>
          <select id="kelurahan" name="kelurahan" data-old="<?= e($old['kelurahan']) ?>" required disabled class="<?= isset($errors['kelurahan']) ? 'is-invalid' : '' ?>"><option value="">Pilih kelurahan</option></select>
          <?= $fe('kelurahan') ?>
        </div>

        <div class="bd-field bd-full">
          <label for="alamat">Alamat Lengkap <span class="req">*</span></label>
          <textarea id="alamat" name="alamat" rows="3" maxlength="500" placeholder="Jalan, nomor rumah, RT/RW, patokan lokasi..." autocomplete="street-address" required class="<?= isset($errors['alamat']) ? 'is-invalid' : '' ?>"><?= e($old['alamat']) ?></textarea>
          <?= $fe('alamat') ?>
        </div>

        <div class="bd-field bd-full">
          <label for="koordinat">Koordinat <span class="opt">(opsional)</span></label>
          <div class="bd-coord">
            <input type="text" id="koordinat" name="koordinat" value="<?= e($old['koordinat']) ?>" placeholder="Latitude, Longitude" inputmode="decimal" autocomplete="off" class="<?= isset($errors['koordinat']) ? 'is-invalid' : '' ?>">
            <button type="button" class="bd-btn bd-btn-outline bd-btn-sm" id="bd-geo"><?= ic('target') ?>Gunakan Lokasi Saya</button>
          </div>
          <span class="bd-geo-msg" id="bd-geo-msg" aria-live="polite"></span>
          <?= $fe('koordinat') ?>
        </div>

        <div class="bd-field bd-full">
          <label class="bd-consent" for="setuju">
            <input type="checkbox" id="setuju" name="setuju" value="1"<?= $old['setuju'] !== '' ? ' checked' : '' ?>>
            <span>Saya menyetujui pengolahan data pribadi sesuai dengan <a href="#kebijakan">Kebijakan Privasi</a>.</span>
          </label>
          <?= $fe('setuju') ?>
        </div>
      </div>

      <div class="bd-actions">
        <button type="submit" class="bd-btn bd-btn-primary bd-btn-block" id="bd-submit">Simpan Biodata &amp; Lanjut Cek Coverage <?= ic('arrow') ?></button>
        <a href="<?= e($homeUrl) ?>" class="bd-btn bd-btn-ghost">Kembali ke Beranda</a>
      </div>
    </form>
  </section>

  <aside class="bd-privacy" id="kebijakan">
    <h3>Kebijakan Privasi</h3>
    <p>Data yang Anda isi dipakai untuk memeriksa ketersediaan jaringan, menjadwalkan survei, dan menghubungi Anda terkait layanan YesNet. Data tetap tersimpan sebagai prospek meskipun jaringan belum tersedia di lokasi Anda, agar kami dapat menghubungi Anda saat jaringan sudah menjangkau area tersebut.</p>
  </aside>
<?php endif; ?>

</div>
</main>

<footer class="bd-footer">
  <div class="bd-footer-in">
    <div>
      <a href="<?= e($homeUrl) ?>" class="bd-brand"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>
      <small>Internet Cepat &amp; Stabil</small>
    </div>
    <div class="bd-social" aria-label="Media sosial">
      <a href="#" aria-label="Facebook">f</a>
      <a href="https://www.instagram.com/yesnet.id/" target="_blank" rel="noopener noreferrer" aria-label="Instagram YesNet">ig</a>
      <a href="#" aria-label="YouTube">yt</a>
      <a href="https://wa.me/6285604145030" target="_blank" rel="noopener" aria-label="WhatsApp">wa</a>
    </div>
    <span>© <?= date('Y') ?> YesNet. All rights reserved.</span>
  </div>
</footer>

<?php if (!$lead): ?>
<script src="js/biodata.js?v=<?= $jsVer ?>" defer></script>
<?php endif; ?>
</body>
</html>