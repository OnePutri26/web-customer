<?php
require_once __DIR__ . '/config/app.php';

const COV_FORM_URL = 'coverage.php';
const COV_PAKET_URL = 'paket.php?mode=coverage';
const COV_SELF_URL = 'coverage_hasil.php';

function cov_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cov_len(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

function cov_csrf(): string
{
    if (empty($_SESSION['cov_csrf'])) {
        $_SESSION['cov_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['cov_csrf'];
}

function cov_csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['cov_csrf'])
        && hash_equals((string) $_SESSION['cov_csrf'], (string) $_POST['csrf']);
}

/** Bagian atas kartu: radar sinyal + judul. $tone: ok | warn | wait */
function cov_hero(string $tone, string $title, string $sub): void
{
    $icons = [
        'ok'   => '<path d="M14 27l8 8 16-17"/>',
        'warn' => '<path d="M26 14v15M26 36v1"/>',
        'wait' => '<path d="M26 14v13l8 5"/>',
    ];
    ?>
    <div class="cv-hero is-<?= cov_h($tone) ?>">
        <div class="cv-radar" aria-hidden="true">
            <span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span>
            <span class="core">
                <svg viewBox="0 0 52 52"><?= $icons[$tone] ?? $icons['warn'] ?></svg>
            </span>
        </div>
        <h1><?= cov_h($title) ?></h1>
        <?php if ($sub !== ''): ?><p class="cv-sub"><?= cov_h($sub) ?></p><?php endif; ?>
    </div>
    <?php
}

$flow = isset($_SESSION['flow']) && is_array($_SESSION['flow']) ? $_SESSION['flow'] : [];
$alamat = trim((string) ($flow['alamat'] ?? ''));
if ($alamat === '') {
    redirect(COV_FORM_URL);
}

$tersedia = !empty($flow['tersedia']);
$odp = (string) ($flow['odp'] ?? '');
$jarak = isset($flow['jarak']) && is_numeric($flow['jarak']) ? (float) $flow['jarak'] : null;
$lat = isset($flow['lat']) && is_numeric($flow['lat']) && abs((float) $flow['lat']) <= 90 ? (float) $flow['lat'] : null;
$lng = isset($flow['lng']) && is_numeric($flow['lng']) && abs((float) $flow['lng']) <= 180 ? (float) $flow['lng'] : null;
if ($lat === null || $lng === null) {
    $lat = $lng = null;
}

$errors = [];
$old = [
    'nama' => trim((string) ($_SESSION['nama'] ?? '')),
    'telepon' => trim((string) ($_SESSION['telephone'] ?? '')),
    'alamat' => $alamat,
    'kelurahan' => '',
    'kecamatan' => '',
    'patokan' => '',
    'latitude' => $lat === null ? '' : (string) $lat,
    'longitude' => $lng === null ? '' : (string) $lng,
    'maps_url' => '',
    'catatan' => '',
];
$request = null;
$serviceError = false;
$requestSaveError = false;
$submissionNotice = '';
$wasJustSubmitted = false;
$isExistingRequest = false;

$loadRequest = static function (mysqli $conn, int $requestId): ?array {
    $stmt = $conn->prepare('SELECT * FROM coverage_expansion_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

if (!$tersedia && !empty($_SESSION['coverage_request_id'])) {
    try {
        $request = $loadRequest($conn, (int) $_SESSION['coverage_request_id']);
        if ($request && !empty($request['network_available'])) {
            $_SESSION['flow']['tersedia'] = true;
            $flow['tersedia'] = true;
            $tersedia = true;
        }
    } catch (Throwable $e) {
        error_log('Coverage request status lookup failed: ' . $e->getMessage());
        $serviceError = true;
    }
}

if (!empty($_SESSION['coverage_request_existing'])) {
    $isExistingRequest = true;
    unset($_SESSION['coverage_request_existing']);
}

if (!$tersedia && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['aksi'] ?? '') === 'ajukan') {
    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string) ($_POST[$key] ?? $old[$key]));
    }
    $telBersih = preg_replace('/[\s\-().]/', '', $old['telepon']);

    if (!cov_csrf_ok()) {
        $errors[] = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        if (cov_len($old['nama']) < 3 || cov_len($old['nama']) > 100) {
            $errors[] = 'Nama lengkap wajib diisi (3 sampai 100 karakter).';
        }
        if (!preg_match('/^\+?[0-9]{9,15}$/', (string) $telBersih)) {
            $errors[] = 'Nomor WhatsApp/telepon tidak valid (9 sampai 15 angka).';
        }
        if (cov_len($old['alamat']) < 10 || cov_len($old['alamat']) > 1000) {
            $errors[] = 'Alamat lengkap wajib diisi (10 sampai 1.000 karakter).';
        }
        foreach (['kelurahan' => 'Kelurahan/desa', 'kecamatan' => 'Kecamatan', 'patokan' => 'Patokan lokasi'] as $key => $label) {
            if (cov_len($old[$key]) > 150) {
                $errors[] = $label . ' maksimal 150 karakter.';
            }
        }
        if (cov_len($old['catatan']) > 1000) {
            $errors[] = 'Catatan maksimal 1.000 karakter.';
        }

        $latInput = $old['latitude'];
        $lngInput = $old['longitude'];
        if (($latInput === '') !== ($lngInput === '')) {
            $errors[] = 'Isi kedua koordinat latitude dan longitude, atau kosongkan keduanya.';
        } elseif ($latInput !== '' && (
            !is_numeric($latInput) || !is_numeric($lngInput)
            || (float) $latInput < -90 || (float) $latInput > 90
            || (float) $lngInput < -180 || (float) $lngInput > 180
        )) {
            $errors[] = 'Koordinat latitude atau longitude tidak valid.';
        }

        $mapUrl = $old['maps_url'];
        if ($mapUrl !== '') {
            $mapHost = strtolower((string) parse_url($mapUrl, PHP_URL_HOST));
            $mapPath = (string) parse_url($mapUrl, PHP_URL_PATH);
            $isGoogleMapsUrl = filter_var($mapUrl, FILTER_VALIDATE_URL)
                && strtolower((string) parse_url($mapUrl, PHP_URL_SCHEME)) === 'https'
                && (
                    $mapHost === 'maps.app.goo.gl'
                    || ($mapHost === 'goo.gl' && str_starts_with($mapPath, '/maps'))
                    || (
                        ($mapHost === 'google.com' || str_ends_with($mapHost, '.google.com'))
                        && str_contains($mapPath, '/maps')
                    )
                );
            if (!$isGoogleMapsUrl || cov_len($mapUrl) > 500) {
                $errors[] = 'Tautan lokasi harus berupa tautan Google Maps yang valid (maksimal 500 karakter).';
            }
        }
    }

    if (!$errors) {
        try {
            $tel = (string) $telBersih;
            $address = $old['alamat'];
            $neighborhood = $old['kelurahan'];
            $district = $old['kecamatan'];
            $landmark = $old['patokan'];
            $latitude = $latInput === '' ? $lat : (float) $latInput;
            $longitude = $lngInput === '' ? $lng : (float) $lngInput;
            if (($latitude === null) !== ($longitude === null)) {
                $latitude = $longitude = null;
            }
            if ($mapUrl !== '' && ($latitude === null || $longitude === null)) {
                $coordinates = null;
                if (preg_match('/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', $mapUrl, $matches)) {
                    $coordinates = [(float) $matches[1], (float) $matches[2]];
                } else {
                    $query = [];
                    parse_str((string) parse_url($mapUrl, PHP_URL_QUERY), $query);
                    $location = (string) ($query['q'] ?? $query['query'] ?? '');
                    if (preg_match('/^\s*(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)\s*$/', $location, $matches)) {
                        $coordinates = [(float) $matches[1], (float) $matches[2]];
                    }
                }
                if ($coordinates !== null && abs($coordinates[0]) <= 90 && abs($coordinates[1]) <= 180) {
                    [$latitude, $longitude] = $coordinates;
                }
            }
            $note = $old['catatan'] !== '' ? $old['catatan'] : null;

            // Periksa struktur tabel agar kolom opsional yang belum tersedia tidak menggagalkan pengajuan.
            $tableResult = $conn->query('SHOW COLUMNS FROM `coverage_expansion_requests`');
            $dbColumns = [];
            while ($column = $tableResult->fetch_assoc()) {
                $dbColumns[(string) $column['Field']] = true;
            }
            $tableResult->free();

            foreach (['id', 'nomor_pengajuan', 'nama', 'telepon', 'alamat', 'status'] as $requiredColumn) {
                if (!isset($dbColumns[$requiredColumn])) {
                    throw new RuntimeException('Kolom wajib coverage_expansion_requests tidak ditemukan: ' . $requiredColumn);
                }
            }

            $stmt = $conn->prepare("
                SELECT id FROM coverage_expansion_requests
                WHERE telepon = ? AND alamat = ? AND status <> 'ditolak'
                ORDER BY id DESC LIMIT 1
            " );
            $stmt->bind_param('ss', $tel, $address);
            $stmt->execute();
            $existingRequest = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingRequest) {
                $requestId = (int) $existingRequest['id'];
                $_SESSION['coverage_request_existing'] = true;
            } else {
                $number = 'REQ' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
                $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $insertData = [
                    'nomor_pengajuan' => $number,
                    'user_id' => $userId,
                    'nama' => $old['nama'],
                    'telepon' => $tel,
                    'alamat' => $address,
                    'kelurahan' => $neighborhood,
                    'kecamatan' => $district,
                    'patokan' => $landmark,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'maps_url' => $mapUrl !== '' ? $mapUrl : null,
                    'catatan' => $note,
                    'status' => 'menunggu',
                ];
                $typeMap = [
                    'nomor_pengajuan' => 's', 'user_id' => 'i', 'nama' => 's',
                    'telepon' => 's', 'alamat' => 's', 'kelurahan' => 's',
                    'kecamatan' => 's', 'patokan' => 's', 'latitude' => 'd',
                    'longitude' => 'd', 'maps_url' => 's', 'catatan' => 's', 'status' => 's',
                ];
                foreach (array_keys($insertData) as $columnName) {
                    if (!isset($dbColumns[$columnName])) {
                        unset($insertData[$columnName]);
                    }
                }
                $insertColumns = array_keys($insertData);
                $quotedColumns = array_map(static fn($columnName) => '`' . $columnName . '`', $insertColumns);
                $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
                $sql = 'INSERT INTO `coverage_expansion_requests` (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')';
                $stmt = $conn->prepare($sql);
                $types = '';
                $bindValues = [];
                foreach ($insertData as $columnName => $value) {
                    $types .= $typeMap[$columnName];
                    $bindValues[] = $value;
                }
                $bindArgs = [$types];
                foreach ($bindValues as &$bindValue) {
                    $bindArgs[] = &$bindValue;
                }
                unset($bindValue);
                call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                $stmt->execute();
                $requestId = (int) $conn->insert_id;
                $stmt->close();
                if ($requestId <= 0) {
                    throw new RuntimeException('Coverage request insert returned no ID.');
                }
                $_SESSION['coverage_request_submitted'] = true;
            }

            $_SESSION['coverage_request_id'] = $requestId;
            header('Location: ' . COV_SELF_URL, true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Coverage expansion request failed [' . get_class($e) . ']: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $serviceError = true;
            $requestSaveError = true;
            $errors[] = 'Permintaan belum dapat disimpan karena layanan sedang mengalami gangguan. Silakan coba lagi.';
        }
    }
}

if (!$tersedia && !empty($_SESSION['coverage_request_id']) && $request === null && !$serviceError) {
    try {
        $request = $loadRequest($conn, (int) $_SESSION['coverage_request_id']);
    } catch (Throwable $e) {
        error_log('Coverage request status lookup failed: ' . $e->getMessage());
        $serviceError = true;
    }
}

if ($request !== null && !empty($_SESSION['coverage_request_submitted'])) {
    $submissionNotice = 'Permintaan Anda berhasil disimpan.';
    $wasJustSubmitted = true;
    unset($_SESSION['coverage_request_submitted']);
} elseif ($request !== null && $isExistingRequest) {
    $submissionNotice = 'Permintaan dengan alamat dan nomor WhatsApp tersebut sudah tercatat sebelumnya.';
}

$pendingPackageId = filter_var(
    $_SESSION['pending_coverage_package_id'] ?? 0,
    FILTER_VALIDATE_INT
);
if ($tersedia && $pendingPackageId > 0) {
    $_SESSION['flow']['paket_id'] = $pendingPackageId;
    unset($_SESSION['pending_coverage_package_id']);
    redirect('detail_paket.php?id=' . $pendingPackageId);
}

$pageTitle = $serviceError ? 'Layanan Coverage Terganggu' : ($tersedia ? 'Jaringan Tersedia' : 'Jaringan Belum Tersedia');
$requestStatusLabels = [
    'menunggu' => 'Menunggu evaluasi admin',
    'perlu_survei' => 'Perlu survei',
    'dalam_perencanaan' => 'Dalam perencanaan',
    'terjadwal' => 'Terjadwal',
    'evaluasi' => 'Sedang dievaluasi admin',
    'survei' => 'Menunggu survei teknisi',
    'disetujui' => 'Disetujui; menunggu jaringan tersedia',
    'ditolak' => 'Permintaan ditolak',
    'tersedia' => 'Jaringan sudah tersedia',
    'selesai' => 'Selesai',
];
$requestStatusText = $request === null
    ? ''
    : ($requestStatusLabels[$request['status']] ?? (string) $request['status']);
if ($request !== null && $request['status'] === 'disetujui' && !empty($request['network_available'])) {
    $requestStatusText = 'Disetujui; jaringan sudah tersedia';
}

/* Tahap progres permintaan (0-3) untuk lini waktu */
$trackLabels = ['Diterima', 'Dievaluasi', 'Disetujui', 'Jaringan aktif'];
$stage = 0;
if ($request !== null) {
    $st = (string) $request['status'];
    if (!empty($request['network_available']) || in_array($st, ['tersedia', 'selesai'], true)) {
        $stage = 3;
    } elseif (in_array($st, ['disetujui', 'dalam_perencanaan', 'terjadwal'], true)) {
        $stage = 2;
    } elseif (in_array($st, ['evaluasi', 'perlu_survei', 'survei'], true)) {
        $stage = 1;
    }
}
$isRejected = $request !== null && $request['status'] === 'ditolak';
$requestTone = $isRejected ? 'warn' : ($stage === 3 ? 'ok' : 'wait');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php if ($request && empty($request['network_available']) && $request['status'] !== 'ditolak'): ?>
        <meta http-equiv="refresh" content="30">
    <?php endif; ?>
    <title><?= cov_h($pageTitle) ?> | YesNet</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0a1a33;
            --ink-2: #13294b;
            --brand: #1677ff;
            --ok: #12b76a;
            --warn: #f79009;
            --err: #d92d20;
            --paper: #f3f6fb;
            --card: #ffffff;
            --line: #e3e9f2;
            --text: #1d2939;
            --muted: #667085;
            --radius: 20px;
            --font: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: var(--font);
            color: var(--text);
            background:
                radial-gradient(circle at 1px 1px, #d5dded 1px, transparent 0) 0 0 / 22px 22px,
                var(--paper);
            line-height: 1.55;
        }
        a { color: inherit; }
        :focus-visible { outline: 3px solid rgba(22,119,255,.45); outline-offset: 2px; }

        /* ---------- Header & footer ---------- */
        .cv-header { background: var(--ink); color: #fff; padding-top: env(safe-area-inset-top, 0px); }
        .cv-header .in {
            max-width: 1080px; margin: 0 auto; padding: 16px 20px;
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }
        .cv-logo { font-weight: 800; font-size: 22px; letter-spacing: -.5px; text-decoration: none; color: #fff; }
        .cv-logo span { color: #5aa2ff; }
        .cv-back {
            font-size: 14px; font-weight: 600; text-decoration: none; color: #c9d7ee;
            padding: 8px 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,.18);
            transition: background .15s, color .15s;
        }
        .cv-back:hover { background: rgba(255,255,255,.1); color: #fff; }
        .cv-footer { margin-top: auto; padding: 28px 20px calc(28px + env(safe-area-inset-bottom, 0px)); text-align: center; color: var(--muted); font-size: 13px; }
        .cv-footer p { margin: 0; }

        /* ---------- Layout ---------- */
        .cv-main { padding: 36px 16px 48px; }
        .cv-wrap { max-width: 720px; margin: 0 auto; }
        .cv-card {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--line);
            box-shadow: 0 1px 2px rgba(10,26,51,.04), 0 24px 48px -24px rgba(10,26,51,.28);
        }
        .cv-body { padding: 28px 32px 32px; }

        /* ---------- Hero + radar ---------- */
        .cv-hero {
            --tone: var(--brand);
            position: relative;
            padding: 44px 28px 40px;
            text-align: center;
            color: #fff;
            background: var(--ink);
            overflow: hidden;
            isolation: isolate;
        }
        .cv-hero.is-ok { --tone: var(--ok); }
        .cv-hero.is-warn { --tone: var(--warn); }
        .cv-hero.is-wait { --tone: #4c9aff; }
        .cv-hero::before {
            content: ""; position: absolute; inset: 0; z-index: -1;
            background: radial-gradient(60% 90% at 50% 0%, color-mix(in srgb, var(--tone) 32%, transparent), transparent 70%);
        }
        .cv-radar { position: relative; width: 112px; height: 112px; margin: 0 auto 22px; }
        .cv-radar .ring {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid var(--tone);
            opacity: 0;
        }
        .cv-radar .core {
            position: absolute; inset: 24px; border-radius: 50%;
            background: var(--tone);
            display: grid; place-items: center;
            box-shadow: 0 0 0 6px color-mix(in srgb, var(--tone) 28%, transparent);
        }
        .cv-radar svg { width: 30px; height: 30px; fill: none; stroke: #fff; stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; }
        .cv-hero.is-ok .ring, .cv-hero.is-wait .ring { animation: cv-ping 2.8s cubic-bezier(.2,.6,.3,1) infinite; }
        .cv-hero.is-ok .r2, .cv-hero.is-wait .r2 { animation-delay: .9s; }
        .cv-hero.is-ok .r3, .cv-hero.is-wait .r3 { animation-delay: 1.8s; }
        .cv-hero.is-warn .ring { opacity: .35; border-style: dashed; border-width: 1.5px; }
        .cv-hero.is-warn .r2 { inset: -14px; opacity: .22; }
        .cv-hero.is-warn .r3 { inset: -28px; opacity: .12; }
        @keyframes cv-ping {
            0% { transform: scale(.55); opacity: .9; }
            100% { transform: scale(1.9); opacity: 0; }
        }
        .cv-hero h1 {
            margin: 0; font-size: clamp(24px, 4.4vw, 32px); line-height: 1.18;
            letter-spacing: -.8px; font-weight: 800; text-wrap: balance;
        }
        .cv-sub { margin: 10px auto 0; max-width: 52ch; color: #b9c8e2; font-size: 15px; }

        /* ---------- Isi ---------- */
        .cv-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 8px; }
        .cv-meta div { background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 12px 16px; }
        .cv-meta dt { font-size: 12.5px; color: var(--muted); font-weight: 600; margin: 0; }
        .cv-meta dd { margin: 2px 0 0; font-size: 17px; font-weight: 800; letter-spacing: -.2px; }

        .cv-lead { margin: 0 0 4px; color: #475467; font-size: 15px; }

        .cv-ticket {
            display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
            border: 1.5px dashed #c4cfe2; border-radius: 16px; padding: 14px 18px; background: #fafcff;
        }
        .cv-ticket small { display: block; color: var(--muted); font-size: 12.5px; font-weight: 600; }
        .cv-ticket strong { font-size: 20px; letter-spacing: .6px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .cv-copy {
            font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer;
            padding: 9px 16px; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--ink);
            transition: background .15s, border-color .15s;
        }
        .cv-copy:hover { background: var(--paper); border-color: #c4cfe2; }

        .cv-status { margin: 22px 0 0; font-size: 16px; font-weight: 700; }
        .cv-status .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 8px; background: var(--brand); vertical-align: 1px; }
        .cv-status.ok .dot { background: var(--ok); }
        .cv-status.warn .dot { background: var(--warn); }

        /* Lini waktu: urutan nyata, jadi bernomor */
        .cv-track { list-style: none; margin: 22px 0 6px; padding: 0; display: grid; grid-template-columns: repeat(4, 1fr); position: relative; }
        .cv-track li { position: relative; text-align: center; font-size: 13px; font-weight: 600; color: var(--muted); padding-top: 42px; }
        .cv-track li::before {
            content: attr(data-n); position: absolute; top: 0; left: 50%; transform: translateX(-50%);
            width: 32px; height: 32px; border-radius: 50%; z-index: 1;
            display: grid; place-items: center; font-weight: 800; font-size: 14px;
            background: #fff; border: 2px solid var(--line); color: var(--muted);
        }
        .cv-track li::after {
            content: ""; position: absolute; top: 15px; left: calc(-50% + 16px); right: calc(50% + 16px);
            height: 2px; background: var(--line);
        }
        .cv-track li:first-child::after { display: none; }
        .cv-track li.done { color: var(--ink); }
        .cv-track li.done::before { background: var(--ok); border-color: var(--ok); color: #fff; }
        .cv-track li.done::after, .cv-track li.now::after { background: var(--ok); }
        .cv-track li.now { color: var(--ink); }
        .cv-track li.now::before { border-color: var(--brand); color: var(--brand); box-shadow: 0 0 0 5px rgba(22,119,255,.15); }

        .cv-notes { list-style: none; margin: 18px 0 0; padding: 0; display: grid; gap: 10px; }
        .cv-notes li { background: var(--paper); border-left: 4px solid var(--brand); border-radius: 10px; padding: 11px 14px; font-size: 14.5px; }
        .cv-notes li b { display: block; font-size: 12.5px; color: var(--muted); font-weight: 700; margin-bottom: 1px; }
        .cv-notes li.warn { border-left-color: var(--warn); background: #fffaf0; }

        /* ---------- Tombol ---------- */
        .cv-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
        .cv-btn {
            flex: 1 1 190px; display: inline-flex; align-items: center; justify-content: center;
            min-height: 50px; padding: 0 22px; border-radius: 14px; border: 1.5px solid var(--line);
            background: #fff; color: var(--ink); font: inherit; font-weight: 700; font-size: 15px;
            text-decoration: none; cursor: pointer; transition: transform .12s, background .15s, box-shadow .15s;
        }
        .cv-btn:hover { background: var(--paper); }
        .cv-btn:active { transform: translateY(1px); }
        .cv-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 10px 20px -10px rgba(22,119,255,.7); }
        .cv-btn.primary:hover { background: #0f67e6; }
        .cv-btn[disabled] { opacity: .65; cursor: progress; }
        .cv-btn.block { width: 100%; flex: none; margin-top: 8px; }

        /* ---------- Alert ---------- */
        .cv-alert { border-radius: 14px; padding: 13px 16px; font-size: 14.5px; font-weight: 600; margin-bottom: 18px; }
        .cv-alert.ok { background: #ecfdf3; border: 1px solid #abefc6; color: #067647; }
        .cv-alert.err { background: #fef3f2; border: 1px solid #fecdca; color: #b42318; }
        .cv-alert div + div { margin-top: 4px; }

        /* ---------- Formulir ---------- */
        .cv-form { margin-top: 30px; padding-top: 26px; border-top: 1px solid var(--line); scroll-margin-top: 20px; }
        .cv-form h2 { margin: 0 0 4px; font-size: 20px; letter-spacing: -.4px; }
        .cv-form .intro { margin: 0 0 20px; color: var(--muted); font-size: 14.5px; }
        .cv-group { margin: 22px 0 10px; font-size: 14px; font-weight: 800; color: var(--ink); }
        .cv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
        .cv-field { margin-bottom: 14px; }
        .cv-field.wide { grid-column: 1 / -1; }
        .cv-field label { display: block; margin-bottom: 6px; font-size: 13.5px; font-weight: 700; }
        .cv-field input, .cv-field textarea {
            width: 100%; font: inherit; font-size: 15px; color: var(--text);
            background: #fff; border: 1.5px solid #d3dbe8; border-radius: 12px; padding: 0 14px;
            min-height: 48px; transition: border-color .15s, box-shadow .15s;
        }
        .cv-field textarea { padding: 12px 14px; min-height: 96px; resize: vertical; }
        .cv-field input::placeholder, .cv-field textarea::placeholder { color: #98a2b3; }
        .cv-field input:hover, .cv-field textarea:hover { border-color: #aebad0; }
        .cv-field input:focus, .cv-field textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(22,119,255,.15); }
        .cv-hint { margin: 6px 0 0; color: var(--muted); font-size: 12.5px; }

        @media (max-width: 600px) {
            .cv-main { padding: 20px 12px 36px; }
            .cv-body { padding: 22px 18px 26px; }
            .cv-hero { padding: 36px 20px 32px; }
            .cv-grid { grid-template-columns: 1fr; }
            .cv-field.wide { grid-column: auto; }
            .cv-track li { font-size: 11.5px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .cv-radar .ring { animation: none !important; opacity: .3 !important; }
            .cv-radar .r2 { inset: -14px; } .cv-radar .r3 { inset: -28px; }
            * { transition: none !important; }
        }
    </style>
</head>
<body>

<header class="cv-header">
    <div class="in">
        <a href="index.php" class="cv-logo">YES<span>NET</span></a>
        <a href="<?= cov_h(COV_FORM_URL) ?>" class="cv-back">← Cek alamat lain</a>
    </div>
</header>

<main class="cv-main">
    <div class="cv-wrap">
        <div class="cv-card">

        <?php if ($serviceError && !$requestSaveError): ?>

            <?php cov_hero('warn', 'Layanan coverage sedang terganggu', 'Kami belum dapat memastikan ketersediaan jaringan atau memuat status permintaan Anda. Ini bukan hasil bahwa lokasi tidak tercover.'); ?>
            <div class="cv-body">
                <div class="cv-actions">
                    <a class="cv-btn primary" href="<?= cov_h(COV_FORM_URL . '?alamat=' . rawurlencode($alamat)) ?>">Coba lagi</a>
                    <a class="cv-btn" href="index.php">Kembali ke beranda</a>
                </div>
            </div>

        <?php elseif ($tersedia && $request === null): ?>

            <?php cov_hero('ok', 'Jaringan tersedia di lokasi Anda', $alamat); ?>
            <div class="cv-body">
                <?php if ($odp !== ''): ?>
                    <dl class="cv-meta">
                        <div><dt>Titik jaringan terdekat</dt><dd><?= cov_h($odp) ?></dd></div>
                        <?php if ($jarak !== null): ?>
                            <div><dt>Jarak dari lokasi Anda</dt><dd><?= cov_h(number_format($jarak, 2, ',', '.')) ?> km</dd></div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
                <div class="cv-actions">
                    <a class="cv-btn primary" href="<?= cov_h(COV_PAKET_URL) ?>">Pilih paket</a>
                    <a class="cv-btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
                </div>
            </div>

        <?php elseif ($request !== null): ?>

            <?php cov_hero($requestTone, 'Permintaan perluasan jaringan', $alamat); ?>
            <div class="cv-body">
                <?php if ($submissionNotice !== ''): ?>
                    <div class="cv-alert ok" role="status"><?= cov_h($submissionNotice) ?></div>
                <?php endif; ?>

                <div class="cv-ticket">
                    <div>
                        <small>Nomor pengajuan</small>
                        <strong id="reqNumber"><?= cov_h($request['nomor_pengajuan']) ?></strong>
                    </div>
                    <button type="button" class="cv-copy" id="copyNumber">Salin nomor</button>
                </div>

                <p class="cv-status <?= cov_h($requestTone) ?>"><span class="dot"></span><?= cov_h($requestStatusText) ?></p>
                <?php if ($wasJustSubmitted): ?>
                    <p class="cv-hint">Status awal saat diajukan: Menunggu.</p>
                <?php endif; ?>

                <?php if (!$isRejected): ?>
                    <ol class="cv-track" aria-label="Progres permintaan">
                        <?php foreach ($trackLabels as $i => $label): ?>
                            <li data-n="<?= $stage > $i || $stage === 3 ? '✓' : $i + 1 ?>" class="<?= $stage > $i || $stage === 3 ? 'done' : ($stage === $i ? 'now' : '') ?>"><?= cov_h($label) ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php if (($isRejected && !empty($request['catatan_teknisi'])) || !empty($request['catatan_admin'])): ?>
                    <ul class="cv-notes">
                        <?php if ($isRejected && !empty($request['catatan_teknisi'])): ?>
                            <li class="warn"><b>Catatan survei</b><?= cov_h($request['catatan_teknisi']) ?></li>
                        <?php endif; ?>
                        <?php if (!empty($request['catatan_admin'])): ?>
                            <li class="<?= $isRejected ? 'warn' : '' ?>"><b>Catatan admin</b><?= cov_h($request['catatan_admin']) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($stage < 3 && !$isRejected): ?>
                    <p class="cv-hint" style="margin-top:16px">Halaman ini diperbarui otomatis setiap 30 detik.</p>
                <?php endif; ?>

                <div class="cv-actions">
                    <?php if (!empty($request['network_available'])): ?>
                        <a class="cv-btn primary" href="<?= cov_h(COV_PAKET_URL) ?>">Pilih paket</a>
                        <a class="cv-btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
                    <?php else: ?>
                        <a class="cv-btn primary" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <?php cov_hero('warn', 'Jaringan YesNet belum tersedia di lokasi Anda', $alamat); ?>
            <div class="cv-body">
                <p class="cv-lead">
                    Lokasi ini belum dapat dilayani jaringan YesNet. Kirim permintaan perluasan coverage
                    agar tim kami dapat mengevaluasi kebutuhan jaringan di wilayah Anda.
                </p>

                <div class="cv-actions">
                    <a class="cv-btn primary" href="#form-perluasan">Ajukan perluasan coverage</a>
                    <a class="cv-btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
                </div>

                <form class="cv-form" id="form-perluasan" method="post" action="<?= cov_h(COV_SELF_URL) ?>">
                    <h2>Formulir permintaan perluasan coverage</h2>
                    <p class="intro">Isi data di bawah. Tim kami akan mengevaluasi dan memperbarui status permintaan Anda di halaman ini.</p>

                    <?php if ($errors): ?>
                        <div class="cv-alert err" role="alert">
                            <?php foreach ($errors as $m): ?>
                                <div><?= cov_h($m) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="aksi" value="ajukan">
                    <input type="hidden" name="csrf" value="<?= cov_h(cov_csrf()) ?>">

                    <div class="cv-group" style="margin-top:0">Data kontak</div>
                    <div class="cv-grid">
                        <div class="cv-field">
                            <label for="nama">Nama lengkap</label>
                            <input type="text" id="nama" name="nama" maxlength="100" minlength="3" required
                                   autocomplete="name" value="<?= cov_h($old['nama']) ?>">
                        </div>
                        <div class="cv-field">
                            <label for="telepon">Nomor WhatsApp</label>
                            <input type="tel" inputmode="tel" id="telepon" name="telepon" maxlength="20" required
                                   autocomplete="tel" placeholder="08xxxxxxxxxx" value="<?= cov_h($old['telepon']) ?>">
                        </div>
                    </div>

                    <div class="cv-group">Lokasi pemasangan</div>
                    <div class="cv-grid">
                        <div class="cv-field wide">
                            <label for="alamatRequest">Alamat lengkap</label>
                            <textarea id="alamatRequest" name="alamat" maxlength="1000" minlength="10" required><?= cov_h($old['alamat']) ?></textarea>
                            <p class="cv-hint">Alamat dari pengecekan coverage sudah diisi otomatis dan dapat diperbaiki.</p>
                        </div>
                        <div class="cv-field">
                            <label for="kelurahan">Kelurahan/desa</label>
                            <input type="text" id="kelurahan" name="kelurahan" maxlength="150" value="<?= cov_h($old['kelurahan']) ?>">
                        </div>
                        <div class="cv-field">
                            <label for="kecamatan">Kecamatan</label>
                            <input type="text" id="kecamatan" name="kecamatan" maxlength="150" value="<?= cov_h($old['kecamatan']) ?>">
                        </div>
                        <div class="cv-field wide">
                            <label for="patokan">Patokan lokasi terdekat</label>
                            <input type="text" id="patokan" name="patokan" maxlength="150" placeholder="Contoh: dekat sekolah atau masjid" value="<?= cov_h($old['patokan']) ?>">
                        </div>
                    </div>

                    <div class="cv-group">Titik peta (opsional)</div>
                    <div class="cv-grid">
                        <div class="cv-field">
                            <label for="latitude">Latitude</label>
                            <input type="text" inputmode="decimal" id="latitude" name="latitude" maxlength="20" placeholder="-90 sampai 90" value="<?= cov_h($old['latitude']) ?>">
                        </div>
                        <div class="cv-field">
                            <label for="longitude">Longitude</label>
                            <input type="text" inputmode="decimal" id="longitude" name="longitude" maxlength="20" placeholder="-180 sampai 180" value="<?= cov_h($old['longitude']) ?>">
                        </div>
                        <div class="cv-field wide">
                            <label for="maps_url">Tautan Google Maps</label>
                            <input type="url" id="maps_url" name="maps_url" maxlength="500" placeholder="https://maps.google.com/..." value="<?= cov_h($old['maps_url']) ?>">
                        </div>
                        <div class="cv-field wide">
                            <label for="catatan">Catatan tambahan</label>
                            <textarea id="catatan" name="catatan" maxlength="1000"
                                      placeholder="Informasi tambahan untuk tim YesNet"><?= cov_h($old['catatan']) ?></textarea>
                        </div>
                    </div>

                    <?php if ($lat !== null && $lng !== null): ?>
                        <p class="cv-hint">Titik lokasi dari GPS ikut tersimpan bersama alamat.</p>
                    <?php else: ?>
                        <p class="cv-hint">Koordinat atau tautan Google Maps membantu tim menemukan lokasi dengan lebih akurat.</p>
                    <?php endif; ?>

                    <button class="cv-btn primary block" type="submit" id="reqSubmit"><?= $serviceError ? 'Coba lagi' : 'Ajukan perluasan coverage' ?></button>
                </form>
            </div>

        <?php endif; ?>

        </div>
    </div>
</main>

<footer class="cv-footer">
    <p>© <?= date('Y') ?> YesNet. All rights reserved.</p>
</footer>

<script>
(function () {
    var form = document.getElementById('form-perluasan');
    var btn = document.getElementById('reqSubmit');
    if (form && btn) {
        var label = btn.textContent;
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.textContent = 'Mengirim...';
        });
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) {
                btn.disabled = false;
                btn.textContent = label;
            }
        });
    }

    var copy = document.getElementById('copyNumber');
    var num = document.getElementById('reqNumber');
    if (copy && num) {
        copy.addEventListener('click', function () {
            var text = num.textContent.trim();
            var done = function () {
                copy.textContent = 'Tersalin';
                setTimeout(function () { copy.textContent = 'Salin nomor'; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            }
        });
    }
})();
</script>

</body>
</html>