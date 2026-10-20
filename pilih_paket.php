<?php
/**
 * pilih_paket.php — alur tombol Pilih Paket YesNet.
 *
 * WAJIB disesuaikan dengan skema database Anda:
 * contoh query di bawah mengasumsikan tabel `langganan` dengan kolom
 * `user_id` dan `status` bernilai `aktif` untuk pelanggan aktif.
 * Jangan gunakan sebelum nama tabel/kolom tersebut cocok dengan database.
 */
$cfgFile = __DIR__ . '/config/app.php';
if (is_file($cfgFile)) require_once $cfgFile;
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('appUrl')) {
    function appUrl(string $file): string {
        foreach (['', 'customer/'] as $dir) {
            if (is_file(__DIR__ . '/' . $dir . $file)) return $dir . $file;
        }
        return $file;
    }
}

$paketId = filter_input(INPUT_GET, 'paket', FILTER_VALIDATE_INT);
$wilayah = strtolower(trim((string)($_GET['wilayah'] ?? '')));
if (!$paketId || $paketId < 1 || !in_array($wilayah, ['jakarta', 'surabaya'], true)) {
    http_response_code(400);
    exit('Paket atau wilayah tidak valid. Silakan kembali ke halaman paket YesNet.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('pilih_paket.php: koneksi mysqli $conn tidak ditemukan.');
    http_response_code(500);
    exit('Koneksi database belum tersedia. Silakan hubungi administrator.');
}

// Pastikan ID paket yang diterima benar-benar ada dan aktif.
try {
    $stmt = $conn->prepare('SELECT * FROM paket_wifi WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $paketId);
    $stmt->execute();
    $paket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('pilih_paket.php validasi paket: ' . $e->getMessage());
    http_response_code(500);
    exit('Paket belum dapat diperiksa. Silakan coba lagi.');
}
if (!$paket) {
    http_response_code(404);
    exit('Paket tidak ditemukan. Silakan pilih paket yang tersedia.');
}
foreach (['status', 'status_paket'] as $col) {
    if (isset($paket[$col]) && in_array(strtolower((string)$paket[$col]), ['nonaktif','non-aktif','tidak aktif','inactive','disabled','0'], true)) {
        http_response_code(404);
        exit('Paket ini sedang tidak tersedia.');
    }
}

// Jika user login, periksa status langganan AKTIF dari database.
$userId = $_SESSION['user_id'] ?? null;
if ($userId !== null && ctype_digit((string)$userId)) {
    try {
        // SESUAIKAN query ini dengan tabel/kolom status langganan di database YesNet.
        $stmt = $conn->prepare("SELECT 1 FROM langganan WHERE user_id = ? AND LOWER(status) IN ('aktif','active') LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $aktif = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        if ($aktif) {
            // redirect.php seharusnya mengarahkan customer aktif ke dashboard.
            header('Location: ' . appUrl('redirect.php'));
            exit;
        }
    } catch (Throwable $e) {
        // Gagal memeriksa status jangan menganggap customer aktif.
        // Catat error; customer diarahkan ke coverage untuk melanjutkan proses.
        error_log('pilih_paket.php cek langganan: ' . $e->getMessage());
    }
}

// Belum login atau belum aktif: bawa pilihan paket dan wilayah ke coverage.
$params = http_build_query(['paket' => (int)$paketId, 'wilayah' => ucfirst($wilayah)]);
header('Location: ' . appUrl('coverage.php') . '?' . $params);
exit;
