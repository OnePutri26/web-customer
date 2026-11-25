<?php
/*
 * ALAT SEKALI PAKAI: melengkapi kolom tabel subscription yang dibutuhkan order.
 *
 * Yang dicek/ditambahkan (hanya jika belum ada):
 *   - promo            (atau promo_code, salah satu cukup)
 *   - bulan_dibayar
 *   - bulan_bonus
 *   - masa_berlangganan_bulan
 *
 * Alat ini TIDAK mengganti nama kolom yang sudah ada.
 *
 * Cara pakai:
 *   1. Ganti $TOKEN di bawah dengan kata acak milikmu.
 *   2. Upload ke folder yang sama dengan order.php.
 *   3. Buka: https://domain-kamu/promo.php?token=KATA-ACAKMU
 *   4. Klik tombol jika ada kolom yang kurang.
 *   5. HAPUS file ini dari server setelah selesai.
 *
 * Catatan: jika sudah ada halaman promo.php milik situs, JANGAN timpa.
 * Pakai nama lain, misalnya promo_fix.php.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

$TOKEN = 'GANTI-TOKEN-INI';

if ($TOKEN === 'GANTI-TOKEN-INI') {
    http_response_code(403);
    exit('Ganti dulu nilai $TOKEN di dalam file ini, lalu upload ulang.');
}
if (!isset($_GET['token']) || !hash_equals($TOKEN, (string) $_GET['token'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

/* Hanya butuh koneksi database, tidak bergantung pada config/app.php. */
require_once __DIR__ . '/config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit('Variabel $conn (mysqli) tidak ditemukan di config/database.php.');
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$TABLE = 'subscription';

/* Kolom yang dibutuhkan: nama => definisi untuk ALTER TABLE. */
$needed = [
    'bulan_dibayar'           => 'INT NOT NULL DEFAULT 1',
    'bulan_bonus'             => 'INT NOT NULL DEFAULT 0',
    'masa_berlangganan_bulan' => 'INT NOT NULL DEFAULT 1',
];

function readColumns(mysqli $conn, string $table): array
{
    $cols = [];
    $res = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
    while ($r = $res->fetch_assoc()) {
        $cols[] = (string) $r['Field'];
    }
    $res->free();
    return $cols;
}

$msg = '';
$ok = true;

try {
    $cols = readColumns($conn, $TABLE);
} catch (Throwable $e) {
    exit('Tabel ' . h($TABLE) . ' tidak bisa dibaca: ' . h($e->getMessage()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $done = [];

    try {
        // Kolom promo: cukup salah satu dari promo / promo_code.
        if (!in_array('promo', $cols, true) && !in_array('promo_code', $cols, true)) {
            $conn->query('ALTER TABLE `' . $TABLE . '` ADD COLUMN `promo` VARCHAR(50) NULL DEFAULT NULL');
            $done[] = 'promo';
        }

        foreach ($needed as $name => $def) {
            if (!in_array($name, $cols, true)) {
                $conn->query('ALTER TABLE `' . $TABLE . '` ADD COLUMN `' . $name . '` ' . $def);
                $done[] = $name;
            }
        }

        $msg = $done
            ? 'BERHASIL menambahkan kolom: ' . implode(', ', $done) . '. Coba order lagi, lalu hapus file ini.'
            : 'Tidak ada yang perlu ditambahkan. Semua kolom sudah lengkap.';
    } catch (Throwable $e) {
        $ok = false;
        $msg = 'Gagal: ' . $e->getMessage();
    }

    $cols = readColumns($conn, $TABLE);
}

$hasPromo = in_array('promo', $cols, true) || in_array('promo_code', $cols, true);
$promoName = in_array('promo_code', $cols, true) ? 'promo_code' : (in_array('promo', $cols, true) ? 'promo' : '');

$missing = [];
if (!$hasPromo) {
    $missing[] = 'promo';
}
foreach ($needed as $name => $def) {
    if (!in_array($name, $cols, true)) {
        $missing[] = $name;
    }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Cek tabel subscription</title>
<body style="font-family:sans-serif;max-width:720px;margin:30px auto;padding:0 16px">
<h2>Cek tabel <code><?= h($TABLE) ?></code></h2>

<?php if ($msg !== ''): ?>
<p style="padding:10px;background:<?= $ok ? '#e8f7ea' : '#fee' ?>"><?= h($msg) ?></p>
<?php endif; ?>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td>Kolom promo</td>
        <td><?= $hasPromo ? '✅ ada (' . h($promoName) . ')' : '❌ belum ada' ?></td>
    </tr>
    <?php foreach ($needed as $name => $def): ?>
    <tr>
        <td><?= h($name) ?></td>
        <td><?= in_array($name, $cols, true) ? '✅ ada' : '❌ belum ada' ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php if ($missing): ?>
<form method="post">
    <button type="submit" style="margin-top:14px;padding:10px 18px">
        Tambahkan kolom yang kurang (<?= h(implode(', ', $missing)) ?>)
    </button>
</form>
<?php else: ?>
<p><b>Tabel sudah lengkap.</b> Hapus file ini dari server.</p>
<?php endif; ?>

<p style="margin-top:22px;color:#666">
    Semua kolom di tabel: <?= h(implode(', ', $cols)) ?>
</p>
</body>