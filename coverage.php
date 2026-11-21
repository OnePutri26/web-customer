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

$promoInput = $_GET['promo'] ?? '';
if (!is_string($promoInput)) {
    http_response_code(400);
    exit('Promo yang dipilih tidak valid.');
}
$requestedPromoCode = trim($promoInput);
if (array_key_exists('promo', $_GET) && !array_key_exists('paket', $_GET) && $requestedPromoCode !== '') {
    http_response_code(400);
    exit('Promo harus digunakan bersama pilihan paket.');
}

if ($requestedPromoCode !== '' && !array_key_exists($requestedPromoCode, subscriptionPromoPlans())) {
    http_response_code(400);
    exit('Promo yang dipilih tidak valid.');
}

if (array_key_exists('paket', $_GET)) {
    $requestedPackageId = filter_var($_GET['paket'], FILTER_VALIDATE_INT);
    if ($requestedPackageId === false || $requestedPackageId < 1) {
        http_response_code(400);
        exit('Paket yang dipilih tidak valid.');
    }
    try {
        if (!getPaket($conn, (int) $requestedPackageId)) {
            http_response_code(404);
            exit('Paket yang dipilih tidak tersedia.');
        }
        $_SESSION['pending_coverage_package_id'] = (int) $requestedPackageId;
        if ($requestedPromoCode !== '') {
            $_SESSION['pending_coverage_promo_code'] = $requestedPromoCode;
        } else {
            unset($_SESSION['pending_coverage_promo_code']);
        }
    } catch (Throwable $e) {
        error_log('Coverage package validation failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Paket belum dapat diperiksa. Silakan coba kembali.');
    }
}

/*
|--------------------------------------------------------------------------
| KONFIGURASI SINKRONISASI TABEL ODP  <-- SESUAIKAN DI SINI
|--------------------------------------------------------------------------
|   table          : nama tabel ODP
|   id / nama      : kolom ID dan nama ODP (mis. "ODP-SDA-001")
|   lat / lng      : kolom koordinat ODP (DECIMAL/FLOAT/DOUBLE, derajat desimal)
|   status_kolom   : (opsional) kolom status; null jika tidak ada
|   status_nilai   : nilai status yang dianggap aktif
|   sisa_port_kolom: (opsional) kolom sisa port; ODP sisa 0 diabaikan; null = nonaktif
|
| PENTING: isi status_kolom / sisa_port_kolom bila ada. Selama null, ODP yang
| sudah penuh / tidak aktif tetap dianggap "tercover".
|
| COV_RADIUS_KM    : jarak maksimum rumah ke ODP agar dianggap tercover (km)
*/
if (!defined('COV_ODP')) {
    define('COV_ODP', [
        'table'            => 'odp',
        'id'               => 'id',
        'nama'             => 'odp_id',
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

/* Batas koordinat wajar untuk Indonesia (membuang data ODP/pin yang rusak, mis. 0,0 atau tertukar) */
const COV_ID_LAT_MIN = -11.5;
const COV_ID_LAT_MAX = 6.5;
const COV_ID_LNG_MIN = 94.0;
const COV_ID_LNG_MAX = 142.0;

/** Pastikan nama tabel/kolom aman sebelum dimasukkan ke SQL. */
function covIdent(mixed $name): string
{
    $name = (string) $name;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama tabel/kolom ODP tidak valid: ' . $name);
    }
    return '`' . $name . '`';
}

/** Filter ODP: koordinat wajar + (opsional) status aktif & sisa port -> [sql, types, params]. */
function covOdpFilter(): array
{
    $c = COV_ODP;
    $cLat = covIdent($c['lat']);
    $cLng = covIdent($c['lng']);
    $sql = " AND $cLat BETWEEN " . COV_ID_LAT_MIN . ' AND ' . COV_ID_LAT_MAX
         . " AND $cLng BETWEEN " . COV_ID_LNG_MIN . ' AND ' . COV_ID_LNG_MAX;
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
 * Cari ODP terdekat dari titik rumah.
 * 'tersedia' = true bila jarak <= COV_RADIUS_KM.
 * 'terdekat_*' selalu terisi bila ada ODP dalam kotak 3 km (untuk info "ODP terdekat X m").
 */
function covCekOdp(mysqli $conn, ?float $lat, ?float $lng): array
{
    $none = [
        'tersedia' => false, 'odp_id' => null, 'odp' => null, 'jarak' => null,
        'odp_lat' => null, 'odp_lng' => null, 'terdekat_odp' => null, 'terdekat_jarak' => null,
    ];
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

    /* Haversine (lebih stabil untuk jarak pendek dibanding ACOS) */
    $sql = "SELECT $cId AS odp_id, $cNama AS odp_nama, $cLat AS odp_lat, $cLng AS odp_lng,
                   (2 * 6371 * ASIN(SQRT(LEAST(1,
                        POW(SIN(RADIANS($cLat - ?) / 2), 2)
                        + COS(RADIANS(?)) * COS(RADIANS($cLat)) * POW(SIN(RADIANS($cLng - ?) / 2), 2)
                   )))) AS jarak
            FROM $t
            WHERE $cLat BETWEEN ? AND ?
              AND $cLng BETWEEN ? AND ?
              $fSql
            ORDER BY jarak ASC
            LIMIT 1";

    $params = array_merge(
        [$lat, $lat, $lng, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng],
        $fParams
    );
    $rows = covFetchAll($conn, $sql, 'ddddddd' . $fTypes, $params);

    if ($rows === []) {
        return $none;
    }

    $row = $rows[0];
    $jarak = (float) $row['jarak'];
    $nama = trim((string) ($row['odp_nama'] ?? ''));
    $label = $nama !== '' ? $nama : 'ODP #' . $row['odp_id'];

    $result = array_merge($none, ['terdekat_odp' => $label, 'terdekat_jarak' => $jarak]);
    if ($jarak > COV_RADIUS_KM) {
        return $result;
    }

    return array_merge($result, [
        'tersedia' => true,
        'odp_id'   => $row['odp_id'],
        'odp'      => $label,
        'jarak'    => $jarak,
        'odp_lat'  => (float) $row['odp_lat'],
        'odp_lng'  => (float) $row['odp_lng'],
    ]);
}

/*
|--------------------------------------------------------------------------
| Endpoint JSON: daftar ODP
|   ?odp=all          -> seluruh titik ODP (dimuat sekali oleh peta)
|   ?odp=1&s&n&w&e    -> hanya ODP di dalam kotak peta (maks. 0,2 derajat)
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['odp'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');

    if ((string) $_GET['odp'] === 'all') {
        try {
            $c = COV_ODP;
            [$fSql, $fTypes, $fParams] = covOdpFilter();
            $sql = 'SELECT ' . covIdent($c['id']) . ' AS id, ' . covIdent($c['nama']) . ' AS nama, '
                 . covIdent($c['lat']) . ' AS lat, ' . covIdent($c['lng']) . ' AS lng
                    FROM ' . covIdent($c['table']) . '
                    WHERE 1=1' . $fSql . '
                    LIMIT 5000';
            $rows = covFetchAll($conn, $sql, $fTypes, $fParams);
            $out = [];
            foreach ($rows as $r) {
                if (!is_numeric($r['lat']) || !is_numeric($r['lng'])) {
                    continue;
                }
                $out[] = [
                    'id'   => (string) $r['id'],
                    'nama' => (string) ($r['nama'] ?? ''),
                    'lat'  => (float) $r['lat'],
                    'lng'  => (float) $r['lng'],
                ];
            }
            echo json_encode(['odp' => $out], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('Coverage ODP all error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['odp' => [], 'error' => true]);
        }
        exit;
    }

    $s = filter_var($_GET['s'] ?? null, FILTER_VALIDATE_FLOAT);
    $n = filter_var($_GET['n'] ?? null, FILTER_VALIDATE_FLOAT);
    $w = filter_var($_GET['w'] ?? null, FILTER_VALIDATE_FLOAT);
    $ea = filter_var($_GET['e'] ?? null, FILTER_VALIDATE_FLOAT);

    $valid = $s !== false && $n !== false && $w !== false && $ea !== false
        && $s !== null && $n !== null && $w !== null && $ea !== null
        && $s >= -90 && $n <= 90 && $w >= -180 && $ea <= 180
        && $n > $s && $ea > $w
        && ($n - $s) <= 0.2 && ($ea - $w) <= 0.2;

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
 * Nilai kosong / tidak valid menjadi null (bukan 0.0).
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
$titikSumber = 'manual';   // search | manual | gps
$titikOk = false;          // pelanggan sudah memastikan pin tepat di rumahnya


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

    $sumberRaw = (string) ($_POST['titik_sumber'] ?? '');
    $titikSumber = in_array($sumberRaw, ['search', 'manual', 'gps'], true) ? $sumberRaw : 'manual';
    $titikOk = (string) ($_POST['titik_dikonfirmasi'] ?? '') === '1';

    if (function_exists('csrf_ok') && !csrf_ok()) {

        $error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';

    } elseif ($addressLength($alamat) < 10) {

        $error = 'Masukkan alamat lengkap minimal 10 karakter.';

    } elseif ($lat === null || $lng === null) {

        $error = 'Tandai lokasi rumah Anda di peta terlebih dahulu (cari alamat, ketuk peta, atau gunakan lokasi saya).';

    } elseif (
        $lat < COV_ID_LAT_MIN || $lat > COV_ID_LAT_MAX
        || $lng < COV_ID_LNG_MIN || $lng > COV_ID_LNG_MAX
    ) {

        $lat = null;
        $lng = null;
        $error = 'Titik yang ditandai berada di luar Indonesia. Periksa kembali alamat dan posisi pin di peta.';

    } elseif (!$titikOk) {

        $error = 'Pastikan pin sudah tepat di rumah Anda, lalu tekan Cek ketersediaan kembali.';

    } else {

        try {

            $h = covCekOdp($conn, $lat, $lng);
            unset($_SESSION['coverage_request_id']);
            $pendingPackageId = filter_var(
                $_SESSION['pending_coverage_package_id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            /*
             * Struktur session 'flow' dipertahankan, karena coverage_hasil.php,
             * paket.php, request_coverage.php dan halaman sesudahnya membacanya.
             * Kunci tambahan (aman diabaikan): odp_lat/odp_lng, terdekat_odp,
             * terdekat_jarak, radius_km, titik_sumber.
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
                'terdekat_odp'   => $h['terdekat_odp'] ?? null,
                'terdekat_jarak' => $h['terdekat_jarak'] ?? null,
                'radius_km'      => COV_RADIUS_KM,
                'titik_sumber'   => $titikSumber,
                'promo_code' => (string) ($_SESSION['pending_coverage_promo_code'] ?? ''),
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
            --ink: #071a36; --brand: #103b73; --ok: #12b76a; --warn: #f79009; --err: #d92d20;
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
        .cv-logo { display: inline-flex; align-items: center; text-decoration: none; }
        .cv-logo img { display: block; width: clamp(120px, 16vw, 150px); height: auto; max-height: 46px; padding: 4px 8px; border-radius: 8px; background: #fff; object-fit: contain; }
        .cv-back { font-size: 14px; font-weight: 600; text-decoration: none; color: #c9d7ee; padding: 8px 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,.18); transition: background .15s, color .15s; }
        .cv-back:hover { background: rgba(255,255,255,.1); color: #fff; }
        .cv-footer { margin-top: auto; padding: 28px 20px calc(28px + env(safe-area-inset-bottom, 0px)); text-align: center; color: var(--muted); font-size: 13px; }
        .cv-footer p { margin: 0; }

        .cv-main { padding: 32px 16px 48px; }
        .cv-wrap { max-width: 1440px; margin: 0 auto; }
        .cv-head { margin: 0 0 18px; }
        .cv-head h1 { margin: 0 0 6px; font-size: clamp(26px, 4.6vw, 34px); letter-spacing: -.9px; line-height: 1.15; font-weight: 800; color: var(--ink); }
        .cv-head p { margin: 0; color: #475467; max-width: 56ch; }

        .cv-card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; box-shadow: 0 1px 2px rgba(10,26,51,.04), 0 24px 48px -24px rgba(10,26,51,.28); }
        .cv-alert { border-radius: 14px; padding: 13px 16px; font-size: 14.5px; font-weight: 600; margin-bottom: 18px; background: #fef3f2; border: 1px solid #fecdca; color: #b42318; }

        .cv-label { display: block; margin-bottom: 6px; font-size: 13.5px; font-weight: 700; }
        .cv-input {
            width: 100%; font: inherit; font-size: 15px; color: var(--text); background: #fff;
            border: 1.5px solid #d3dbe8; border-radius: 12px; padding: 12px 14px; min-height: 92px; resize: vertical;
            transition: border-color .15s, box-shadow .15s;
        }
        .cv-input::placeholder { color: #98a2b3; }
        .cv-input:hover { border-color: #aebad0; }
        .cv-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(16,59,115,.15); }

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
        .cv-btn.primary { background: linear-gradient(110deg, #103b73, #14528d 72%, #bd2035); border-color: transparent; color: #fff; box-shadow: 0 10px 20px -10px rgba(16,59,115,.7); }
        .cv-btn.primary:hover { background: linear-gradient(110deg, #092c57, #103b73 72%, #a9162a); }
        .cv-btn.block { width: 100%; min-height: 52px; font-size: 16px; margin-top: 14px; }
        .cv-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Peta */
        .cv-mapbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
        .cv-mapbar .t { font-size: 13.5px; font-weight: 700; }
        .cv-seg { display: inline-flex; background: var(--paper); border: 1px solid var(--line); border-radius: 12px; padding: 3px; }
        .cv-seg button { font: inherit; font-size: 13px; font-weight: 700; border: 0; background: transparent; color: var(--muted); padding: 7px 14px; border-radius: 9px; cursor: pointer; }
        .cv-seg button[aria-pressed="true"] { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(10,26,51,.15); }
        .cv-map { height: min(66vh, 620px); min-height: 420px; border-radius: 16px; border: 1px solid #cbd5e1; overflow: hidden; isolation: isolate; background: #e8eef7; }
        .cv-map .leaflet-container { font-family: var(--font); }
        .cv-pin { filter: drop-shadow(0 4px 6px rgba(10,26,51,.4)); }

        .cv-legend { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 10px 0 0; padding: 0; list-style: none; font-size: 13.5px; color: #344054; }
        .cv-legend li { display: flex; align-items: center; gap: 7px; }
        .cv-legend i { display: inline-block; width: 14px; height: 14px; border-radius: 50%; }
        .cv-legend .l-pin { background: var(--err); }
        .cv-legend .l-odp { background: #16e34b; border: 1px solid #111827; }
        .cv-legend .l-ring { background: rgba(18,183,106,.2); border: 1.5px solid var(--ok); }

        .cv-status { margin-top: 14px; border-radius: 14px; padding: 12px 16px; background: var(--paper); border: 1px solid var(--line); font-size: 14.5px; }
        .cv-status.ok { background: #ecfdf3; border-color: #abefc6; color: #067647; }
        .cv-status.far { background: #fffaf0; border-color: #fedf89; color: #93370d; }
        .cv-status .warn { margin-top: 6px; font-weight: 700; color: #93370d; }
        .cv-status .rev { margin-top: 6px; font-size: 13px; color: var(--muted); }
        .cv-status .coords { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 6px; font-size: 12.5px; color: var(--muted); }
        .cv-status a { color: var(--brand); font-weight: 700; text-decoration: none; }
        .cv-status a:hover { text-decoration: underline; }

        .cv-tip { margin-top: 18px; display: flex; gap: 12px; padding: 14px 18px; border-radius: 14px; background: #eaf3ff; color: #134a9e; font-size: 14px; }
        .cv-tip strong { display: block; }
        .cv-tip p { margin: 2px 0 0; }

        @media (max-width: 600px) {
            .cv-main { padding: 20px 12px 36px; }
            .cv-card { padding: 18px; }
            .cv-map { height: 380px; min-height: 320px; }
            .cv-tools .cv-btn { flex: 1 1 100%; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>

<body>

<header class="cv-header">
    <div class="in">
        <a href="index.php" class="cv-logo" aria-label="YESNET beranda"><img src="logo-yesnet.png" alt="YESNET"></a>
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
                <input type="hidden" name="titik_sumber" id="titik_sumber" value="<?= e($titikSumber) ?>">
                <input type="hidden" name="titik_dikonfirmasi" id="titik_dikonfirmasi" value="<?= $titikOk ? '1' : '0' ?>">

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

                <button class="cv-btn primary block" type="submit" id="covSubmit"><?= $serviceError ? 'Coba lagi' : 'Cek ketersediaan' ?></button>

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
    var srcEl = $('titik_sumber'), okEl = $('titik_dikonfirmasi');
    var infoEl = $('covInfo'), submitBtn = $('covSubmit'), gpsBtn = $('covGps'), searchBtn = $('covSearch');
    var radiusKm = parseFloat(form.dataset.radius) || 0.25;
    var radiusM = radiusKm * 1000;
    var actionUrl = form.getAttribute('action') || 'coverage.php';
    var NOM = 'https://nominatim.openstreetmap.org';
    var MISMATCH_PRECISE_M = 1000;   // alamat ketemu persis, pin > 1 km dari situ = mencurigakan
    var MISMATCH_APPROX_M = 4000;    // alamat hanya ketemu setingkat kelurahan/kota
    var REGION_MARGIN_DEG = 0.15;    // ~17 km di luar sebaran ODP dianggap di luar area
    var GPS_BAD_ACC_M = 150;
    $('covRadiusTxt').textContent = Math.round(radiusM) + ' m';

    /* ---------- State ---------- */
    var pinSource = srcEl.value || 'manual';      // search | manual | gps
    var confirmed = okEl.value === '1';           // pelanggan sudah memastikan pin tepat
    var awaitingAck = false;                      // menunggu klik kedua sebagai konfirmasi
    var addrPoint = null;                         // hasil geocode dari alamat yang diketik
    var checkedAddr = '';                         // teks alamat yang sudah dicocokkan dengan pin
    var gpsAcc = null, revText = '', revToken = 0, revTimer = null;
    var searchToken = 0, busy = false, submitting = false;
    var marker = null, ring = null, nearLine = null, hilite = null;
    var odpList = [], odpLoaded = false, region = null;

    function addrNorm() { return addrEl.value.replace(/\s+/g, ' ').trim().toLowerCase(); }
    function hasPoint() { return latEl.value !== '' && lngEl.value !== ''; }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function hav(lat1, lng1, lat2, lng2) {
        var R = 6371000, r = Math.PI / 180;
        var dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
    }
    function fmtDist(m) { return m >= 1000 ? (m / 1000).toFixed(1).replace('.', ',') + ' km' : Math.round(m) + ' m'; }

    /* ---------- Peta & lapisan ---------- */
    var map = L.map('covMap', { zoomControl: true, maxZoom: 20, preferCanvas: true })
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

    var pinIcon = L.divIcon({
        className: 'cv-pin',
        html: '<svg width="34" height="44" viewBox="0 0 34 44" aria-hidden="true"><path d="M17 43C17 43 31 27.5 31 16.5 31 8.5 24.7 2 17 2S3 8.5 3 16.5C3 27.5 17 43 17 43Z" fill="#d92d20" stroke="#fff" stroke-width="2.5"/><path d="M10.5 18.5L17 12l6.5 6.5V25h-4v-4.5h-5V25h-4z" fill="#fff"/></svg>',
        iconSize: [34, 44], iconAnchor: [17, 42]
    });

    /* ---------- ODP: dimuat SEKALI, lalu semua perhitungan jarak di browser ---------- */
    var odpLayer = L.layerGroup().addTo(map);
    var ODP_STYLE = { radius: 6, color: '#111827', weight: 1.2, fillColor: '#16e34b', fillOpacity: 1 };

    function drawOdp(o) {
        var m = L.circleMarker([o.lat, o.lng], ODP_STYLE);
        m.bindPopup(function () {
            var b = document.createElement('strong');
            b.textContent = o.nama || ('ODP ' + o.id);
            return b;
        });
        m.addTo(odpLayer);
        o.marker = m;
    }

    function computeRegion() {
        var w = Infinity, e = -Infinity, s = Infinity, n = -Infinity;
        odpList.forEach(function (o) {
            if (o.lng < w) w = o.lng; if (o.lng > e) e = o.lng;
            if (o.lat < s) s = o.lat; if (o.lat > n) n = o.lat;
        });
        region = { w: w - REGION_MARGIN_DEG, e: e + REGION_MARGIN_DEG, s: s - REGION_MARGIN_DEG, n: n + REGION_MARGIN_DEG };
    }
    function outsideRegion(lat, lng) {
        return !!region && (lat < region.s || lat > region.n || lng < region.w || lng > region.e);
    }
    function nearest(lat, lng) {
        var best = null, bd = Infinity;
        for (var i = 0; i < odpList.length; i++) {
            var d = hav(lat, lng, odpList[i].lat, odpList[i].lng);
            if (d < bd) { bd = d; best = odpList[i]; }
        }
        return best ? { o: best, d: bd } : null;
    }

    var odpReady = fetch(actionUrl + '?odp=all', { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
        .then(function (data) {
            (data.odp || []).forEach(function (o) {
                if (isFinite(o.lat) && isFinite(o.lng)) { odpList.push(o); drawOdp(o); }
            });
            odpLoaded = odpList.length > 0;
            if (odpLoaded) {
                computeRegion();
                if (!hasPoint()) {
                    var pts = odpList.map(function (o) { return [o.lat, o.lng]; });
                    if (pts.length === 1) { map.setView(pts[0], 16); }
                    else { map.fitBounds(pts, { padding: [28, 28], maxZoom: 15 }); }
                }
            }
            renderInfo();
        })
        .catch(function () { odpLoaded = false; renderInfo(); });

    /* ---------- Info & peringatan ---------- */
    var submitLabel = submitBtn.textContent.trim();
    function updateButton() {
        submitBtn.textContent = awaitingAck ? 'Ya, pin sudah benar - cek ketersediaan' : submitLabel;
    }
    function syncHidden() {
        srcEl.value = pinSource;
        okEl.value = confirmed ? '1' : '0';
    }
    function say(msg, cls) { infoEl.className = 'cv-status' + (cls ? ' ' + cls : ''); infoEl.textContent = msg; }

    function clearOverlays() {
        if (ring) { map.removeLayer(ring); ring = null; }
        if (nearLine) { map.removeLayer(nearLine); nearLine = null; }
        if (hilite) { hilite.setStyle(ODP_STYLE); hilite = null; }
    }
    function drawOverlays(lat, lng, nr) {
        clearOverlays();
        ring = L.circle([lat, lng], { radius: radiusM, color: '#12b76a', weight: 1.5, fillColor: '#12b76a', fillOpacity: .12, interactive: false }).addTo(map);
        if (nr && nr.d <= 3000 && nr.o.marker) {
            nearLine = L.polyline([[lat, lng], [nr.o.lat, nr.o.lng]], { color: '#103b73', weight: 2, dashArray: '6 6', interactive: false }).addTo(map);
            nr.o.marker.setStyle({ radius: 9, weight: 3, color: '#103b73' });
            nr.o.marker.bringToFront();
            hilite = nr.o.marker;
        }
    }

    function renderInfo() {
        infoEl.className = 'cv-status';
        infoEl.textContent = '';
        function line(t, cls) {
            var d = document.createElement('div');
            if (cls) { d.className = cls; }
            d.textContent = t;
            infoEl.appendChild(d);
        }
        if (!hasPoint()) {
            clearOverlays();
            line('Cari alamat atau ketuk peta untuk menandai rumah Anda. Pilih tampilan Satelit agar atap rumah lebih mudah dikenali.');
            return;
        }
        var lat = parseFloat(latEl.value), lng = parseFloat(lngEl.value);
        var nr = odpLoaded ? nearest(lat, lng) : null;
        drawOverlays(lat, lng, nr);

        if (!odpLoaded) {
            line('Data ODP belum dapat ditampilkan. Hasil resmi muncul setelah Anda menekan Cek ketersediaan.');
        } else if (nr && nr.d <= radiusM) {
            infoEl.className = 'cv-status ok';
            line('ODP terdekat' + (nr.o.nama ? ' (' + nr.o.nama + ')' : '') + ' sekitar ' + fmtDist(nr.d) + '. Kemungkinan jaringan tersedia.');
        } else if (nr) {
            infoEl.className = 'cv-status far';
            line('ODP terdekat sekitar ' + fmtDist(nr.d) + ', di luar jangkauan ' + Math.round(radiusM) + ' m. Pastikan pin tepat di rumah Anda.');
        }

        /* Peringatan kecocokan alamat dan pin */
        if (pinSource === 'search' && !confirmed) {
            line(addrPoint && addrPoint.approx
                ? 'Titik ini hanya perkiraan kasar (setingkat kelurahan/kota), bukan alamat persisnya. Zoom lalu geser pin tepat ke rumah Anda.'
                : 'Titik diperkirakan dari alamat. Geser pin tepat ke rumah Anda sebelum mengecek.', 'warn');
        }
        if (pinSource === 'search' && checkedAddr !== '' && addrNorm() !== checkedAddr) {
            line('Alamat diubah setelah pin ditentukan. Tekan "Cari alamat di peta" agar pin diperbarui.', 'warn');
        }
        if (addrPoint && pinSource !== 'search' && checkedAddr === addrNorm()) {
            var dm = hav(lat, lng, addrPoint.lat, addrPoint.lng);
            if (dm > (addrPoint.approx ? MISMATCH_APPROX_M : MISMATCH_PRECISE_M)) {
                line('Pin berjarak sekitar ' + fmtDist(dm) + ' dari lokasi alamat yang Anda ketik. Pastikan alamat dan pin menunjuk rumah yang sama.', 'warn');
            }
        }
        if (outsideRegion(lat, lng)) {
            line('Lokasi ini jauh di luar area jaringan YesNet yang kami kenal. Periksa kembali alamat dan posisi pin.', 'warn');
        }
        if (pinSource === 'gps' && gpsAcc !== null && gpsAcc > GPS_BAD_ACC_M) {
            line('Akurasi GPS rendah (sekitar ' + Math.round(gpsAcc) + ' m). Geser pin ke rumah Anda.', 'warn');
        }
        if (awaitingAck) {
            line('Pastikan pin tepat di rumah Anda, lalu tekan tombol sekali lagi untuk mengecek.', 'warn');
        }
        if (revText) { line('Lokasi pin: ' + revText, 'rev'); }

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

    /* ---------- Pin ---------- */
    function scheduleReverse(lat, lng) {
        clearTimeout(revTimer);
        revText = '';
        var t = ++revToken;
        revTimer = setTimeout(function () {
            fetch(NOM + '/reverse?format=jsonv2&zoom=18&accept-language=id&lat=' + lat.toFixed(6) + '&lon=' + lng.toFixed(6))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (t !== revToken || !d || !d.display_name) { return; }
                    revText = d.display_name.split(',').slice(0, 4).join(',').trim();
                    renderInfo();
                }).catch(function () {});
        }, 900);
    }

    function setPoint(lat, lng, zoom, source, isConfirmed) {
        pinSource = source;
        confirmed = !!isConfirmed;
        awaitingAck = false;
        if (source !== 'gps') { gpsAcc = null; }
        latEl.value = lat.toFixed(6);
        lngEl.value = lng.toFixed(6);
        var ll = L.latLng(lat, lng);
        if (marker === null) {
            marker = L.marker(ll, { icon: pinIcon, draggable: true, keyboard: true, title: 'Lokasi rumah' }).addTo(map);
            marker.on('dragend', function () {
                var p = marker.getLatLng();
                setPoint(p.lat, p.lng, null, 'manual', true);
            });
        } else {
            marker.setLatLng(ll);
        }
        if (zoom) { map.setView(ll, zoom); }
        syncHidden(); updateButton();
        scheduleReverse(lat, lng);
        renderInfo();
    }

    function clearPoint() {
        latEl.value = ''; lngEl.value = '';
        if (marker) { map.removeLayer(marker); marker = null; }
        pinSource = 'manual'; confirmed = false; awaitingAck = false; revText = '';
        syncHidden(); updateButton(); renderInfo();
    }

    map.on('click', function (ev) { setPoint(ev.latlng.lat, ev.latlng.lng, null, 'manual', true); });

    /* ---------- Geocoding bertingkat (Nominatim) ---------- */
    function buildAttempts(raw) {
        var s = raw.replace(/\s+/g, ' ').trim();
        var clean = s
            .replace(/\b(rt|rw)\b\.?\s*\d+/gi, ' ')
            .replace(/\b(no|nomor)\b\.?\s*\d[\w\-\/]*/gi, ' ')
            .replace(/\b(kel|kelurahan|kec|kecamatan|kab|kabupaten|desa)\b\.?\s+/gi, '')
            .replace(/[\/]+\s*(?=,|$)/g, '')
            .replace(/\s+/g, ' ')
            .replace(/\s*,\s*(,\s*)+/g, ', ')
            .replace(/^[,\s]+|[,\s]+$/g, '');
        var parts = clean.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        var tail = parts.length > 2 ? parts.slice(-3).join(', ') : '';
        var list = [];
        var seen = {};
        function add(q, bounded, approx) {
            if (!q) { return; }
            var key = q.toLowerCase() + '|' + (bounded ? 1 : 0);
            if (seen[key]) { return; }
            seen[key] = true;
            list.push({ q: q, bounded: bounded && !!region, approx: approx });
        }
        add(s, true, false);
        add(clean, true, false);
        add(s, false, false);
        add(clean, false, false);
        add(tail, true, true);
        add(tail, false, true);
        return list.slice(0, 4);
    }

    function nominatim(q, bounded) {
        var url = NOM + '/search?format=jsonv2&limit=5&countrycodes=id&accept-language=id&q=' + encodeURIComponent(q);
        if (bounded && region) {
            url += '&viewbox=' + [region.w, region.n, region.e, region.s].join(',') + '&bounded=1';
        }
        return fetch(url).then(function (r) {
            if (!r.ok) { throw new Error('http'); }
            return r.json();
        }).then(function (rows) {
            if (!rows || !rows.length) { return null; }
            var best = rows[0];
            for (var i = 0; i < rows.length; i++) {
                if ((rows[i].place_rank || 0) >= 26) { best = rows[i]; break; }
            }
            var lat = parseFloat(best.lat), lng = parseFloat(best.lon);
            if (!isFinite(lat) || !isFinite(lng)) { return null; }
            return { lat: lat, lng: lng, rank: best.place_rank || 0, label: best.display_name || '' };
        });
    }

    /* Mencoba beberapa bentuk query (spasi 1,1 dtk sesuai batas Nominatim). Hasil kasar ditandai approx. */
    function geocodeAddress(raw) {
        return odpReady.then(function () {
            var attempts = buildAttempts(raw), i = 0;
            function next() {
                if (i >= attempts.length) { return Promise.resolve(null); }
                var a = attempts[i++];
                var wait = i > 1 ? sleep(1100) : Promise.resolve();
                return wait.then(function () { return nominatim(a.q, a.bounded); }).then(function (p) {
                    if (!p) { return next(); }
                    p.approx = a.approx || p.rank < 26;
                    return p;
                });
            }
            return next();
        });
    }

    function searchAddress() {
        var q = addrEl.value.trim();
        if (q.length < 4) { say('Isi alamat terlebih dahulu, lalu cari di peta.', 'far'); return Promise.resolve(false); }
        var token = ++searchToken;
        searchBtn.disabled = true;
        say('Mencari alamat di peta...');
        return geocodeAddress(q).then(function (p) {
            if (token !== searchToken) { return false; }
            searchBtn.disabled = false;
            checkedAddr = addrNorm();
            if (!p) {
                addrPoint = null;
                if (pinSource === 'search') { clearPoint(); }
                say('Alamat tidak ditemukan di peta. Periksa penulisan alamat, atau ketuk langsung lokasi rumah Anda pada peta.', 'far');
                return false;
            }
            addrPoint = p;
            setPoint(p.lat, p.lng, p.approx ? 16 : 18, 'search', false);
            return true;
        }).catch(function () {
            if (token === searchToken) {
                searchBtn.disabled = false;
                say('Pencarian alamat gagal. Ketuk langsung lokasi rumah Anda pada peta.', 'far');
            }
            return false;
        });
    }
    searchBtn.addEventListener('click', function () { searchAddress(); });

    var inputTimer = null;
    addrEl.addEventListener('input', function () {
        clearTimeout(inputTimer);
        inputTimer = setTimeout(function () { if (hasPoint()) { renderInfo(); } }, 400);
    });

    /* ---------- Lokasi saya (GPS) ---------- */
    if (navigator.geolocation) {
        gpsBtn.hidden = false;
        gpsBtn.addEventListener('click', function () {
            gpsBtn.disabled = true;
            say('Mengambil lokasi Anda...');
            navigator.geolocation.getCurrentPosition(function (pos) {
                gpsBtn.disabled = false;
                gpsAcc = pos.coords.accuracy || null;
                var good = gpsAcc === null || gpsAcc <= GPS_BAD_ACC_M;
                if (addrEl.value.trim() === '') { checkedAddr = ''; }
                setPoint(pos.coords.latitude, pos.coords.longitude, good ? 19 : 17, 'gps', good);
                if (addrEl.value.trim() === '') {
                    fetch(NOM + '/reverse?format=jsonv2&accept-language=id&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude)
                        .then(function (r) { return r.json(); }).then(function (d) {
                            if (d && d.display_name && addrEl.value.trim() === '') { addrEl.value = d.display_name; }
                        }).catch(function () {});
                }
            }, function () {
                gpsBtn.disabled = false;
                say('Lokasi tidak dapat diambil. Izinkan akses lokasi di browser atau ketuk peta secara manual.', 'far');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        });
    }

    /* ---------- Cocokkan alamat yang diketik dengan pin ---------- */
    /* true = boleh lanjut; false = pelanggan perlu memeriksa pin dulu */
    function recheck() {
        say('Memeriksa kecocokan alamat dan pin...');
        return geocodeAddress(addrEl.value.trim()).then(function (p) {
            checkedAddr = addrNorm();
            if (!p) { addrPoint = null; renderInfo(); return true; }   // tidak bisa diverifikasi: jangan memblokir
            addrPoint = p;
            if (pinSource === 'search') {
                setPoint(p.lat, p.lng, p.approx ? 16 : 18, 'search', false);   // alamat berubah: pindahkan pin
                return false;
            }
            var d = hav(parseFloat(latEl.value), parseFloat(lngEl.value), p.lat, p.lng);
            if (d > (p.approx ? MISMATCH_APPROX_M : MISMATCH_PRECISE_M)) {
                confirmed = false; awaitingAck = true;
                syncHidden(); updateButton(); renderInfo();
                return false;
            }
            renderInfo();
            return true;
        });
    }

    function proceed() {
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    }

    /* ---------- Submit ---------- */
    form.addEventListener('submit', function (ev) {
        if (submitting || busy) { ev.preventDefault(); return; }

        if (Array.from(addrEl.value.trim()).length < 10) {
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
        if (checkedAddr !== addrNorm()) {
            ev.preventDefault();
            busy = true; submitBtn.disabled = true; submitBtn.textContent = 'Memeriksa alamat...';
            recheck().then(function (ok) {
                busy = false; submitBtn.disabled = false; updateButton();
                if (ok) { proceed(); }
            }).catch(function () {
                busy = false; submitBtn.disabled = false; updateButton();
                checkedAddr = addrNorm();   // verifikasi gagal (jaringan): jangan memblokir
                renderInfo();
            });
            return;
        }
        if (!confirmed) {
            ev.preventDefault();
            if (!awaitingAck) {
                awaitingAck = true; updateButton(); renderInfo();
                $('covMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                confirmed = true; awaitingAck = false; syncHidden(); updateButton();
                proceed();
            }
            return;
        }
        submitting = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memeriksa...';
    });
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { submitting = false; busy = false; submitBtn.disabled = false; updateButton(); }
    });

    /* ---------- Inisialisasi ---------- */
    syncHidden();
    if (hasPoint()) {
        setPoint(parseFloat(latEl.value), parseFloat(lngEl.value), 18, pinSource, confirmed);
    } else if (form.dataset.auto === '1') {
        searchAddress();
    }
    setTimeout(function () { map.invalidateSize(); }, 200);
})();
</script>

</body>
</html>