<?php
/*
 * SKRIP DIAGNOSTIK SEMENTARA - HAPUS DARI SERVER SETELAH DIPAKAI.
 *
 * Fungsi (hanya membaca, tidak mengubah apa pun):
 *   1. Menampilkan kode ensureOrder() dan baris 570-600 dari config/app.php
 *   2. Menampilkan struktur kolom tabel yang berkaitan dengan order
 *      (tanpa isi data pelanggan)
 *
 * Cara pakai:
 *   - Ganti nilai $kunci di bawah dengan kata acak buatan Anda sendiri.
 *   - Buka: https://DOMAIN-ANDA/cek_order.php?key=KATA_ACAK_ANDA
 *   - Salin seluruh hasilnya ke chat, lalu HAPUS file ini dari server.
 */
$kunci = 'GANTI_DENGAN_KATA_ACAK_ANDA';

if (
    $kunci === 'GANTI_DENGAN_KATA_ACAK_ANDA'
    || !hash_equals($kunci, (string) ($_GET['key'] ?? ''))
) {
    http_response_code(403);
    exit('Akses ditolak. Ubah $kunci di file ini, lalu buka dengan ?key=...');
}

header('Content-Type: text/plain; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);

function cetakBaris(string $file, int $dari, int $sampai): void
{
    $baris = @file($file, FILE_IGNORE_NEW_LINES);
    if ($baris === false) {
        echo "(file tidak dapat dibaca: $file)\n";
        return;
    }
    $sampai = min($sampai, count($baris));
    for ($i = max(1, $dari); $i <= $sampai; $i++) {
        printf("%4d | %s\n", $i, $baris[$i - 1]);
    }
}

$appFile = __DIR__ . '/config/app.php';

echo "=== 1. config/app.php baris 570-600 (lokasi error) ===\n";
cetakBaris($appFile, 570, 600);

echo "\n=== 2. Fungsi ensureOrder() ===\n";
$baris = @file($appFile, FILE_IGNORE_NEW_LINES) ?: [];
$mulai = 0;
foreach ($baris as $i => $teks) {
    if (preg_match('/function\s+ensureOrder\s*\(/', $teks)) {
        $mulai = $i + 1;
        break;
    }
}
if ($mulai > 0) {
    echo "Dimulai di baris $mulai\n";
    cetakBaris($appFile, $mulai, $mulai + 140);
} else {
    echo "ensureOrder() tidak ditemukan di config/app.php\n";
}

echo "\n=== 3. Struktur tabel terkait (tanpa data) ===\n";
try {
    require_once __DIR__ . '/config/database.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        echo "Koneksi database tidak tersedia.\n";
    } else {
        $sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND (TABLE_NAME REGEXP 'tagihan|langgan|subscr|order|customer|paket|pembayaran'
                       OR COLUMN_NAME LIKE '%promo%')
                ORDER BY TABLE_NAME, ORDINAL_POSITION";
        $hasil = $conn->query($sql);
        if (!$hasil) {
            echo 'Query gagal: ' . $conn->error . "\n";
        } else {
            $tabelSekarang = '';
            while ($r = $hasil->fetch_assoc()) {
                if ($r['TABLE_NAME'] !== $tabelSekarang) {
                    $tabelSekarang = $r['TABLE_NAME'];
                    echo "\n[$tabelSekarang]\n";
                }
                echo '  ' . $r['COLUMN_NAME'] . '  ' . $r['COLUMN_TYPE']
                    . ($r['IS_NULLABLE'] === 'YES' ? '  NULL' : '  NOT NULL') . "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

echo "\n=== SELESAI. Hapus cek_order.php dari server. ===\n";