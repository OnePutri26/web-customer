<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session: jangan dobel start (config/app.php bisa saja sudah memulainya)
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Load konfigurasi utama
|--------------------------------------------------------------------------
*/
$configFile = null;
$docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

/* Folder kandidat: folder file ini + semua folder di atasnya (maks. 6 tingkat) + document root */
$bases = [];
$walk = __DIR__;
for ($lvl = 0; $lvl < 6; $lvl++) {
    $bases[] = $walk;
    $up = dirname($walk);
    if ($up === $walk) {
        break;
    }
    $walk = $up;
}
if ($docRoot !== '') {
    $bases[] = $docRoot;
}
$bases = array_values(array_unique($bases));

/* 1) config/app.php langsung di salah satu folder kandidat */
foreach ($bases as $base) {
    if (is_file($base . '/config/app.php')) {
        $configFile = $base . '/config/app.php';
        break;
    }
}

/* 2) config/app.php di subfolder langsung (mis. customer/config, app/config) */
if ($configFile === null) {
    foreach ($bases as $base) {
        $found = glob($base . '/*/config/app.php') ?: [];
        if ($found !== []) {
            $configFile = $found[0];
            break;
        }
    }
}

if ($configFile === null) {
    error_log('YesNet coverage: config/app.php tidak ditemukan dari ' . __DIR__);
    http_response_code(500);
    // DIAGNOSTIK SEMENTARA: hapus blok ini setelah halaman berjalan normal.
    header('Content-Type: text/plain; charset=utf-8');
    $listing = static function (string $dir): string {
        $items = @scandir($dir);
        return $items === false ? '(tidak bisa dibaca)' : implode(', ', array_diff($items, ['.', '..']));
    };
    $sub = static function (string $name) use ($listing, $docRoot): string {
        $dir = ($docRoot !== '' ? $docRoot : __DIR__) . '/' . $name;
        return is_dir($dir) ? $listing($dir) : '(folder tidak ada)';
    };
    exit(
        "config/app.php tidak ditemukan.\n\n" .
        "Folder file ini : " . __DIR__ . "\n" .
        "Document root   : " . $docRoot . "\n\n" .
        "Isi config/     : " . $sub('config') . "\n" .
        "Isi customer/   : " . $sub('customer') . "\n" .
        "Isi inc/        : " . $sub('inc') . "\n" .
        "Isi assets/     : " . $sub('assets')
    );
}

require_once $configFile;
$baseDir = dirname($configFile, 2);
unset($bases, $walk, $up, $lvl, $base, $found);

/*
|--------------------------------------------------------------------------
| Helper escaping (didefinisikan sebelum dipakai, hanya jika belum ada)
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| KONFIGURASI SINKRONISASI TABEL ODP  <-- SESUAIKAN DI SINI
|--------------------------------------------------------------------------
| Semua pengecekan coverage kini hanya membaca tabel ODP di bawah ini.
| Ubah nama tabel/kolom agar cocok dengan database Anda.
|
|   table          : nama tabel ODP
|   id / nama      : kolom ID dan nama ODP (mis. "ODP-SDA-001")
|   lat / lng      : kolom koordinat ODP (tipe DECIMAL/FLOAT/DOUBLE, derajat desimal)
|   status_kolom   : (opsional) kolom status; isi null jika tidak ada
|   status_nilai   : nilai status yang dianggap aktif
|   sisa_port_kolom: (opsional) kolom sisa port; ODP dengan sisa 0 diabaikan; null = nonaktif
|
| COV_RADIUS_KM    : jarak maksimum rumah ke ODP agar dianggap tercover (km)
*/
if (!defined('COV_ODP')) {
    define('COV_ODP', [
        'table'            => 'odp',
        'id'               => 'id',
        'nama'             => 'nama_odp',
        'lat'              => 'latitude',
        'lng'              => 'longitude',
        'status_kolom'     => null,
        'status_nilai'     => 'aktif',
        'sisa_port_kolom'  => null,
    ]);
}
if (!defined('COV_RADIUS_KM')) {
    define('COV_RADIUS_KM', 0.25);
}
/* Pusat peta awal (Sidoarjo) bila belum ada titik */
const COV_MAP_LAT = -7.4478;
const COV_MAP_LNG = 112.7183;

/** Pastikan nama tabel/kolom aman sebelum dimasukkan ke SQL. */
function covIdent(mixed $name): string
{
    $name = (string) $name;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama tabel/kolom ODP tidak valid: ' . $name);
    }
    return '`' . $name . '`';
}

/** Filter opsional (status aktif, sisa port) -> [sql, types, params]. */
function covOdpFilter(): array
{
    $c = COV_ODP;
    $sql = '';
    $types = '';
    $params = [];
    if (!empty($c['status_kolom'])) {
        $sql .= ' AND ' . covIdent($c['status_kolom']) . ' = ?';
        $types .= 's';
        $params[] = (string) $c['status_nilai'];
    }
    if (!empty($c['sisa_port_kolom'])) {
        $sql .= ' AND ' . covIdent($c['sisa_port_kolom']) . ' > 0';
    }
    return [$sql, $types, $params];
}

/** Eksekusi prepared statement dengan parameter dinamis, kembalikan semua baris. */
function covFetchAll(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare gagal: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Cari ODP terdekat dari titik rumah langsung di tabel ODP.
 * Tersedia bila jarak <= COV_RADIUS_KM.
 */
function covCekOdp(mysqli $conn, ?float $lat, ?float $lng): array
{
    $none = ['tersedia' => false, 'odp_id' => null, 'odp' => null, 'jarak' => null, 'odp_lat' => null, 'odp_lng' => null];
    if ($lat === null || $lng === null) {
        return $none;
    }

    $c = COV_ODP;
    $t = covIdent($c['table']);
    $cId = covIdent($c['id']);
    $cNama = covIdent($c['nama']);
    $cLat = covIdent($c['lat']);
    $cLng = covIdent($c['lng']);

    /* Kotak pembatas 3 km agar query ringan di tabel besar */
    $boxKm = 3.0;
    $dLat = $boxKm / 111.32;
    $dLng = $boxKm / (111.32 * max(0.01, cos(deg2rad($lat))));

    [$fSql, $fTypes, $fParams] = covOdpFilter();

    $sql = "SELECT $cId AS odp_id, $cNama AS odp_nama, $cLat AS odp_lat, $cLng AS odp_lng,
                   (6371 * ACOS(LEAST(1,
                        COS(RADIANS(?)) * COS(RADIANS($cLat)) * COS(RADIANS($cLng) - RADIANS(?))
                        + SIN(RADIANS(?)) * SIN(RADIANS($cLat))
                   ))) AS jarak
            FROM $t
            WHERE $cLat BETWEEN ? AND ?
              AND $cLng BETWEEN ? AND ?
              $fSql
            ORDER BY jarak ASC
            LIMIT 1";

    $params = array_merge(
        [$lat, $lng, $lat, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng],
        $fParams
    );
    $rows = covFetchAll($conn, $sql, 'ddddddd' . $fTypes, $params);

    if ($rows === []) {
        return $none;
    }

    $row = $rows[0];
    $jarak = (float) $row['jarak'];
    if ($jarak > COV_RADIUS_KM) {
        return $none;
    }

    $nama = trim((string) ($row['odp_nama'] ?? ''));
    return [
        'tersedia' => true,
        'odp_id'   => $row['odp_id'],
        'odp'      => $nama !== '' ? $nama : 'ODP #' . $row['odp_id'],
        'jarak'    => $jarak,
        'odp_lat'  => (float) $row['odp_lat'],
        'odp_lng'  => (float) $row['odp_lng'],
    ];
}

/*
|--------------------------------------------------------------------------
| Endpoint JSON: daftar ODP di area peta yang sedang dilihat
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['odp'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');

    $s = filter_var($_GET['s'] ?? null, FILTER_VALIDATE_FLOAT);
    $n = filter_var($_GET['n'] ?? null, FILTER_VALIDATE_FLOAT);
    $w = filter_var($_GET['w'] ?? null, FILTER_VALIDATE_FLOAT);
    $ea = filter_var($_GET['e'] ?? null, FILTER_VALIDATE_FLOAT);

    $valid = $s !== false && $n !== false && $w !== false && $ea !== false
        && $s !== null && $n !== null && $w !== null && $ea !== null
        && $s >= -90 && $n <= 90 && $w >= -180 && $ea <= 180
        && $n > $s && $ea > $w
        && ($n - $s) <= 0.2 && ($ea - $w) <= 0.2; // batasi area agar tidak membuang seluruh tabel

    if (!$valid) {
        echo json_encode(['odp' => []]);
        exit;
    }

    try {
        $c = COV_ODP;
        [$fSql, $fTypes, $fParams] = covOdpFilter();
        $sql = 'SELECT ' . covIdent($c['id']) . ' AS id, ' . covIdent($c['nama']) . ' AS nama, '
             . covIdent($c['lat']) . ' AS lat, ' . covIdent($c['lng']) . ' AS lng
                FROM ' . covIdent($c['table']) . '
                WHERE ' . covIdent($c['lat']) . ' BETWEEN ? AND ?
                  AND ' . covIdent($c['lng']) . ' BETWEEN ? AND ?' . $fSql . '
                LIMIT 400';
        $rows = covFetchAll($conn, $sql, 'dddd' . $fTypes, array_merge([$s, $n, $w, $ea], $fParams));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'   => (string) $r['id'],
                'nama' => (string) ($r['nama'] ?? ''),
                'lat'  => (float) $r['lat'],
                'lng'  => (float) $r['lng'],
            ];
        }
        echo json_encode(['odp' => $out]);
    } catch (Throwable $e) {
        error_log('Coverage ODP json error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['odp' => [], 'error' => true]);
    }
    exit;
}

$error = '';
$serviceError = false;

$alamat = trim((string) ($_GET['alamat'] ?? ''));

$addressLength = static function (string $value): int {
    $length = preg_match_all('/./us', $value);
    return $length === false ? 0 : $length;
};

/*
 * Parse koordinat: hanya angka valid dan masuk rentang yang diterima.
 * Nilai kosong / tidak valid menjadi null (bukan 0.0, yang titiknya di
 * tengah Samudra Atlantik dan akan merusak perhitungan jarak ODP).
 */
$parseCoord = static function ($raw, float $min, float $max): ?float {
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value >= $min && $value <= $max) ? $value : null;
};

$auto = isset($_GET['auto']) && $addressLength($alamat) >= 10;
$lat = null;
$lng = null;


/*
|--------------------------------------------------------------------------
| Proses pengecekan coverage
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $alamat = trim((string) ($_POST['alamat'] ?? ''));

    $lat = $parseCoord($_POST['latitude']  ?? '', -90.0, 90.0);
    $lng = $parseCoord($_POST['longitude'] ?? '', -180.0, 180.0);

    // Koordinat harus berpasangan; kalau salah satu tidak valid, buang keduanya.
    if ($lat === null || $lng === null) {
        $lat = null;
        $lng = null;
    }

    if (function_exists('csrf_ok') && !csrf_ok()) {

        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';

    } elseif ($addressLength($alamat) < 10) {

        $error = 'Masukkan alamat lengkap minimal 10 karakter.';

    } elseif ($lat === null || $lng === null) {

        // Pengecekan dilakukan terhadap koordinat ODP, jadi titik rumah wajib ada.
        $error = 'Tandai lokasi rumah Anda di peta terlebih dahulu (cari alamat, ketuk peta, atau gunakan lokasi saya).';

    } else {

        try {

            $h = covCekOdp($conn, $lat, $lng);
            unset($_SESSION['coverage_request_id']);
            $pendingPackageId = filter_var(
                $_SESSION['pending_coverage_package_id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            /*
             * Struktur session 'flow' dipertahankan sama persis, karena
             * coverage_hasil.php, paket.php, request_coverage.php dan halaman
             * sesudahnya membacanya. odp_lat/odp_lng adalah tambahan baru
             * (aman diabaikan) untuk menampilkan peta di halaman hasil.
             */
            $_SESSION['flow'] = [
                'mode'     => 'coverage',
                'alamat'   => $alamat,
                'lat'      => $lat,
                'lng'      => $lng,
                'tersedia' => !empty($h['tersedia']),
                'odp_id'   => $h['odp_id'] ?? null,
                'odp'      => $h['odp'] ?? null,
                'jarak'    => $h['jarak'] ?? null,
                'odp_lat'  => $h['odp_lat'] ?? null,
                'odp_lng'  => $h['odp_lng'] ?? null,
            ];
            if (!empty($h['tersedia']) && $pendingPackageId > 0) {
                $_SESSION['flow']['paket_id'] = $pendingPackageId;
            }

            if (function_exists('redirect')) {
                redirect('coverage_hasil.php');
            } else {
                header('Location: coverage_hasil.php');
            }
            exit;

        } catch (Throwable $e) {

            // Detail error hanya ke log server, bukan ke customer.
            error_log('Coverage error: ' . $e->getMessage());

            $serviceError = true;
            $error = 'Layanan pengecekan coverage sedang mengalami gangguan. Alamat Anda belum dapat diperiksa.';
        }
    }
}

$pageTitle = 'Cek Coverage';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title><?= e($pageTitle) ?> | YesNet</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">

    <style>
        :root {
            --ink: #0a1a33; --brand: #1677ff; --ok: #12b76a; --warn: #f79009; --err: #d92d20;
            --paper: #f3f6fb; --card: #ffffff; --line: #e3e9f2; --text: #1d2939; --muted: #667085;
            --radius: 20px;
            --font: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; flex-direction: column;
            font-family: var(--font); color: var(--text); line-height: 1.55;
            background: radial-gradient(circle at 1px 1px, #d5dded 1px, transparent 0) 0 0 / 22px 22px, var(--paper);
        }
        :focus-visible { outline: 3px solid rgba(22,119,255,.45); outline-offset: 2px; }

        .cv-header { background: var(--ink); color: #fff; padding-top: env(safe-area-inset-top, 0px); }
        .cv-header .in { max-width: 1080px; margin: 0 auto; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .cv-logo { font-weight: 800; font-size: 22px; letter-spacing: -.5px; text-decoration: none; color: #fff; }
        .cv-logo span { color: #5aa2ff; }
        .cv-back { font-size: 14px; font-weight: 600; text-decoration: none; color: #c9d7ee; padding: 8px 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,.18); transition: background .15s, color .15s; }
        .cv-back:hover { background: rgba(255,255,255,.1); color: #fff; }
        .cv-footer { margin-top: auto; padding: 28px 20px calc(28px + env(safe-area-inset-bottom, 0px)); text-align: center; color: var(--muted); font-size: 13px; }
        .cv-footer p { margin: 0; }

        .cv-main { padding: 32px 16px 48px; }
        .cv-wrap { max-width: 760px; margin: 0 auto; }
        .cv-head { margin: 0 0 18px; }
        .cv-head h1 { margin: 0 0 6px; font-size: clamp(26px, 4.6vw, 34px); letter-spacing: -.9px; line-height: 1.15; font-weight: 800; color: var(--ink); }
        .cv-head p { margin: 0; color: #475467; max-width: 56ch; }

        .cv-card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 26px; box-shadow: 0 1px 2px rgba(10,26,51,.04), 0 24px 48px -24px rgba(10,26,51,.28); }
        .cv-alert { border-radius: 14px; padding: 13px 16px; font-size: 14.5px; font-weight: 600; margin-bottom: 18px; background: #fef3f2; border: 1px solid #fecdca; color: #b42318; }

        .cv-label { display: block; margin-bottom: 6px; font-size: 13.5px; font-weight: 700; }
        .cv-input {
            width: 100%; font: inherit; font-size: 15px; color: var(--text); background: #fff;
            border: 1.5px solid #d3dbe8; border-radius: 12px; padding: 12px 14px; min-height: 92px; resize: vertical;
            transition: border-color .15s, box-shadow .15s;
        }
        .cv-input::placeholder { color: #98a2b3; }
        .cv-input:hover { border-color: #aebad0; }
        .cv-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(22,119,255,.15); }

        .cv-tools { display: flex; flex-wrap: wrap; gap: 10px; margin: 12px 0 14px; }
        .cv-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 46px; padding: 0 18px; border-radius: 14px; border: 1.5px solid var(--line);
            background: #fff; color: var(--ink); font: inherit; font-weight: 700; font-size: 14.5px;
            text-decoration: none; cursor: pointer; transition: transform .12s, background .15s;
        }
        .cv-btn:hover { background: var(--paper); }
        .cv-btn:active { transform: translateY(1px); }
        .cv-btn[hidden] { display: none; }
        .cv-btn[disabled] { opacity: .65; cursor: progress; }
        .cv-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 10px 20px -10px rgba(22,119,255,.7); }
        .cv-btn.primary:hover { background: #0f67e6; }
        .cv-btn.block { width: 100%; min-height: 52px; font-size: 16px; margin-top: 14px; }
        .cv-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Peta */
        .cv-mapbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
        .cv-mapbar .t { font-size: 13.5px; font-weight: 700; }
        .cv-seg { display: inline-flex; background: var(--paper); border: 1px solid var(--line); border-radius: 12px; padding: 3px; }
        .cv-seg button { font: inherit; font-size: 13px; font-weight: 700; border: 0; background: transparent; color: var(--muted); padding: 7px 14px; border-radius: 9px; cursor: pointer; }
        .cv-seg button[aria-pressed="true"] { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(10,26,51,.15); }
        .cv-map { height: 360px; border-radius: 16px; border: 1px solid var(--line); overflow: hidden; isolation: isolate; background: #e8eef7; }
        .cv-map .leaflet-container { font-family: var(--font); }
        .cv-pin { filter: drop-shadow(0 4px 6px rgba(10,26,51,.4)); }
        .cv-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 10px 0 0; padding: 0; list-style: none; font-size: 12.5px; color: var(--muted); }
        .cv-legend li { display: flex; align-items: center; gap: 7px; }
        .cv-legend i { display: inline-block; width: 12px; height: 12px; border-radius: 50%; }
        .cv-legend .l-pin { background: var(--err); }
        .cv-legend .l-odp { background: var(--brand); border: 2px solid #fff; box-shadow: 0 0 0 1px #c4cfe2; }
        .cv-legend .l-ring { background: rgba(18,183,106,.2); border: 1.5px solid var(--ok); }

        .cv-status { margin-top: 14px; border-radius: 14px; padding: 12px 16px; background: var(--paper); border: 1px solid var(--line); font-size: 14.5px; }
        .cv-status.ok { background: #ecfdf3; border-color: #abefc6; color: #067647; }
        .cv-status.far { background: #fffaf0; border-color: #fedf89; color: #93370d; }
        .cv-status .coords { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 6px; font-size: 12.5px; color: var(--muted); }
        .cv-status a { color: var(--brand); font-weight: 700; text-decoration: none; }
        .cv-status a:hover { text-decoration: underline; }

        .cv-tip { margin-top: 18px; display: flex; gap: 12px; padding: 14px 18px; border-radius: 14px; background: #eaf3ff; color: #134a9e; font-size: 14px; }
        .cv-tip strong { display: block; }
        .cv-tip p { margin: 2px 0 0; }

        @media (max-width: 600px) {
            .cv-main { padding: 20px 12px 36px; }
            .cv-card { padding: 18px; }
            .cv-map { height: 320px; }
            .cv-tools .cv-btn { flex: 1 1 100%; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>

<body>

<header class="cv-header">
    <div class="in">
        <a href="index.php" class="cv-logo">YES<span>NET</span></a>
        <a href="index.php" class="cv-back">← Kembali</a>
    </div>
</header>

<main class="cv-main">
    <div class="cv-wrap">

        <div class="cv-head">
            <h1>Cek coverage</h1>
            <p>Tandai rumah Anda di peta untuk mengetahui apakah jaringan YesNet sudah menjangkau lokasi pemasangan.</p>
        </div>

        <div class="cv-card">

            <?php if ($error): ?>
                <div class="cv-alert" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form
                id="covForm"
                method="post"
                action="coverage.php"
                data-auto="<?= $auto ? '1' : '0' ?>"
                data-radius="<?= e((string) COV_RADIUS_KM) ?>"
                data-lat0="<?= e((string) COV_MAP_LAT) ?>"
                data-lng0="<?= e((string) COV_MAP_LNG) ?>"
                novalidate
            >

                <?php
                if (function_exists('csrf_field')) {
                    echo csrf_field();
                }
                ?>

                <input type="hidden" name="latitude" id="latitude" value="<?= e($lat ?? '') ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?= e($lng ?? '') ?>">

                <label class="cv-label" for="alamat">Alamat lengkap</label>
                <textarea
                    class="cv-input"
                    id="alamat"
                    name="alamat"
                    required
                    minlength="10"
                    placeholder="Contoh: Jl. Merdeka No. 123, Kel. Sidokare, Sidoarjo"
                ><?= e($alamat) ?></textarea>

                <div class="cv-tools">
                    <button class="cv-btn" type="button" id="covSearch">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                        Cari alamat di peta
                    </button>
                    <button class="cv-btn" type="button" id="covGps" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
                        Gunakan lokasi saya
                    </button>
                </div>

                <div class="cv-mapbar">
                    <span class="t">Lokasi rumah</span>
                    <div class="cv-seg" role="group" aria-label="Jenis peta">
                        <button type="button" id="covLayerMap" aria-pressed="true">Peta</button>
                        <button type="button" id="covLayerSat" aria-pressed="false">Satelit</button>
                    </div>
                </div>

                <div id="covMap" class="cv-map" role="application" aria-label="Peta untuk menandai lokasi rumah"></div>

                <ul class="cv-legend">
                    <li><i class="l-pin"></i>Lokasi rumah (geser untuk menyesuaikan)</li>
                    <li><i class="l-odp"></i>ODP YesNet</li>
                    <li><i class="l-ring"></i>Jangkauan <span id="covRadiusTxt"></span></li>
                </ul>

                <div id="covInfo" class="cv-status" aria-live="polite">
                    Cari alamat atau ketuk peta untuk menandai rumah Anda. Pilih tampilan Satelit agar atap rumah lebih mudah dikenali.
                </div>

                <button class="cv-btn primary block" type="submit" id="covSubmit">
                    <?= $serviceError ? 'Coba lagi' : 'Cek ketersediaan' ?>
                </button>

            </form>

        </div>

        <div class="cv-tip">
            <div>
                <strong>Tips</strong>
                <p>Zoom sampai atap rumah terlihat, lalu letakkan pin tepat di rumah Anda. Hasil pengecekan dihitung dari jarak titik tersebut ke ODP terdekat.</p>
            </div>
        </div>

    </div>
</main>

<footer class="cv-footer">
    <p>© <?= date('Y') ?> YesNet. All rights reserved.</p>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
    var form = document.getElementById('covForm');
    if (!form || typeof L === 'undefined') {
        var infoFail = document.getElementById('covInfo');
        if (infoFail) { infoFail.textContent = 'Peta gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.'; }
        return;
    }

    var $ = function (id) { return document.getElementById(id); };
    var latEl = $('latitude'), lngEl = $('longitude'), addrEl = $('alamat');
    var infoEl = $('covInfo'), submitBtn = $('covSubmit'), gpsBtn = $('covGps'), searchBtn = $('covSearch');
    var radiusKm = parseFloat(form.dataset.radius) || 0.25;
    var radiusM = radiusKm * 1000;
    var actionUrl = form.getAttribute('action') || 'coverage.php';
    $('covRadiusTxt').textContent = Math.round(radiusM) + ' m';

    /* ---------- Peta & lapisan ---------- */
    var map = L.map('covMap', { zoomControl: true, maxZoom: 20 })
        .setView([parseFloat(form.dataset.lat0), parseFloat(form.dataset.lng0)], 13);

    var street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxNativeZoom: 19, maxZoom: 20, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    var sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxNativeZoom: 19, maxZoom: 20, attribution: 'Tiles &copy; Esri, Maxar, Earthstar Geographics'
    });
    var btnMap = $('covLayerMap'), btnSat = $('covLayerSat');
    function useLayer(satellite) {
        if (satellite) { map.removeLayer(street); sat.addTo(map); }
        else { map.removeLayer(sat); street.addTo(map); }
        btnMap.setAttribute('aria-pressed', satellite ? 'false' : 'true');
        btnSat.setAttribute('aria-pressed', satellite ? 'true' : 'false');
    }
    btnMap.addEventListener('click', function () { useLayer(false); });
    btnSat.addEventListener('click', function () { useLayer(true); });

    /* ---------- Pin rumah ---------- */
    var pinIcon = L.divIcon({
        className: 'cv-pin',
        html: '<svg width="34" height="44" viewBox="0 0 34 44" aria-hidden="true"><path d="M17 43C17 43 31 27.5 31 16.5 31 8.5 24.7 2 17 2S3 8.5 3 16.5C3 27.5 17 43 17 43Z" fill="#d92d20" stroke="#fff" stroke-width="2.5"/><path d="M10.5 18.5L17 12l6.5 6.5V25h-4v-4.5h-5V25h-4z" fill="#fff"/></svg>',
        iconSize: [34, 44], iconAnchor: [17, 42]
    });
    var marker = null;
    var odpLayer = L.layerGroup().addTo(map);
    var odpCache = {};   // id -> {lat,lng,nama}

    function hav(lat1, lng1, lat2, lng2) {
        var R = 6371000, r = Math.PI / 180;
        var dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function hasPoint() { return latEl.value !== '' && lngEl.value !== ''; }

    function renderInfo() {
        infoEl.className = 'cv-status';
        infoEl.textContent = '';
        if (!hasPoint()) {
            infoEl.textContent = 'Cari alamat atau ketuk peta untuk menandai rumah Anda. Pilih tampilan Satelit agar atap rumah lebih mudah dikenali.';
            return;
        }
        var lat = parseFloat(latEl.value), lng = parseFloat(lngEl.value);
        var best = null, bestD = Infinity;
        Object.keys(odpCache).forEach(function (k) {
            var o = odpCache[k], d = hav(lat, lng, o.lat, o.lng);
            if (d < bestD) { bestD = d; best = o; }
        });

        var line = document.createElement('div');
        if (best === null) {
            line.textContent = 'Belum ada ODP yang terlihat di sekitar titik ini. Hasil resmi muncul setelah Anda menekan Cek ketersediaan.';
        } else if (bestD <= radiusM) {
            infoEl.className = 'cv-status ok';
            line.textContent = 'ODP terdekat' + (best.nama ? ' (' + best.nama + ')' : '') + ' sekitar ' + Math.round(bestD) + ' m. Kemungkinan jaringan tersedia.';
        } else {
            infoEl.className = 'cv-status far';
            line.textContent = 'ODP terdekat sekitar ' + Math.round(bestD) + ' m, di luar jangkauan ' + Math.round(radiusM) + ' m. Pastikan pin tepat di rumah Anda.';
        }
        infoEl.appendChild(line);

        var meta = document.createElement('div');
        meta.className = 'coords';
        var c = document.createElement('span');
        c.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
        var a = document.createElement('a');
        a.href = 'https://www.google.com/maps?q=' + lat.toFixed(6) + ',' + lng.toFixed(6);
        a.target = '_blank'; a.rel = 'noopener';
        a.textContent = 'Buka di Google Maps';
        meta.appendChild(c); meta.appendChild(a);
        infoEl.appendChild(meta);
    }

    function setPoint(lat, lng, zoom) {
        latEl.value = lat.toFixed(6);
        lngEl.value = lng.toFixed(6);
        var ll = L.latLng(lat, lng);
        if (marker === null) {
            marker = L.marker(ll, { icon: pinIcon, draggable: true, keyboard: true, title: 'Lokasi rumah' }).addTo(map);
            marker.on('dragend', function () {
                var p = marker.getLatLng();
                setPoint(p.lat, p.lng);
            });
        } else {
            marker.setLatLng(ll);
        }
        if (zoom) { map.setView(ll, zoom); }
        loadOdp();
        renderInfo();
    }

    map.on('click', function (ev) { setPoint(ev.latlng.lat, ev.latlng.lng); });

    /* ---------- ODP dari database (lewat endpoint ?odp=1) ---------- */
    var odpTimer = null, odpCtl = null;
    function drawOdp(o) {
        var dot = L.circleMarker([o.lat, o.lng], { radius: 6, color: '#fff', weight: 2, fillColor: '#1677ff', fillOpacity: 1 });
        var pop = document.createElement('div');
        var b = document.createElement('strong'); b.textContent = o.nama || ('ODP ' + o.id);
        pop.appendChild(b);
        dot.bindPopup(pop);
        dot.addTo(odpLayer);
        L.circle([o.lat, o.lng], { radius: radiusM, color: '#12b76a', weight: 1.5, fillColor: '#12b76a', fillOpacity: 0.12, interactive: false }).addTo(odpLayer);
    }
    function loadOdp() {
        clearTimeout(odpTimer);
        odpTimer = setTimeout(function () {
            if (map.getZoom() < 14) { return; }
            var b = map.getBounds();
            var url = actionUrl + '?odp=1&s=' + b.getSouth().toFixed(5) + '&n=' + b.getNorth().toFixed(5) +
                      '&w=' + b.getWest().toFixed(5) + '&e=' + b.getEast().toFixed(5);
            if (odpCtl && odpCtl.abort) { odpCtl.abort(); }
            odpCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            fetch(url, { credentials: 'same-origin', signal: odpCtl ? odpCtl.signal : undefined })
                .then(function (r) { return r.ok ? r.json() : { odp: [] }; })
                .then(function (data) {
                    var changed = false;
                    (data.odp || []).forEach(function (o) {
                        if (!odpCache[o.id]) { odpCache[o.id] = o; drawOdp(o); changed = true; }
                    });
                    if (changed || hasPoint()) { renderInfo(); }
                })
                .catch(function () {});
        }, 300);
    }
    map.on('moveend', loadOdp);

    /* ---------- Cari alamat (Nominatim / OpenStreetMap) ---------- */
    function geocode(q) {
        var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=id&accept-language=id&q=' + encodeURIComponent(q);
        return fetch(url).then(function (r) { return r.json(); }).then(function (rows) {
            return rows && rows.length ? { lat: parseFloat(rows[0].lat), lng: parseFloat(rows[0].lon) } : null;
        });
    }
    function say(msg, cls) { infoEl.className = 'cv-status' + (cls ? ' ' + cls : ''); infoEl.textContent = msg; }

    function searchAddress(done) {
        var q = addrEl.value.trim();
        if (q.length < 4) { say('Isi alamat terlebih dahulu, lalu cari di peta.', 'far'); return; }
        searchBtn.disabled = true;
        say('Mencari alamat di peta...');
        geocode(q).then(function (p) {
            if (!p) {
                /* coba lagi dengan bagian akhir alamat (kelurahan/kota) */
                var parts = q.split(',');
                if (parts.length > 2) { return geocode(parts.slice(-3).join(',')); }
            }
            return p;
        }).then(function (p) {
            searchBtn.disabled = false;
            if (!p) { say('Alamat tidak ditemukan di peta. Ketuk langsung lokasi rumah Anda pada peta.', 'far'); return; }
            setPoint(p.lat, p.lng, 18);
            var note = document.createElement('div');
            note.textContent = 'Titik diperkirakan dari alamat. Geser pin tepat ke rumah Anda sebelum mengecek.';
            infoEl.appendChild(note);
            if (done) { done(); }
        }).catch(function () {
            searchBtn.disabled = false;
            say('Pencarian alamat gagal. Ketuk langsung lokasi rumah Anda pada peta.', 'far');
        });
    }
    searchBtn.addEventListener('click', function () { searchAddress(); });

    /* ---------- Lokasi saya (GPS) ---------- */
    if (navigator.geolocation) {
        gpsBtn.hidden = false;
        gpsBtn.addEventListener('click', function () {
            gpsBtn.disabled = true;
            say('Mengambil lokasi Anda...');
            navigator.geolocation.getCurrentPosition(function (pos) {
                gpsBtn.disabled = false;
                setPoint(pos.coords.latitude, pos.coords.longitude, 19);
                if (addrEl.value.trim() === '') {
                    var u = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=id&lat=' +
                            pos.coords.latitude + '&lon=' + pos.coords.longitude;
                    fetch(u).then(function (r) { return r.json(); }).then(function (d) {
                        if (d && d.display_name && addrEl.value.trim() === '') { addrEl.value = d.display_name; }
                    }).catch(function () {});
                }
            }, function () {
                gpsBtn.disabled = false;
                say('Lokasi tidak dapat diambil. Izinkan akses lokasi di browser atau ketuk peta secara manual.', 'far');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        });
    }

    /* ---------- Submit ---------- */
    var submitLabel = submitBtn.textContent;
    form.addEventListener('submit', function (ev) {
        if (addrEl.value.trim().length < 10) {
            ev.preventDefault();
            say('Masukkan alamat lengkap minimal 10 karakter.', 'far');
            addrEl.focus();
            return;
        }
        if (!hasPoint()) {
            ev.preventDefault();
            searchAddress();   // tempatkan pin dari alamat, lalu minta pengguna memeriksanya
            return;
        }
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memeriksa...';
    });
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { submitBtn.disabled = false; submitBtn.textContent = submitLabel; }
    });

    /* ---------- Inisialisasi ---------- */
    if (hasPoint()) {
        setPoint(parseFloat(latEl.value), parseFloat(lngEl.value), 18);
    } else {
        loadOdp();
        if (form.dataset.auto === '1') { searchAddress(); }
    }
    setTimeout(function () { map.invalidateSize(); }, 200);
})();
</script>

</body>
</html>