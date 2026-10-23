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
$email     = 'yesnet_official@yesnet.net.id';
$alamatJakarta  = 'Gedung Cyber 1, Lantai 10, Jl. Kuningan Barat No. 8, Jakarta Selatan, 12710';
$alamatSurabaya = 'Wilayah layanan Sidoarjo dan sekitarnya, Jawa Timur';

/* Wilayah yang tersedia. Tambahkan kota baru di sini jika perlu (juga di tabel paket_harga_wifi). */
$wilayahList = ['jakarta' => 'Jakarta', 'sidoarjo' => 'Surabaya'];
$wilayahDipilih = strtolower(trim((string)($_GET['wilayah'] ?? 'jakarta')));
if (!isset($wilayahList[$wilayahDipilih])) { $wilayahDipilih = 'jakarta'; }
$namaWilayah = $wilayahList[$wilayahDipilih];
$alamat = $wilayahDipilih === 'sidoarjo' ? $alamatSurabaya : $alamatJakarta;

$waUrl     = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode('Halo YesNet, saya ingin berlangganan internet. Mohon info paket yang tersedia untuk alamat saya.');

$cssVer = @filemtime(__DIR__ . '/landing.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/landing.js') ?: 1;

/*
 * PROMO
 * Edit di sini jika promo berubah. Tiap skema: [bayar (bulan), total layanan (bulan), label tampilan].
 * Asumsi: "Buy 5 Get 1" = bayar 5 bulan + gratis 1 bulan (total 6 bulan);
 *         "Buy 10 Get 12" = bayar 10 bulan, total layanan 12 bulan;
 *         "Buy 1 Get 1"  = bayar 1 bulan + gratis 1 bulan (total 2 bulan).
 */
$promo = [
    'umum' => [
        'nama'  => 'Promo Umum',
        'sub'   => 'Untuk pelanggan baru YesNet',
        'ikon'  => 'gift',
        'warna' => 'blue',
        'tiers' => [
            [3, 4,  'Bayar 3 Dapat 4'],
            [5, 7,  'Bayar 5 Dapat 7'],
            [8, 12, 'Bayar 8 Dapat 12'],
        ],
    ],
    'nest' => [
        'nama'  => 'Apartemen The Nest & Satu8',
        'sub'   => 'Khusus apartemen The Nest dan Satu8',
        'ikon'  => 'building',
        'warna' => 'violet',
        'tiers' => [
            [5, 6,   'Buy 5 Get 1'],
            [10, 12, 'Buy 10 Get 12'],
        ],
    ],
    'garden' => [
        'nama'  => 'Apartemen City Garden',
        'sub'   => 'Khusus apartemen City Garden',
        'ikon'  => 'building',
        'warna' => 'green',
        'tiers' => [
            [1, 2, 'Buy 1 Get 1'],
        ],
    ],
    'metropolis' => [
        'nama'  => 'Apartemen Metropolis',
        'sub'   => 'Khusus pelanggan Apartemen Metropolis',
        'ikon'  => 'building',
        'warna' => 'violet',
        'tiers' => [
            [3, 4,  'Buy 3 Get 4'],
            [6, 8,  'Buy 6 Get 8'],
            [9, 12, 'Buy 9 Get 12'],
        ],
    ],
];
$promoJs = [];
foreach ($promo as $key => $pr) {
    $promoJs[$key] = ['nama' => $pr['nama'], 'tiers' => array_map(static function ($t) {
        return ['bayar' => $t[0], 'total' => $t[1], 'label' => $t[2]];
    }, $pr['tiers'])];
}

/*
 * Paket diambil langsung dari tabel paket_wifi, sehingga ID, nama, kecepatan dan harga
 * SELALU sama dengan halaman pembayaran.
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

/*
 * Harga per wilayah: jika tabel paket_harga_wifi tersedia, deteksi nama kolom umum.
 * Kolom yang didukung: paket_id/id_paket, wilayah/region/kota, harga/harga_bulanan/price.
 * Jika tabel ini berisi data, paket hanya tampil di wilayah yang punya harganya (filter).
 */
$hargaWilayah = [];
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $tables = $conn->query("SHOW TABLES LIKE 'paket_harga_wifi'");
        if ($tables && $tables->num_rows > 0) {
            $cols = [];
            $cr = $conn->query('SHOW COLUMNS FROM paket_harga_wifi');
            while ($cr && ($c = $cr->fetch_assoc())) $cols[] = $c['Field'];
            $findCol = static function (array $candidates) use ($cols) {
                foreach ($candidates as $candidate) if (in_array($candidate, $cols, true)) return $candidate;
                return null;
            };
            $idCol     = $findCol(['paket_id', 'id_paket', 'wifi_id', 'id_wifi']);
            $regionCol = $findCol(['wilayah', 'region', 'kota', 'lokasi', 'area']);
            $priceCol  = $findCol(['harga', 'harga_bulanan', 'price', 'tarif', 'biaya']);
            $activeCol = $findCol(['status', 'aktif', 'is_active']);
            if ($idCol && $regionCol && $priceCol) {
                $sql = 'SELECT `' . $idCol . '` AS pid, `' . $regionCol . '` AS wilayah, `' . $priceCol . '` AS harga FROM paket_harga_wifi';
                if ($activeCol) $sql .= " WHERE (`$activeCol` IS NULL OR LOWER(CAST(`$activeCol` AS CHAR)) NOT IN ('0','nonaktif','non-aktif','inactive','disabled'))";
                $pr = $conn->query($sql);
                while ($pr && ($hr = $pr->fetch_assoc())) {
                    $reg = strtolower(trim((string)$hr['wilayah']));
                    if (in_array($reg, ['jakarta', 'dki jakarta'], true)) $reg = 'jakarta';
                    if (in_array($reg, ['jatim', 'sidoarjo', 'surabaya', 'kota surabaya', 'jawa timur'], true)) $reg = 'sidoarjo';
                    if (is_numeric($hr['harga'])) $hargaWilayah[(int)$hr['pid']][$reg] = (float)$hr['harga'];
                }
            }
        }
    }
} catch (Throwable $e) { error_log('Landing harga wilayah: ' . $e->getMessage()); }
$filterWilayahAktif = !empty($hargaWilayah);

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

/*
 * Data resmi paket per wilayah yang diberikan YesNet.
 * Nama/ID paket tetap berasal dari database; speed dan harga mengikuti wilayah.
 * Sidoarjo menggunakan kode wilayah JATIM di tabel database.
 */
$paketWilayahResmi = [
    'sidoarjo' => [
        'yeslite'   => ['mbps' => 10,   'harga' => 125000],
        'yeseco'    => ['mbps' => 20,   'harga' => 150000],
        'yeshome'   => ['mbps' => 30,   'harga' => 175000],
        'yesfun'    => ['mbps' => 50,   'harga' => 209000],
        'yesplus'   => ['mbps' => 100,  'harga' => 262000],
        'yesmax'    => ['mbps' => 150,  'harga' => 315000],
        'yespower'  => ['mbps' => 300,  'harga' => 388500],
        'yesgalaxy' => ['mbps' => 1000, 'harga' => 1050000],
    ],
    'jakarta' => [
        'yeslite'  => ['mbps' => 15,  'harga' => 125000],
        'yeseco'   => ['mbps' => 25,  'harga' => 150000],
        'yeshome'  => ['mbps' => 35,  'harga' => 170000],
        'yesfun'   => ['mbps' => 45,  'harga' => 199000],
        'yesplus'  => ['mbps' => 75,  'harga' => 249000],
        'yesmax'   => ['mbps' => 100, 'harga' => 299000],
        'yespower' => ['mbps' => 150, 'harga' => 349000],
        // yesgalaxy memang tidak ditawarkan untuk Jakarta.
    ],
];

$paket = [];
$maxMbps = 1;
foreach ($daftarPaket as $i => $row) {
    $pid = (int) ($row['id'] ?? 0);
    $namaAsli = (string) ($row['nama_paket'] ?? ('Paket ' . ($i + 1)));
    $namaKey = strtolower(trim($namaAsli));
    $namaKey = preg_replace('/^paket\s+/', '', $namaKey);
    $namaKey = preg_replace('/[^a-z0-9]/', '', $namaKey);
    // Cocokkan juga variasi nama dari database seperti "Yeslite 10 Mbps" atau "Yeslite - Jatim".
    foreach (['yeslite','yeseco','yeshome','yesfun','yesplus','yesmax','yespower','yesgalaxy'] as $canonicalName) {
        if ($namaKey === $canonicalName || strpos($namaKey, $canonicalName) === 0) {
            $namaKey = $canonicalName;
            break;
        }
    }

    /* harga dasar (kolom harga di tabel paket_wifi) */
    $baseRaw = null;
    foreach ($row as $k => $v) {
        if (preg_match('/^(harga|price|tarif|biaya)/i', (string) $k) && is_numeric($v) && (float) $v > 0) {
            $baseRaw = (float) $v;
            break;
        }
    }

    /*
     * FILTER UTAMA: wilayah diambil dari kolom `wilayah` pada tabel paket_wifi.
     * Nilai JAKARTA hanya tampil di Jakarta; nilai JATIM hanya tampil di Sidoarjo.
     * Jangan menentukan ketersediaan hanya dari nama paket, karena nama paket
     * (misalnya yeslite) memang berulang untuk tiap wilayah.
     */
    $rowRegion = strtoupper(trim((string)($row['wilayah'] ?? '')));
    if (in_array($rowRegion, ['JATIM', 'JAWA TIMUR', 'SIDOARJO', 'SURABAYA'], true)) {
        $rowRegion = 'sidoarjo';
    } elseif (in_array($rowRegion, ['JAKARTA', 'DKI JAKARTA'], true)) {
        $rowRegion = 'jakarta';
    } else {
        $rowRegion = strtolower($rowRegion);
    }

    $harga = []; $raw = []; $avail = []; $speedByRegion = [];
    foreach (array_keys($wilayahList) as $wl) {
        $override = $paketWilayahResmi[$wl][$namaKey] ?? null;
        $val = $override['harga'] ?? ($hargaWilayah[$pid][$wl] ?? $baseRaw);
        // Data kolom harga pada tabel paket_wifi menjadi sumber utama.
        if (isset($row['harga']) && is_numeric($row['harga'])) {
            $val = (float)$row['harga'];
        }
        $raw[$wl]   = $val;
        $harga[$wl] = $val !== null ? number_format((float)$val, 0, ',', '.') : null;
        $speedByRegion[$wl] = (int)($row['speed_mbps'] ?? ($override['mbps'] ?? 0));
        $avail[$wl] = ($rowRegion === $wl)
            && isset($paketWilayahResmi[$wl][$namaKey]);
    }

    $desc = '';
    foreach (['deskripsi', 'keterangan', 'deskripsi_paket'] as $k) {
        if (!empty($row[$k]) && is_string($row[$k])) {
            $desc = trim(mb_substr(strip_tags($row[$k]), 0, 70));
            break;
        }
    }
    $mbps = (int) ($speedByRegion[$wilayahDipilih] ?? ($row['speed_mbps'] ?? 0));
    $maxMbps = max($maxMbps, $mbps);
    $paket[] = [
        'id'    => $pid,
        'nama'  => (string) ($row['nama_paket'] ?? ('Paket ' . ($i + 1))),
        'desc'  => $desc !== '' ? $desc : $descDefault[min($i, 3)],
        'mbps'  => $mbps,
        'speed' => $speedByRegion,
        'harga' => $harga,
        'raw'   => $raw,
        'avail' => $avail,
        'icon'  => $ikonRank[min($i, 3)],
        'kelas' => $i === 1 ? 'popular' : ($i === 2 ? 'premium' : ($i >= 3 ? 'business' : '')),
    ];
}

$jumlahTampil = 0;
foreach ($paket as $p) { if ($p['avail'][$wilayahDipilih]) $jumlahTampil++; }

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

/* Alur versi customer: singkat, 5 langkah. who: you = dilakukan customer, us = dilakukan tim YesNet */
$langkah = [
    ['pin',    'Cek alamat',         'you', 'Ketik alamat pemasangan di kolom Cek Coverage. Hasilnya langsung terlihat. Jika belum terjangkau, kirim form request dan kami pertimbangkan area Anda.'],
    ['box',    'Pilih paket',        'you', 'Pilih wilayah, paket, dan promo hemat yang Anda mau.'],
    ['card',   'Daftar dan bayar',   'you', 'Isi data diri, lalu bayar online. Data Anda kami verifikasi setelah pembayaran.'],
    ['tool',   'Survei dan jadwal',  'us',  'Tim kami mensurvei lokasi lalu menghubungi Anda untuk jadwal pemasangan.'],
    ['check',  'Internet aktif',     'us',  'Teknisi memasang dan mengaktifkan layanan. Pantau semuanya lewat dashboard.'],
];
$pintas = [
    ['Cek Coverage', appUrl('coverage.php'), 'pin'],
    ['Lihat Paket', '#paket', 'box'],
    [$loginLabel, $loginUrl, 'user'],
];

$faq = [
    ['Bagaimana cara berlangganan YesNet?', 'Cek coverage dengan memasukkan alamat Anda, pilih paket, daftar sebagai customer, lalu lakukan pembayaran. Setelah data diverifikasi, tim kami melakukan survei dan menjadwalkan instalasi.'],
    ['Apakah ada biaya instalasi?', 'Semua paket sudah termasuk instalasi gratis dan WiFi router.'],
    ['Apakah harga paket sama di Jakarta dan Surabaya?', 'Harga dan ketersediaan paket mengikuti wilayah. Pilih Jakarta atau Sidoarjo di bagian Paket untuk melihat daftar yang berlaku.'],
    ['Bagaimana cara mendapat promo bayar beberapa bulan dapat lebih lama?', 'Lihat bagian Promo, pilih skema yang Anda mau, lalu gunakan simulasi hemat untuk melihat total bayar. Sebutkan promo yang dipilih saat mendaftar atau menghubungi kami. Promo apartemen berlaku untuk lokasi apartemen yang tertera. Syarat dan ketentuan berlaku.'],
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
<meta name="description" content="YesNet - Internet fiber optic cepat, stabil dan terjangkau untuk rumah, keluarga dan bisnis. Cek coverage, pilih paket Jakarta atau Sidoarjo, dan nikmati promo berlangganan.">
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
<body data-region="<?= e($wilayahDipilih) ?>">

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
<symbol id="i-gift" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M7.500 8a2.500 2.500 0 1 1 0-5C10 3 12 8 12 8s2-5 4.500-5a2.500 2.500 0 1 1 0 5"/></symbol>
<symbol id="i-building" viewBox="0 0 24 24"><path d="M4 21V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v17M15 9h4a1 1 0 0 1 1 1v11M2 21h20M8 7h3M8 11h3M8 15h3"/></symbol>
<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
<symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></symbol>
</svg>

<!-- NAVBAR -->
<header class="navbar">
  <div class="nav-inner">
    <a href="#home" class="brand" aria-label="YesNet beranda"><?= ic('wifi') ?><strong>YES<span>NET</span></strong></a>

    <nav class="nav-center" id="menu" aria-label="Menu utama">
      <a href="#home" class="active">Home</a>
      <a href="#keunggulan">Keunggulan</a>
      <a href="#paket">Paket</a>
      <a href="#promo">Promo</a>
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
        <a href="#promo" class="btn btn-promo"><?= ic('gift') ?>Lihat Promo</a>
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

<!-- PITA PROMO BERJALAN -->
<a class="promo-strip" href="#promo" aria-label="Lihat promo berlangganan">
  <div class="strip-track">
    <?php for ($copy = 0; $copy < 2; $copy++): ?>
    <div class="strip-group"<?= $copy ? ' aria-hidden="true"' : '' ?>>
      <?php foreach ($promo as $pr): foreach ($pr['tiers'] as $t): ?>
      <span class="strip-item"><?= ic('gift') ?><?= $pr['nama'] === 'Promo Umum' ? '' : '<em>' . e($pr['nama']) . '</em>' ?><?= e($t[2]) ?></span>
      <?php endforeach; endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>
</a>

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

    <!-- Filter wilayah: langsung berubah tanpa memuat ulang halaman (tautan tetap berfungsi tanpa JavaScript) -->
    <div class="region-picker reveal" role="group" aria-label="Pilih wilayah paket">
      <div class="region-picker-title"><?= ic('pin') ?>Pilih wilayah pemasangan</div>
      <div class="region-picker-options">
        <?php foreach ($wilayahList as $kode => $nama): ?>
        <a class="region-btn<?= $wilayahDipilih === $kode ? ' active' : '' ?>" href="?wilayah=<?= e($kode) ?>#paket" data-region="<?= e($kode) ?>" aria-pressed="<?= $wilayahDipilih === $kode ? 'true' : 'false' ?>"><?= ic('pin') ?><?= e($nama) ?></a>
        <?php endforeach; ?>
      </div>
      <p class="region-caption" id="region-caption" aria-live="polite">Menampilkan <strong><?= (int) $jumlahTampil ?> paket</strong> untuk wilayah <strong><?= e($namaWilayah) ?></strong>.</p>
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
      <article class="package-card reveal tilt <?= e($p['kelas']) ?>"
        data-id="<?= e($p['id']) ?>" data-name="<?= e($p['nama']) ?>" data-speed="<?= (int)$p['mbps'] ?>"
        data-price="<?= e($p['harga'][$wilayahDipilih] ?? '') ?>" data-region="<?= e($wilayahDipilih) ?>"
        data-avail="<?= e(implode(' ', array_keys(array_filter($p['avail'])))) ?>"
        <?php foreach ($wilayahList as $kode => $nama): ?>data-price-<?= e($kode) ?>="<?= e($p['harga'][$kode] ?? '') ?>" data-raw-<?= e($kode) ?>="<?= e($p['raw'][$kode] ?? '') ?>" data-speed-<?= e($kode) ?>="<?= (int)($p['speed'][$kode] ?? $p['mbps']) ?>" <?php endforeach; ?>
        <?= $p['avail'][$wilayahDipilih] ? '' : 'hidden' ?>>
        <?php if ($p['kelas'] === 'popular'): ?><div class="popular-label">Paling Populer</div><?php endif; ?>
        <div class="package-top">
          <div class="package-icon"><?= ic($p['icon']) ?></div>
          <div><h3><?= e($p['nama']) ?></h3><small><?= e($p['desc']) ?></small></div>
        </div>
        <div class="package-speed"><?= ic('wifi') ?><span><b data-count="<?= (int)$p['mbps'] ?>"><?= (int)$p['mbps'] ?></b> Mbps</span></div>
        <div class="speed-bar" aria-hidden="true"><i style="--w:<?= round($p['mbps'] / $maxMbps * 100) ?>%"></i></div>
        <div class="package-price"><?php $hg = $p['harga'][$wilayahDipilih] ?? null; if ($hg !== null): ?><span class="cur">Rp</span><?= e($hg) ?><small>/bulan</small><?php else: ?><span class="cur">Hubungi kami</span><?php endif; ?></div>
        <a class="package-promo" href="#promo"><?= ic('gift') ?>Lihat promo sesuai lokasi</a>
        <ul>
          <li><?= ic('check') ?>Internet Unlimited</li>
          <li><?= ic('check') ?>WiFi Router</li>
          <li><?= ic('check') ?>Instalasi Gratis</li>
        </ul>
        <a href="<?= e(appUrl('pilih_paket.php')) ?>?paket=<?= (int)$p['id'] ?>&amp;wilayah=<?= e($wilayahDipilih) ?>" class="package-button" aria-label="Pilih paket <?= e($p['nama']) ?> wilayah <?= e($namaWilayah) ?>">Pilih Paket <?= ic('arrow') ?></a>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="packages-empty" id="packages-empty"<?= $jumlahTampil ? ' hidden' : '' ?>>
      <?= ic('pin') ?>
      <h3>Paket untuk wilayah ini belum tersedia</h3>
      <p>Hubungi tim kami untuk info paket dan jadwal pemasangan di wilayah Anda.</p>
      <a href="<?= e($waUrl) ?>" class="btn btn-wa btn-sm" target="_blank" rel="noopener"><?= ic('chat') ?>Tanya lewat WhatsApp</a>
    </div>

    <p class="packages-note reveal">Butuh bantuan memilih? <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener">Chat kami lewat WhatsApp</a></p>
  </div>
</section>

<!-- PROMO -->
<section id="promo" class="section promo-section">
  <div class="container">
    <div class="section-heading reveal">
      <span class="section-label">Promo Berlangganan</span>
      <h2>Bayar Lebih Sedikit, <span class="grad">Internet Lebih Lama</span></h2>
      <p>Pilih promo umum atau promo khusus apartemen, lalu klik skema untuk menghitung penghematan.</p>
    </div>

    <div class="promo-grid">
      <?php foreach ($promo as $key => $pr): ?>
      <article class="promo-card reveal tilt p-<?= e($pr['warna']) ?>">
        <div class="promo-head">
          <div class="promo-icon"><?= ic($pr['ikon']) ?></div>
          <div><h3><?= e($pr['nama']) ?></h3><small><?= e($pr['sub']) ?></small></div>
        </div>
        <ul class="promo-tiers">
          <?php foreach ($pr['tiers'] as $ti => $t): $gratis = $t[1] - $t[0]; ?>
          <li>
            <button type="button" class="tier" data-promo="<?= e($key) ?>" data-tier="<?= (int) $ti ?>">
              <span><b><?= e($t[2]) ?></b><small>Total <?= (int) $t[1] ?> bulan layanan</small></span>
              <span class="badge">Gratis <?= (int) $gratis ?> bulan</span>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
        <p class="promo-hint">Klik skema untuk simulasi hemat</p>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="promo-calc reveal" id="promo-calc"
         data-promos="<?= e(json_encode($promoJs, JSON_UNESCAPED_UNICODE)) ?>"
         data-pilih="<?= e(appUrl('pilih_paket.php')) ?>"
         data-wa="https://wa.me/<?= e($waNumber) ?>">
      <div class="calc-title"><?= ic('calendar') ?>Simulasi hemat <span class="calc-chip">Wilayah <strong class="calc-region"><?= e($namaWilayah) ?></strong></span></div>

      <div class="calc-row">
        <label>Promo<select id="calc-promo"></select></label>
        <label>Skema<select id="calc-tier"></select></label>
        <label>Paket<select id="calc-paket"></select></label>
      </div>

      <div class="calc-result" id="calc-result" aria-live="polite">
        <div class="stat"><small>Anda bayar</small><b id="rs-bayar">-</b><span id="rs-bayar-bln"></span></div>
        <div class="stat"><small>Anda dapat</small><b id="rs-dapat">-</b><span id="rs-gratis"></span></div>
        <div class="stat hl"><small>Total hemat</small><b id="rs-hemat">-</b><span id="rs-eff"></span></div>
      </div>
      <p class="calc-note" id="calc-note">Harga normal dihitung dari harga bulanan paket di wilayah terpilih. Syarat dan ketentuan berlaku.</p>
      <div class="calc-actions">
        <a href="#" class="btn btn-primary btn-sm" id="calc-go">Pakai promo ini <?= ic('arrow') ?></a>
        <a href="#" class="btn btn-outline btn-sm" id="calc-wa" target="_blank" rel="noopener"><?= ic('chat') ?>Tanya via WhatsApp</a>
      </div>
    </div>
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
      <div class="flow-body">
        <p class="flow-legend"><span class="who you">Anda</span> yang melakukan &nbsp; <span class="who us">Tim YesNet</span> yang mengurus</p>
        <ol class="timeline">
          <?php foreach ($langkah as $n => $s): ?>
          <li class="tl-item" style="--k:<?= (int) $n ?>">
            <span class="tl-dot"><?= $s[2] === 'us' && $n === count($langkah) - 1 ? ic('check') : (int) ($n + 1) ?></span>
            <div class="tl-card">
              <div class="tl-head"><h4><?= e($s[1]) ?></h4><span class="who <?= e($s[2]) ?>"><?= $s[2] === 'you' ? 'Anda' : 'Tim YesNet' ?></span></div>
              <p><?= e($s[3]) ?></p>
            </div>
          </li>
          <?php endforeach; ?>
        </ol>
        <div class="flow-quick">
          <?php foreach ($pintas as $q): ?>
          <a href="<?= e($q[1]) ?>" class="btn btn-outline btn-sm"><?= ic($q[2]) ?><?= e($q[0]) ?></a>
          <?php endforeach; ?>
        </div>
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
        <a href="#home">Home</a><a href="#keunggulan">Keunggulan</a><a href="#paket">Paket</a><a href="#promo">Promo</a><a href="#alur">Cara Daftar</a><a href="#faq">FAQ</a></div>
      <div class="footer-column"><h4>Hubungi Kami</h4>
        <a href="tel:<?= e($telp) ?>"><?= ic('phone') ?><?= e($telp) ?></a>
        <a href="mailto:<?= e($email) ?>"><?= ic('mail') ?><?= e($email) ?></a>
        <a href="<?= e(appUrl('coverage.php')) ?>" id="footer-alamat" data-alamat-jakarta="<?= e($alamatJakarta) ?>" data-alamat-surabaya="<?= e($alamatSurabaya) ?>"><?= ic('pin') ?><span class="addr-text"><?= e($alamat) ?></span></a></div>
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