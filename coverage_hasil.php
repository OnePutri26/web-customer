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

function cov_asset(string $name): string
{
    $path = __DIR__ . '/assets/css/' . $name;
    return is_file($path)
        ? 'assets/css/' . $name . '?v=' . (string) filemtime($path)
        : 'assets/css/' . $name;
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
            $stmt = $conn->prepare("
                SELECT id
                FROM coverage_expansion_requests
                WHERE telepon = ? AND alamat = ? AND status <> 'ditolak'
                ORDER BY id DESC LIMIT 1
            ");
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
                $name = $old['nama'];
                $stmt = $conn->prepare("
                    INSERT INTO coverage_expansion_requests
                    (nomor_pengajuan, user_id, nama, telepon, alamat, kelurahan, kecamatan,
                     patokan, latitude, longitude, maps_url, catatan, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu')
                ");
                $stmt->bind_param(
                    'sissssssddss',
                    $number,
                    $userId,
                    $name,
                    $tel,
                    $address,
                    $neighborhood,
                    $district,
                    $landmark,
                    $latitude,
                    $longitude,
                    $mapUrl,
                    $note
                );
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
            error_log('Coverage expansion request failed: ' . $e->getMessage());
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($request && empty($request['network_available']) && $request['status'] !== 'ditolak'): ?>
        <meta http-equiv="refresh" content="30">
    <?php endif; ?>
    <title><?= cov_h($pageTitle) ?> | YesNet</title>

    <link rel="stylesheet" href="<?= cov_h(cov_asset('coverage.css')) ?>">

    <style>
        .center { text-align: center; }
        .state { width: 64px; height: 64px; margin: 0 auto 14px; display: block; fill: none; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
        .state circle { stroke: currentColor; }
        .state path { stroke: currentColor; }
        .state.ok { color: #12b76a; }
        .state.warn { color: #f79009; }
        .result h2 { margin: 0 0 8px; font-size: 24px; letter-spacing: -.5px; }
        .result .addr { margin: 8px 0 0; }
        .chip { display: inline-block; margin-top: 14px; padding: 6px 12px; border-radius: 999px; background: #eaf3ff; color: #1677ff; font-size: 13px; font-weight: 700; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 24px; }
        .actions .btn { flex: 1 1 180px; }
        .alert.ok { background: #ecfdf3; border: 1px solid #abefc6; color: #067647; }
        .alert div + div { margin-top: 4px; }
        .req-form { text-align: left; margin-top: 24px; border-top: 1px solid #e7edf5; padding-top: 22px; }
        .req-form h3 { margin: 0 0 14px; font-size: 17px; }
        .field { margin-bottom: 14px; }
        .field label { margin-bottom: 7px; }
        .in-line { min-height: 48px; height: 48px; resize: none; padding-top: 0; padding-bottom: 0; }
        textarea.in-area { min-height: 90px; height: auto; padding-top: 12px; padding-bottom: 12px; }
        .hint { margin: 6px 0 0; color: #667085; font-size: 12px; }
        .steps { margin: 18px 0 0; padding: 0; list-style: none; text-align: left; color: #475467; font-size: 14px; line-height: 1.6; }
        .steps li { padding-left: 4px; }
        .nomor { display: inline-block; margin: 10px 0 0; padding: 8px 14px; border-radius: 10px; background: #f2f4f7; font-weight: 800; letter-spacing: .5px; }
        .request-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
        .request-grid .field-wide { grid-column: 1 / -1; }
        .field input { width: 100%; }
        .service-error { text-align: center; }
        @media (max-width: 600px) { .request-grid { grid-template-columns: 1fr; } .request-grid .field-wide { grid-column: auto; } }
    </style>
</head>
<body>

<header class="simple-header">
    <div class="container">
        <a href="index.php" class="logo">YES<span>NET</span></a>
        <a href="<?= cov_h(COV_FORM_URL) ?>" class="back-link">← Cek alamat lain</a>
    </div>
</header>

<main class="coverage-page">
    <div class="coverage-container">

        <div class="card center result enter">

        <?php if ($serviceError && !$requestSaveError): ?>

            <svg class="state warn" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M26 15v14M26 36v1"/></svg>
            <h2>Layanan coverage sedang terganggu</h2>
            <p class="muted addr">Kami belum dapat memastikan ketersediaan jaringan atau memuat status permintaan Anda. Ini bukan hasil bahwa lokasi tidak tercover.</p>
            <div class="actions">
                <a class="btn btn-primary" href="<?= cov_h(COV_FORM_URL . '?alamat=' . rawurlencode($alamat)) ?>">Coba Lagi</a>
                <a class="btn" href="index.php">Kembali ke Beranda</a>
            </div>

        <?php elseif ($tersedia && $request === null): ?>

            <svg class="state ok" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-17"/></svg>
            <h2>Jaringan tersedia di lokasi Anda</h2>
            <p class="muted addr"><?= cov_h($alamat) ?></p>

            <?php if ($odp !== ''): ?>
                <span class="chip">
                    <?= cov_h($odp) ?><?= $jarak !== null ? ' · ' . number_format($jarak, 2, ',', '.') . ' km' : '' ?>
                </span>
            <?php endif; ?>

            <div class="actions">
                <a class="btn btn-primary" href="<?= cov_h(COV_PAKET_URL) ?>">Pilih Paket</a>
                <a class="btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
            </div>

        <?php elseif ($request !== null): ?>

            <svg class="state <?= $request['status'] === 'ditolak' ? 'warn' : 'ok' ?>" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="<?= $request['status'] === 'ditolak' ? 'M26 15v14M26 36v1' : 'M14 27l8 8 16-17' ?>"/></svg>
            <?php if ($submissionNotice !== ''): ?>
                <div class="alert ok" role="status"><?= cov_h($submissionNotice) ?></div>
            <?php endif; ?>
            <h2>Permintaan perluasan jaringan</h2>
            <p class="muted addr"><?= cov_h($alamat) ?></p>
            <span class="nomor"><?= cov_h($request['nomor_pengajuan']) ?></span>
            <p class="chip"><?= cov_h($requestStatusText) ?></p>
            <?php if ($wasJustSubmitted): ?>
                <p class="muted">Status awal saat diajukan: Menunggu.</p>
            <?php endif; ?>

            <ol class="steps">
                <li>Status permintaan saat ini: <?= cov_h($requestStatusText) ?>.</li>
                <?php if ($request['status'] === 'ditolak' && !empty($request['catatan_teknisi'])): ?>
                    <li>Catatan survei: <?= cov_h($request['catatan_teknisi']) ?></li>
                <?php endif; ?>
                <?php if (!empty($request['catatan_admin'])): ?>
                    <li>Catatan admin: <?= cov_h($request['catatan_admin']) ?></li>
                <?php endif; ?>
            </ol>

            <div class="actions">
                <?php if (!empty($request['network_available'])): ?>
                    <a class="btn btn-primary" href="<?= cov_h(COV_PAKET_URL) ?>">Pilih Paket</a>
                <?php endif; ?>
                <a class="btn btn-primary" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
            </div>

        <?php else: ?>

            <svg class="state warn" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M26 15v14M26 36v1"/></svg>
            <h2>Jaringan YesNet belum tersedia di lokasi Anda</h2>
            <p class="muted addr"><?= cov_h($alamat) ?></p>
            <p style="margin-top:12px">
                Lokasi ini belum dapat dilayani jaringan YesNet. Kirim permintaan perluasan coverage
                agar tim kami dapat mengevaluasi kebutuhan jaringan di wilayah Anda.
            </p>

            <div class="actions">
                <a class="btn btn-primary" href="#form-perluasan">Ajukan Perluasan Coverage</a>
                <a class="btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
            </div>

            <form class="req-form" id="form-perluasan" method="post" action="<?= cov_h(COV_SELF_URL) ?>">
                <h3>Formulir Permintaan Perluasan Coverage</h3>

                <?php if ($errors): ?>
                    <div class="alert err" role="alert">
                        <?php foreach ($errors as $m): ?>
                            <div><?= cov_h($m) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="aksi" value="ajukan">
                <input type="hidden" name="csrf" value="<?= cov_h(cov_csrf()) ?>">

                <div class="request-grid">
                <div class="field">
                    <label for="nama">Nama lengkap</label>
                    <input class="in in-line" type="text" id="nama" name="nama" maxlength="100" minlength="3" required
                           autocomplete="name" value="<?= cov_h($old['nama']) ?>">
                </div>

                <div class="field">
                    <label for="telepon">Nomor WhatsApp</label>
                    <input class="in in-line" type="tel" inputmode="tel" id="telepon" name="telepon" maxlength="20" required
                           autocomplete="tel" placeholder="08xxxxxxxxxx" value="<?= cov_h($old['telepon']) ?>">
                </div>

                <div class="field field-wide">
                    <label for="alamatRequest">Alamat lengkap lokasi pemasangan</label>
                    <textarea class="in in-area" id="alamatRequest" name="alamat" maxlength="1000" minlength="10" required><?= cov_h($old['alamat']) ?></textarea>
                    <p class="hint">Alamat dari pengecekan coverage sudah diisi otomatis dan dapat diperbaiki.</p>
                </div>

                <div class="field">
                    <label for="kelurahan">Kelurahan/desa</label>
                    <input class="in in-line" type="text" id="kelurahan" name="kelurahan" maxlength="150" value="<?= cov_h($old['kelurahan']) ?>">
                </div>

                <div class="field">
                    <label for="kecamatan">Kecamatan</label>
                    <input class="in in-line" type="text" id="kecamatan" name="kecamatan" maxlength="150" value="<?= cov_h($old['kecamatan']) ?>">
                </div>

                <div class="field field-wide">
                    <label for="patokan">Patokan lokasi terdekat</label>
                    <input class="in in-line" type="text" id="patokan" name="patokan" maxlength="150" placeholder="Contoh: dekat sekolah atau masjid" value="<?= cov_h($old['patokan']) ?>">
                </div>

                <div class="field">
                    <label for="latitude">Latitude (opsional)</label>
                    <input class="in in-line" type="text" inputmode="decimal" id="latitude" name="latitude" maxlength="20" placeholder="-90 sampai 90" value="<?= cov_h($old['latitude']) ?>">
                </div>

                <div class="field">
                    <label for="longitude">Longitude (opsional)</label>
                    <input class="in in-line" type="text" inputmode="decimal" id="longitude" name="longitude" maxlength="20" placeholder="-180 sampai 180" value="<?= cov_h($old['longitude']) ?>">
                </div>

                <div class="field field-wide">
                    <label for="maps_url">Tautan Google Maps (opsional)</label>
                    <input class="in in-line" type="url" id="maps_url" name="maps_url" maxlength="500" placeholder="https://maps.google.com/..." value="<?= cov_h($old['maps_url']) ?>">
                </div>

                <div class="field field-wide">
                    <label for="catatan">Catatan tambahan (opsional)</label>
                    <textarea class="in in-area" id="catatan" name="catatan" maxlength="1000"
                              placeholder="Informasi tambahan untuk tim YesNet"><?= cov_h($old['catatan']) ?></textarea>
                </div>
                </div>

                <?php if ($lat !== null && $lng !== null): ?>
                    <p class="hint">Titik lokasi dari GPS ikut tersimpan bersama alamat.</p>
                <?php else: ?>
                    <p class="hint">Koordinat atau tautan Google Maps membantu tim menemukan lokasi dengan lebih akurat.</p>
                <?php endif; ?>

                <button class="btn btn-primary btn-block" type="submit" id="reqSubmit"><?= $serviceError ? 'Coba Lagi' : 'Ajukan Perluasan Coverage' ?></button>
            </form>

            <div class="actions">
                <a class="btn" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
            </div>

        <?php endif; ?>

        </div>

    </div>
</main>

<footer class="simple-footer">
    <p>© <?= date('Y') ?> YesNet. All rights reserved.</p>
</footer>

<script>
(function () {
    var form = document.getElementById('form-perluasan');
    var btn = document.getElementById('reqSubmit');
    if (!form || !btn) { return; }
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
})();
</script>

</body>
</html>