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
    return $length === false ? 0 : $length;
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
$old = ['nama' => '', 'telepon' => '', 'email' => '', 'catatan' => ''];
$request = null;

if (!$tersedia && !empty($_SESSION['coverage_request_id'])) {
    $requestId = (int) $_SESSION['coverage_request_id'];
    $stmt = $conn->prepare('SELECT * FROM coverage_expansion_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($request && $request['status'] === 'tersedia') {
        $_SESSION['flow']['tersedia'] = true;
        $flow['tersedia'] = true;
        $tersedia = true;
    }
}

if (
    !$tersedia
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['aksi'] ?? '') === 'ajukan'
) {
    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string) ($_POST[$key] ?? ''));
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
        if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }
        if (cov_len($old['catatan']) > 500) {
            $errors[] = 'Catatan maksimal 500 karakter.';
        }
    }

    if (!$errors) {
        try {
            $tel = (string) $telBersih;
            $stmt = $conn->prepare("
                SELECT id
                FROM coverage_expansion_requests
                WHERE telepon = ? AND alamat = ?
                  AND status IN ('menunggu', 'evaluasi', 'survei', 'disetujui')
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->bind_param('ss', $tel, $alamat);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $_SESSION['coverage_request_id'] = (int) $existing['id'];
            } else {
                $number = 'REQ' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
                $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $name = $old['nama'];
                $email = $old['email'] !== '' ? $old['email'] : null;
                $note = $old['catatan'] !== '' ? $old['catatan'] : null;
                $stmt = $conn->prepare("
                    INSERT INTO coverage_expansion_requests
                    (nomor_pengajuan, user_id, nama, telepon, email, alamat, latitude, longitude, catatan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('sissssdds', $number, $userId, $name, $tel, $email, $alamat, $lat, $lng, $note);
                $stmt->execute();
                $_SESSION['coverage_request_id'] = (int) $conn->insert_id;
                $stmt->close();
            }

            header('Location: ' . COV_SELF_URL);
            exit;
        } catch (Throwable $e) {
            error_log('Coverage expansion request failed: ' . $e->getMessage());
            $errors[] = 'Permintaan belum dapat disimpan. Silakan coba lagi nanti.';
        }
    }
}

if (!$tersedia && !empty($_SESSION['coverage_request_id'])) {
    $requestId = (int) $_SESSION['coverage_request_id'];
    $stmt = $conn->prepare('SELECT * FROM coverage_expansion_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$pageTitle = $tersedia ? 'Jaringan Tersedia' : 'Jaringan Belum Tersedia';
$requestStatusLabels = [
    'menunggu' => 'Menunggu evaluasi admin',
    'evaluasi' => 'Sedang dievaluasi admin',
    'survei' => 'Menunggu survei teknisi',
    'disetujui' => 'Disetujui; menunggu jaringan tersedia',
    'ditolak' => 'Permintaan ditolak',
    'tersedia' => 'Jaringan sudah tersedia',
    'selesai' => 'Selesai',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($request && in_array($request['status'], ['menunggu', 'evaluasi', 'survei', 'disetujui'], true)): ?>
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

        <?php if ($tersedia): ?>

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
            <h2>Permintaan perluasan jaringan</h2>
            <p class="muted addr"><?= cov_h($alamat) ?></p>
            <span class="nomor"><?= cov_h($request['nomor_pengajuan']) ?></span>
            <p class="chip"><?= cov_h($requestStatusLabels[$request['status']] ?? $request['status']) ?></p>

            <ol class="steps">
                <li>1. Evaluasi admin: <?= in_array($request['status'], ['evaluasi', 'survei', 'disetujui', 'ditolak', 'tersedia'], true) ? 'selesai' : 'menunggu' ?>.</li>
                <li>2. Survei teknisi: <?= in_array($request['status'], ['disetujui', 'ditolak', 'tersedia'], true) ? 'selesai' : ($request['status'] === 'survei' ? 'sedang diproses' : 'menunggu') ?>.</li>
                <li>3. Keputusan: <?= cov_h($requestStatusLabels[$request['status']] ?? $request['status']) ?>.</li>
                <?php if ($request['status'] === 'ditolak' && !empty($request['catatan_teknisi'])): ?>
                    <li>Catatan survei: <?= cov_h($request['catatan_teknisi']) ?></li>
                <?php endif; ?>
                <?php if (!empty($request['catatan_admin'])): ?>
                    <li>Catatan admin: <?= cov_h($request['catatan_admin']) ?></li>
                <?php endif; ?>
                <?php if ($request['status'] === 'disetujui'): ?>
                    <li>Jaringan belum ditandai tersedia; paket dapat dipilih setelah pembangunan selesai.</li>
                <?php endif; ?>
            </ol>

            <div class="actions">
                <?php if ($request['status'] === 'tersedia'): ?>
                    <a class="btn btn-primary" href="<?= cov_h(COV_PAKET_URL) ?>">Pilih Paket</a>
                <?php endif; ?>
                <a class="btn btn-primary" href="<?= cov_h(COV_FORM_URL) ?>">Cek alamat lain</a>
            </div>

        <?php else: ?>

            <svg class="state warn" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M26 15v14M26 36v1"/></svg>
            <h2>Jaringan belum tersedia di lokasi Anda</h2>
            <p class="muted addr"><?= cov_h($alamat) ?></p>
            <p style="margin-top:12px">
                Ajukan perluasan jaringan. Alamat dan titik lokasi Anda akan dievaluasi admin
                dan disurvei teknisi. Setelah jaringan tersedia, Anda dapat memilih paket.
            </p>

            <form class="req-form" id="reqForm" method="post" action="<?= cov_h(COV_SELF_URL) ?>">
                <h3>Ajukan Perluasan Jaringan</h3>

                <?php if ($errors): ?>
                    <div class="alert err" role="alert">
                        <?php foreach ($errors as $m): ?>
                            <div><?= cov_h($m) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="aksi" value="ajukan">
                <input type="hidden" name="csrf" value="<?= cov_h(cov_csrf()) ?>">

                <div class="field">
                    <label for="nama">Nama lengkap</label>
                    <input class="in in-line" type="text" id="nama" name="nama" maxlength="100" required
                           autocomplete="name" value="<?= cov_h($old['nama']) ?>">
                </div>

                <div class="field">
                    <label for="telepon">Nomor WhatsApp</label>
                    <input class="in in-line" type="tel" inputmode="tel" id="telepon" name="telepon" maxlength="20" required
                           autocomplete="tel" placeholder="08xxxxxxxxxx" value="<?= cov_h($old['telepon']) ?>">
                    <p class="hint">Kami menghubungi nomor ini saat jaringan tersedia.</p>
                </div>

                <div class="field">
                    <label for="email">Email (opsional)</label>
                    <input class="in in-line" type="email" id="email" name="email" maxlength="120"
                           autocomplete="email" value="<?= cov_h($old['email']) ?>">
                </div>

                <div class="field">
                    <label for="catatan">Catatan (opsional)</label>
                    <textarea class="in in-area" id="catatan" name="catatan" maxlength="500"
                              placeholder="Mis. patokan lokasi atau jumlah calon pelanggan di sekitar"><?= cov_h($old['catatan']) ?></textarea>
                </div>

                <?php if ($lat !== null && $lng !== null): ?>
                    <p class="hint">Titik lokasi dari GPS ikut tersimpan bersama alamat.</p>
                <?php else: ?>
                    <p class="hint">Tanpa titik GPS, survei memakai alamat di atas. Cek ulang dengan tombol “Gunakan lokasi saya” agar lebih akurat.</p>
                <?php endif; ?>

                <button class="btn btn-primary btn-block" type="submit" id="reqSubmit">Kirim Permintaan</button>
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
    var form = document.getElementById('reqForm');
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