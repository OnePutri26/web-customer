<?php
/*
 * ALAT SEKALI PAKAI: menambahkan kolom promo_code ke tabel langganan.
 * Cara pakai:
 *   1. Upload ke folder yang sama dengan order.php.
 *   2. Buka: https://domain-kamu/fix_promo_code.php?token=GANTI-TOKEN-INI
 *   3. Pilih tabel yang benar, klik tombol.
 *   4. HAPUS file ini dari server setelah selesai.
 */
require_once __DIR__ . '/config/app.php';

$TOKEN = 'GANTI-TOKEN-INI';   // ganti dengan kata acak milikmu
if (!isset($_GET['token']) || !hash_equals($TOKEN, (string) $_GET['token'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$dbRow = $conn->query('SELECT DATABASE() AS d')->fetch_assoc();
$db = $dbRow['d'];

// Semua tabel di database ini
$tables = [];
$res = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
while ($r = $res->fetch_assoc()) { $tables[] = $r['TABLE_NAME']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = (string) ($_POST['tabel'] ?? '');
    if (!in_array($t, $tables, true)) {
        $msg = 'Tabel tidak valid.';
    } else {
        try {
            $chk = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $t) . "` LIKE 'promo_code'");
            if ($chk && $chk->num_rows > 0) {
                $msg = "Kolom promo_code sudah ada di tabel $t. Tidak ada yang diubah.";
            } else {
                $conn->query("ALTER TABLE `" . str_replace('`', '', $t) . "` ADD COLUMN promo_code VARCHAR(50) NULL DEFAULT NULL");
                $msg = "BERHASIL: kolom promo_code ditambahkan ke tabel $t. Coba order lagi, lalu hapus file ini.";
            }
        } catch (Throwable $e) {
            $msg = 'Gagal: ' . $e->getMessage();
        }
    }
}

// Tampilkan info tiap tabel: kolomnya dan apakah sudah punya promo_code
$info = [];
foreach ($tables as $t) {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $t) . "`");
    while ($c = $res->fetch_assoc()) { $cols[] = $c['Field']; }
    $info[$t] = $cols;
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Perbaiki promo_code</title>
<body style="font-family:sans-serif;max-width:760px;margin:30px auto;padding:0 16px">
<h2>Tambah kolom promo_code</h2>
<p>Database: <b><?= h($db) ?></b></p>
<?php if ($msg): ?><p style="padding:10px;background:#eef"><?= h($msg) ?></p><?php endif; ?>
<p>Pilih tabel <b>langganan</b> (tabel yang dipakai alias <code>s</code> di app.php baris ~585):</p>
<form method="post">
<?php foreach ($info as $t => $cols): $has = in_array('promo_code', $cols, true); ?>
  <label style="display:block;margin:8px 0">
    <input type="radio" name="tabel" value="<?= h($t) ?>" required>
    <b><?= h($t) ?></b> <?= $has ? '✅ sudah ada promo_code' : '' ?><br>
    <small style="color:#666"><?= h(implode(', ', $cols)) ?></small>
  </label>
<?php endforeach; ?>
<button type="submit" style="margin-top:12px;padding:10px 18px">Tambah kolom promo_code</button>
</form>
</body>