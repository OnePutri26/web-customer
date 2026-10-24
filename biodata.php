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
<style>
/* ===== YesNet - Halaman Biodata ===== */
:root {
  --navy: #0a1d4d;
  --blue: #1f62ff;
  --blue-d: #154fd6;
  --blue-soft: #e8f0ff;
  --ink: #0f1f3d;
  --muted: #5b6b8c;
  --line: #d9e3f4;
  --bg: #f2f6fd;
  --ok: #12a150;
  --err: #d92d3a;
  --err-soft: #fdecee;
}

* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  color: var(--ink);
  background: var(--bg);
  line-height: 1.5;
}
a { color: var(--blue); }
.ic { width: 1em; height: 1em; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
:focus-visible { outline: 3px solid rgba(31, 98, 255, .45); outline-offset: 2px; }

/* ----- Header & footer ----- */
.bd-header { background: var(--navy); color: #fff; }
.bd-header-in, .bd-footer-in { max-width: 1100px; margin: 0 auto; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.bd-brand { display: inline-flex; align-items: center; gap: 8px; color: #fff; text-decoration: none; font-size: 22px; }
.bd-brand strong { font-size: 18px; letter-spacing: .02em; font-weight: 800; }
.bd-brand strong span { color: #6aa8ff; }
.bd-login { display: inline-flex; align-items: center; gap: 6px; background: var(--blue); color: #fff; text-decoration: none; font-size: 13px; font-weight: 700; padding: 8px 14px; border-radius: 999px; }
.bd-login:hover { background: var(--blue-d); }

.bd-footer { background: var(--navy); color: #cdd8f2; margin-top: auto; }
.bd-footer-in { padding: 22px 20px; flex-wrap: wrap; font-size: 13px; }
.bd-footer .bd-brand { font-size: 20px; }
.bd-footer small { display: block; color: #9fb1d9; }
.bd-social { display: flex; gap: 8px; }
.bd-social a { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 8px; background: rgba(255, 255, 255, .1); color: #fff; text-decoration: none; font-size: 12px; font-weight: 700; }
.bd-social a:hover { background: var(--blue); }

/* ----- Layout ----- */
.bd-main { flex: 1; padding: 28px 16px 56px; }
.bd-wrap { max-width: 780px; margin: 0 auto; }
.bd-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 30px; box-shadow: 0 12px 32px rgba(15, 42, 107, .07); }

/* ----- Stepper ----- */
.bd-stepper { list-style: none; margin: 0 auto 24px; padding: 0; display: flex; align-items: center; max-width: 640px; }
.bd-stepper li { flex: 1; display: flex; align-items: center; gap: 8px; color: #8a98b6; font-size: 13px; font-weight: 600; }
.bd-stepper li:last-child { flex: 0 0 auto; }
.bd-stepper li::after { content: ""; flex: 1; height: 2px; margin: 0 10px; background: var(--line); }
.bd-stepper li:last-child::after { display: none; }
.bd-sn { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: #e3eaf7; color: #6b7a99; font-size: 13px; font-weight: 800; flex: none; }
.bd-stepper .is-active { color: var(--ink); }
.bd-stepper .is-active .bd-sn { background: var(--blue); color: #fff; box-shadow: 0 0 0 4px rgba(31, 98, 255, .15); }

/* ----- Section heading ----- */
.bd-sec-head { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
.bd-sec-head + .bd-grid { margin-top: 0; }
.bd-sec-ic { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; background: var(--blue-soft); color: var(--blue); font-size: 22px; flex: none; }
.bd-sec-head h2 { margin: 0; font-size: 17px; line-height: 1.3; }
.bd-sec-head p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
.bd-divider { height: 1px; background: var(--line); border: 0; margin: 26px 0; }

/* ----- Form ----- */
.bd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 18px; }
.bd-full { grid-column: 1 / -1; }
.bd-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.bd-field > label, .bd-label { font-size: 13px; font-weight: 700; }
.bd-field .req { color: var(--err); }
.bd-field .opt { font-weight: 500; color: var(--muted); }

.bd-field input[type="text"], .bd-field input[type="email"], .bd-field input[type="tel"], .bd-field select, .bd-field textarea {
  width: 100%; font: inherit; font-size: 14px; color: var(--ink); background: #fff;
  border: 1.5px solid var(--line); border-radius: 10px; padding: 0 14px; height: 46px;
  transition: border-color .15s, box-shadow .15s;
}
.bd-field textarea { height: auto; min-height: 96px; padding: 12px 14px; resize: vertical; }
.bd-field select {
  appearance: none; -webkit-appearance: none; padding-right: 40px; cursor: pointer;
  background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235b6b8c' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 14px center / 16px;
}
.bd-field select:disabled { background-color: #f4f7fc; color: #93a0bd; cursor: not-allowed; }
.bd-field input::placeholder, .bd-field textarea::placeholder { color: #9aa7c2; }
.bd-field input:focus, .bd-field select:focus, .bd-field textarea:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 4px rgba(31, 98, 255, .13); }
.bd-field .is-invalid { border-color: var(--err); }
.bd-field .is-invalid:focus { box-shadow: 0 0 0 4px rgba(217, 45, 58, .13); }
.bd-err { min-height: 0; font-size: 12px; font-weight: 600; color: var(--err); }
.bd-err:empty { display: none; }
.bd-hint { font-size: 12px; color: var(--muted); }

.bd-coord { display: flex; gap: 10px; }
.bd-coord input { flex: 1; min-width: 0; }

.bd-consent { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; color: var(--muted); cursor: pointer; }
.bd-consent input { width: 18px; height: 18px; margin: 2px 0 0; accent-color: var(--blue); flex: none; }

/* ----- Buttons ----- */
.bd-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  height: 50px; padding: 0 22px; border-radius: 12px; border: 1.5px solid transparent;
  font: inherit; font-size: 15px; font-weight: 700; text-decoration: none; cursor: pointer;
  transition: background .15s, border-color .15s, transform .1s;
}
.bd-btn:active { transform: translateY(1px); }
.bd-btn-block { width: 100%; }
.bd-btn-primary { background: var(--blue); color: #fff; }
.bd-btn-primary:hover { background: var(--blue-d); }
.bd-btn-outline { background: #fff; color: var(--blue); border-color: var(--blue); }
.bd-btn-outline:hover { background: var(--blue-soft); }
.bd-btn-ghost { background: transparent; color: var(--blue); height: 44px; }
.bd-btn-ghost:hover { text-decoration: underline; }
.bd-btn-sm { height: 46px; padding: 0 16px; font-size: 13px; white-space: nowrap; }
.bd-btn:disabled { opacity: .7; cursor: not-allowed; }
.bd-btn.is-loading { position: relative; color: transparent; pointer-events: none; }
.bd-btn.is-loading::after { content: ""; position: absolute; width: 20px; height: 20px; border-radius: 50%; border: 3px solid rgba(255, 255, 255, .45); border-top-color: #fff; animation: bd-spin .7s linear infinite; }
@keyframes bd-spin { to { transform: rotate(360deg); } }
.bd-actions { margin-top: 24px; display: grid; gap: 8px; }

/* ----- Alerts & notes ----- */
.bd-alert { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 600; background: var(--err-soft); color: #a3202b; border: 1px solid #f6c3c8; }
.bd-alert .ic { font-size: 18px; margin-top: 1px; }
.bd-note { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 12px 14px; border-radius: 12px; margin-bottom: 20px; background: var(--blue-soft); font-size: 13px; }
.bd-note strong { font-weight: 800; }
.bd-geo-msg { font-size: 12px; color: var(--muted); }
.bd-geo-msg.is-ok { color: var(--ok); }
.bd-geo-msg.is-bad { color: var(--err); }
.bd-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.bd-privacy { margin-top: 18px; padding: 16px 18px; font-size: 12.5px; color: var(--muted); background: #fff; border: 1px dashed var(--line); border-radius: 12px; }
.bd-privacy h3 { margin: 0 0 4px; font-size: 13px; color: var(--ink); }
.bd-privacy p { margin: 0; }
.bd-noscript { margin-bottom: 16px; padding: 10px 12px; border-radius: 10px; background: #fff7e0; color: #7a5a00; font-size: 13px; }

/* ----- Success ----- */
.bd-success { text-align: center; padding: 34px 30px 28px; }
.bd-illu { width: 190px; height: auto; margin: 0 auto 4px; display: block; }
.bd-success h1 { margin: 6px 0 8px; font-size: 24px; line-height: 1.25; }
.bd-success > p { margin: 0 auto 24px; max-width: 460px; color: var(--muted); font-size: 14px; }
.bd-summary { text-align: left; border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; margin-bottom: 22px; }
.bd-summary h2 { margin: 0 0 10px; font-size: 14px; }
.bd-summary dl { margin: 0; display: grid; grid-template-columns: 130px 1fr; gap: 8px 0; font-size: 13px; }
.bd-summary dt { color: var(--muted); font-weight: 600; }
.bd-summary dd { margin: 0; font-weight: 700; display: flex; gap: 10px; word-break: break-word; }
.bd-summary dd::before { content: ":"; color: var(--muted); font-weight: 600; }

/* ----- Responsive ----- */
@media (max-width: 640px) {
  .bd-card { padding: 22px 18px; }
  .bd-grid { grid-template-columns: 1fr; }
  .bd-coord { flex-direction: column; }
  .bd-btn-sm { width: 100%; }
  .bd-stepper li:not(.is-active) .bd-sl { display: none; }
  .bd-stepper li::after { margin: 0 6px; }
  .bd-summary dl { grid-template-columns: 105px 1fr; }
  .bd-footer-in { justify-content: center; text-align: center; }
}
@media (prefers-reduced-motion: reduce) {
  * { transition: none !important; animation-duration: .01ms !important; }
}

</style>
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
<script>
/* YesNet - Halaman Biodata: dropdown wilayah, lokasi saya, validasi form */
(function () {
  'use strict';

  var form = document.getElementById('bd-form');
  if (!form) return;

  var $ = function (id) { return document.getElementById(id); };

  /* ---------- Util ---------- */
  function normalizePhone(v) {
    var d = String(v || '').replace(/\D+/g, '');
    if (!d) return '';
    if (d.indexOf('62') === 0) return d;
    if (d.charAt(0) === '0') return '62' + d.slice(1);
    if (d.charAt(0) === '8') return '62' + d;
    return d;
  }

  function showErr(id, msg) {
    var span = $('err-' + id);
    var field = $(id);
    if (span) span.textContent = msg || '';
    if (field) {
      field.classList.toggle('is-invalid', !!msg);
      if (msg) field.setAttribute('aria-invalid', 'true'); else field.removeAttribute('aria-invalid');
    }
  }

  /* ---------- Dropdown wilayah (provinsi > kota > kecamatan > kelurahan) ---------- */
  var API = 'https://www.emsifa.com/api-wilayah-indonesia/api/';
  var cache = {};
  var order = ['provinsi', 'kota', 'kecamatan', 'kelurahan'];
  var paths = [
    function () { return 'provinces.json'; },
    function (c) { return 'regencies/' + c + '.json'; },
    function (c) { return 'districts/' + c + '.json'; },
    function (c) { return 'villages/' + c + '.json'; }
  ];
  var labels = {
    provinsi: 'Pilih provinsi',
    kota: 'Pilih kota/kabupaten',
    kecamatan: 'Pilih kecamatan',
    kelurahan: 'Pilih kelurahan'
  };

  function title(s) {
    return String(s).toLowerCase()
      .replace(/(^|\s)(\S)/g, function (m, a, b) { return a + b.toUpperCase(); })
      .replace(/\bDki\b/, 'DKI').replace(/\bDi\b/, 'DI');
  }

  function getJSON(path) {
    if (cache[path]) return Promise.resolve(cache[path]);
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 9000);
    return fetch(API + path, ctl ? { signal: ctl.signal } : undefined)
      .then(function (r) { clearTimeout(timer); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { cache[path] = d; return d; });
  }

  function currentSelect(i) {
    var el = $(order[i]);
    return el && el.tagName === 'SELECT' ? el : null;
  }

  function fillSelect(sel, items, label) {
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = ''; first.textContent = label;
    sel.appendChild(first);
    items.forEach(function (it) {
      var o = document.createElement('option');
      o.value = title(it.name); o.textContent = title(it.name);
      o.setAttribute('data-code', it.id);
      sel.appendChild(o);
    });
  }

  function pickOld(sel) {
    var old = (sel.getAttribute('data-old') || '').toLowerCase();
    sel.removeAttribute('data-old');
    if (!old) return false;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value.toLowerCase() === old) { sel.selectedIndex = i; return true; }
    }
    return false;
  }

  function resetFrom(i) {
    for (; i < order.length; i++) {
      var s = currentSelect(i);
      if (s) { fillSelect(s, [], labels[order[i]]); s.disabled = true; }
    }
  }

  /* Jika API tidak bisa diakses, ganti dropdown dengan kolom ketik manual */
  function toText(from) {
    for (var i = from; i < order.length; i++) {
      var s = currentSelect(i);
      if (!s) continue;
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.id = s.id; inp.name = s.name; inp.className = s.className;
      inp.required = true; inp.maxLength = 100;
      inp.placeholder = 'Ketik ' + labels[order[i]].replace('Pilih ', '');
      inp.value = s.getAttribute('data-old') || '';
      s.parentNode.replaceChild(inp, s);
    }
  }

  function loadLevel(i) {
    var sel = currentSelect(i);
    if (!sel) return Promise.resolve();
    var code = '';
    if (i > 0) {
      var parent = currentSelect(i - 1);
      var opt = parent && parent.options[parent.selectedIndex];
      code = opt ? opt.getAttribute('data-code') : '';
      if (!code) { resetFrom(i); return Promise.resolve(); }
    }
    sel.disabled = true;
    sel.innerHTML = '<option value="">Memuat…</option>';
    return getJSON(paths[i](code)).then(function (items) {
      fillSelect(sel, items, labels[order[i]]);
      sel.disabled = false;
      if (pickOld(sel) && i < order.length - 1) return loadLevel(i + 1);
    }).catch(function () {
      toText(i);
    });
  }

  order.forEach(function (id, i) {
    var el = $(id);
    if (!el || el.tagName !== 'SELECT') return;
    el.addEventListener('change', function () {
      showErr(id, '');
      resetFrom(i + 1);
      if (el.value && i < order.length - 1) loadLevel(i + 1);
    });
  });

  resetFrom(1);
  loadLevel(0);

  /* ---------- Gunakan lokasi saya ---------- */
  var geoBtn = $('bd-geo');
  var geoMsg = $('bd-geo-msg');
  var coord = $('koordinat');

  function say(text, cls) {
    if (!geoMsg) return;
    geoMsg.textContent = text || '';
    geoMsg.className = 'bd-geo-msg' + (cls ? ' ' + cls : '');
  }

  if (geoBtn && coord) {
    geoBtn.addEventListener('click', function () {
      if (!navigator.geolocation) { say('Perangkat Anda tidak mendukung pencarian lokasi.', 'is-bad'); return; }
      if (window.isSecureContext === false) { say('Pencarian lokasi hanya bisa lewat koneksi HTTPS.', 'is-bad'); return; }
      geoBtn.disabled = true;
      say('Mencari lokasi Anda…');
      navigator.geolocation.getCurrentPosition(function (p) {
        coord.value = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6);
        showErr('koordinat', '');
        say('Koordinat berhasil diisi.', 'is-ok');
        geoBtn.disabled = false;
      }, function (err) {
        var m = err.code === 1 ? 'Izin lokasi ditolak. Anda bisa mengisi koordinat secara manual atau melewatinya.'
              : err.code === 3 ? 'Waktu pencarian lokasi habis. Coba lagi.'
              : 'Lokasi tidak dapat ditemukan.';
        say(m, 'is-bad');
        geoBtn.disabled = false;
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    });
  }

  /* ---------- Validasi (mengikuti aturan di server) ---------- */
  function val(id) {
    var el = $(id);
    return el ? String(el.value || '').replace(/\s+/g, ' ').trim() : '';
  }

  var rules = {
    nama: function () {
      var v = val('nama');
      if (!v) return 'Nama lengkap wajib diisi.';
      if (v.length < 3) return 'Nama minimal 3 karakter.';
      if (!/^[\p{L}\p{M}\s.'’-]+$/u.test(v)) return 'Nama hanya boleh berisi huruf.';
    },
    whatsapp: function () {
      var v = val('whatsapp');
      if (!v) return 'Nomor WhatsApp wajib diisi.';
      if (!/^628\d{7,12}$/.test(normalizePhone(v))) return 'Gunakan nomor WhatsApp yang valid, contoh 081234567890.';
    },
    email: function () {
      var v = val('email');
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Format email tidak valid.';
    },
    provinsi: function () { if (!val('provinsi')) return 'Pilih provinsi.'; },
    kota: function () { if (!val('kota')) return 'Pilih kota/kabupaten.'; },
    kecamatan: function () { if (!val('kecamatan')) return 'Pilih kecamatan.'; },
    kelurahan: function () { if (!val('kelurahan')) return 'Pilih kelurahan.'; },
    alamat: function () {
      var v = val('alamat');
      if (!v) return 'Alamat lengkap wajib diisi.';
      if (v.length < 10) return 'Tulis alamat lebih lengkap (jalan, nomor, RT/RW).';
    },
    koordinat: function () {
      var v = val('koordinat');
      if (!v) return;
      var m = v.match(/^(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)$/);
      if (!m) return 'Format koordinat: latitude, longitude. Contoh: -7.257472, 112.752088';
      var lat = parseFloat(m[1]), lng = parseFloat(m[2]);
      if (lat < -11 || lat > 6 || lng < 95 || lng > 141) return 'Koordinat berada di luar wilayah Indonesia.';
    },
    setuju: function () {
      if (!$('setuju').checked) return 'Anda perlu menyetujui kebijakan privasi untuk melanjutkan.';
    }
  };

  var ids = Object.keys(rules);

  ids.forEach(function (id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('blur', function () { showErr(id, rules[id]() || ''); });
    el.addEventListener(id === 'setuju' ? 'change' : 'input', function () {
      if (el.classList.contains('is-invalid')) showErr(id, rules[id]() || '');
    });
  });

  var phone = $('whatsapp');
  if (phone) phone.addEventListener('input', function () {
    var clean = phone.value.replace(/[^\d+\s-]/g, '');
    if (clean !== phone.value) phone.value = clean;
  });

  var submitBtn = $('bd-submit');

  form.addEventListener('submit', function (ev) {
    var first = null;
    ids.forEach(function (id) {
      var msg = rules[id]() || '';
      showErr(id, msg);
      if (msg && !first) first = $(id);
    });
    if (first) {
      ev.preventDefault();
      first.focus({ preventScroll: true });
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (submitBtn) submitBtn.classList.add('is-loading');
  });

  /* tombol kembali dari browser: pulihkan tombol kirim */
  window.addEventListener('pageshow', function () {
    if (submitBtn) submitBtn.classList.remove('is-loading');
  });
})();

</script>
<?php endif; ?>
</body>
</html>