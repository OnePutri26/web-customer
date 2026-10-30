<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET - REDIRECT BERDASARKAN ROLE
|--------------------------------------------------------------------------
| File     : redirect.php (satu tingkat dengan folder config/)
| Auth     : config/auth.php
| Database : config/database.php (mysqli, variabel $conn)
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Helper untuk menghentikan eksekusi dengan pesan aman bagi pengguna
 * dan detail lengkap di error log.
 */
function redirectFail(int $status, string $publicMessage, string $logMessage): never
{
    error_log('[YESNET REDIRECT] ' . $logMessage);

    if (!headers_sent()) {
        http_response_code($status);
    }

    exit($publicMessage);
}

$authFile     = __DIR__ . '/config/auth.php';
$databaseFile = __DIR__ . '/config/database.php';

/*
|--------------------------------------------------------------------------
| 1. Muat auth.php
|--------------------------------------------------------------------------
*/

if (!is_file($authFile) || !is_readable($authFile)) {
    redirectFail(
        500,
        'File konfigurasi autentikasi tidak tersedia.',
        'auth.php tidak ditemukan atau tidak terbaca: ' . $authFile
    );
}

try {
    require_once $authFile;
} catch (Throwable $e) {
    redirectFail(
        500,
        'Gagal memuat konfigurasi autentikasi.',
        'Gagal memuat auth.php: ' . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );
}

/*
|--------------------------------------------------------------------------
| 2. Pastikan auth.php yang terbaca adalah versi yang benar
|--------------------------------------------------------------------------
*/

$authRealPath = realpath($authFile) ?: $authFile;

if (!defined('YESNET_AUTH_VERSION')) {
    redirectFail(
        500,
        'File config/auth.php di server bukan versi terbaru. '
        . 'Upload ulang file tersebut.',
        'auth.php tidak memiliki YESNET_AUTH_VERSION (kemungkinan versi lama). '
        . 'Path: ' . $authRealPath
        . ' | Ukuran: ' . (string) filesize($authFile) . ' byte'
        . ' | Diubah: ' . date('Y-m-d H:i:s', (int) filemtime($authFile))
        . ' | File dimuat: ' . implode(', ', get_included_files())
    );
}

$requiredFunctions = [
    'appRedirect',
    'clearAuthSession',
    'normalizeAuthRole',
    'redirectRoleHome',
];

$missingFunctions = array_values(array_filter(
    $requiredFunctions,
    static fn (string $name): bool => !function_exists($name)
));

if ($missingFunctions !== []) {
    redirectFail(
        500,
        'Fungsi autentikasi tidak ditemukan: '
        . implode(', ', $missingFunctions)
        . '. Periksa config/auth.php.',
        'Fungsi tidak ditemukan: ' . implode(', ', $missingFunctions)
        . ' | Auth aktif: ' . $authRealPath
    );
}

/*
|--------------------------------------------------------------------------
| 3. Validasi sesi (user_id & role)
|--------------------------------------------------------------------------
*/

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

if ($userId === false || $userId === null || $userId <= 0) {
    clearAuthSession();
    $_SESSION['login_error'] = 'Sesi login tidak valid. Silakan masuk kembali.';
    appRedirect('login.php');
}

$userId = (int) $userId;
$role   = normalizeAuthRole($_SESSION['role'] ?? '');

if ($role === '') {
    clearAuthSession();
    $_SESSION['login_error'] = 'Role akun tidak ditemukan. Silakan masuk kembali.';
    appRedirect('login.php');
}

/*
|--------------------------------------------------------------------------
| 4. Muat koneksi database
|--------------------------------------------------------------------------
| database.php memakai environment variable:
| YESNET_DB_HOST, YESNET_DB_USER, YESNET_DB_PASSWORD,
| YESNET_DB_NAME, YESNET_DB_PORT.
|--------------------------------------------------------------------------
*/

if (!is_file($databaseFile) || !is_readable($databaseFile)) {
    redirectFail(
        500,
        'File konfigurasi database tidak tersedia.',
        'database.php tidak ditemukan atau tidak terbaca: ' . $databaseFile
    );
}

try {
    require_once $databaseFile;
} catch (Throwable $e) {
    redirectFail(
        503,
        'Layanan database sedang tidak tersedia.',
        'Gagal memuat database.php: ' . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    redirectFail(
        503,
        'Koneksi database tidak tersedia.',
        'Variabel $conn (mysqli) tidak tersedia setelah memuat database.php.'
    );
}

try {
    if ($conn->connect_errno) {
        throw new RuntimeException('Koneksi database tidak aktif: ' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    redirectFail(
        503,
        'Database tidak dapat digunakan saat ini.',
        'Validasi koneksi gagal: ' . $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| 5. Redirect sesuai role
|--------------------------------------------------------------------------
| admin      -> admin/dashboard.php
| teknisi    -> teknisi/dashboard.php  (technician dinormalisasi ke teknisi)
| customer   -> ditentukan oleh status akun, paket, tagihan, pembayaran,
|               pengajuan pemasangan, dan instalasi (lihat auth.php)
|--------------------------------------------------------------------------
*/

try {
    redirectRoleHome($conn, $role, $userId);
} catch (Throwable $e) {
    redirectFail(
        500,
        'Terjadi kesalahan saat membuka dashboard. Silakan coba kembali.',
        'Redirect gagal. User ID: ' . $userId
        . ' | Role: ' . $role
        . ' | Error: ' . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );
}