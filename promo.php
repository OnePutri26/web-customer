<?php
/*
 * ALAT SEKALI PAKAI: memastikan tabel langganan punya kolom bernama promo_code.
 *  - Jika tabel punya kolom "promo" -> diganti nama menjadi promo_code (data tetap aman).
 *  - Jika tidak ada keduanya      -> kolom promo_code ditambahkan.
 * Cara pakai:
 *   1. Ganti $TOKEN di bawah dengan kata acak milikmu, upload ke folder yang sama dengan order.php.
 *   2. Buka: https://domain-kamu/promo.php?token=KATA-ACAKMU
 *   3. Pilih tabel langganan, klik tombol.
 *   4. HAPUS file ini dari server setelah selesai.
 */
require_once __DIR__ . '/config/app.php';

$TOKEN = 'GANTI-TOKEN-INI';
if (!isset($_GET['token']) || !hash_equals($TOKEN, (string) $_GET['token'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function q($t) { return '`' . str_replace('`', '', $t) . '`'; }

$msg = '';
$db = $conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'];

$tables = [];
$res = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
while ($r = $res->fetch_assoc()) { $tables[] = $r['TABLE_NAME']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = (string) ($_POST['tabel'] ?? '');
    if (!in_array($t, $tables, true)) {
        $msg = 'Tabel tidak valid.';
    } else {
        try {
            $cols = [];
            $res = $conn->query('SHOW COLUMNS FROM ' . q($t));
            while ($c = $res->fetch_assoc()) { $cols[$c['Field']] = $c; }

            if (isset($cols['promo_code'])) {
                $msg = "Kolom promo_code sudah ada di tabel $t. Tidak ada yang diubah.";
            } elseif (isset($cols['promo'])) {
                $type = $cols['promo']['Type'];   // pakai tipe asli supaya data aman
                $conn->query('ALTER TABLE ' . q($t) . ' CHANGE `promo` `promo_code` ' . $type . ' NULL DEFAULT NULL');
                $msg = "BERHASIL: kolom promo di tabel $t diganti nama menjadi promo_code. Coba order lagi, lalu hapus file ini.";
            } else {
                $conn->query('ALTER TABLE ' . q($t) . ' ADD COLUMN promo_code VARCHAR(50) NULL DEFAULT NULL');
                $msg = "BERHASIL: kolom promo_code ditambahkan ke tabel $t. Coba order lagi, lalu hapus file ini.";
            }
        } catch (Throwable $e) {
            $msg = 'Gagal: ' . $e->getMessage();
        }
    }
}

$info = [];
foreach ($tables as $t) {
    $cols = [];
    $res = $conn->query('SHOW COLUMNS FROM ' . q($t));
    while ($c = $res->fetch_assoc()) { $cols[] = $c['Field']; }
    $info[$t] = $cols;
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Perbaiki promo_code</title>
<body style="font-family:sans-serif;max-width:760px;margin:30px auto;padding:0 16px">
<h2>Perbaiki kolom promo_code</h2>
<p>Database: <b><?= h($db) ?></b></p>
<?php if ($msg): ?><p style="padding:10px;background:#eef"><?= h($msg) ?></p><?php endif; ?>
<p>Pilih tabel <b>langganan</b> (yang dipakai alias <code>s</code> di app.php baris ~585). Tabel yang punya kolom <code>promo</code> atau <code>promo_code</code> ditandai.</p>
<form method="post">
<?php foreach ($info as $t => $cols):
    $hasCode = in_array('promo_code', $cols, true);
    $hasPromo = in_array('promo', $cols, true); ?>
  <label style="display:block;margin:8px 0">
    <input type="radio" name="tabel" value="<?= h($t) ?>" required>
    <b><?= h($t) ?></b>
    <?= $hasCode ? '✅ sudah ada promo_code' : '' ?>
    <?= $hasPromo ? '⚠️ ada kolom "promo" (akan diganti nama)' : '' ?><br>
    <small style="color:#666"><?= h(implode(', ', $cols)) ?></small>
  </label>
<?php endforeach; ?>
<button type="submit" style="margin-top:12px;padding:10px 18px">Perbaiki</button>
</form>
</body>