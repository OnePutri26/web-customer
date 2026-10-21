<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET - REDIRECT BERDASARKAN ROLE
|--------------------------------------------------------------------------
| File       : redirect.php
| Auth       : config/auth.php
| Database   : config/database.php
| Koneksi    : mysqli
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| 1. Pastikan sesi aktif
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| 2. Tentukan lokasi konfigurasi
|--------------------------------------------------------------------------
| Script ini mengasumsikan redirect.php berada satu tingkat dengan
| folder config.
|--------------------------------------------------------------------------
*/

$authFile = __DIR__ . '/config/auth.php';
$databaseFile = __DIR__ . '/config/database.php';

/*
|--------------------------------------------------------------------------
| 3. Validasi file auth.php
|--------------------------------------------------------------------------
*/

if (!is_file($authFile) || !is_readable($authFile)) {
    error_log(
        '[YESNET REDIRECT] auth.php tidak ditemukan atau tidak terbaca: '
        . $authFile
    );

    http_response_code(500);
    exit('File konfigurasi autentikasi tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| 4. Muat auth.php
|--------------------------------------------------------------------------
*/

try {
    require_once $authFile;
} catch (Throwable $e) {
    error_log(
        '[YESNET REDIRECT] Gagal memuat auth.php: '
        . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );

    http_response_code(500);
    exit('Gagal memuat konfigurasi autentikasi.');
}

/*
|--------------------------------------------------------------------------
| 5. Periksa fungsi autentikasi
|--------------------------------------------------------------------------
*/

$requiredFunctions = [
    'appRedirect',
    'clearAuthSession',
    'normalizeAuthRole',
    'redirectRoleHome',
];

$missingFunctions = [];

foreach ($requiredFunctions as $functionName) {
    if (!function_exists($functionName)) {
        $missingFunctions[] = $functionName;
    }
}

if ($missingFunctions !== []) {
    error_log(
        '[YESNET REDIRECT] Fungsi tidak ditemukan: '
        . implode(', ', $missingFunctions)
        . ' | Auth aktif: '
        . (realpath($authFile) ?: $authFile)
    );

    http_response_code(500);
    exit(
        'Fungsi autentikasi tidak ditemukan: '
        . implode(', ', $missingFunctions)
        . '. Periksa config/auth.php.'
    );
}

/*
|--------------------------------------------------------------------------
| 6. Validasi user_id
|--------------------------------------------------------------------------
*/

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($userId === false || $userId === null || $userId <= 0) {
    clearAuthSession();

    $_SESSION['login_error'] =
        'Sesi login tidak valid. Silakan masuk kembali.';

    appRedirect('login.php');
}

/*
|--------------------------------------------------------------------------
| 7. Validasi role
|--------------------------------------------------------------------------
*/

$role = normalizeAuthRole($_SESSION['role'] ?? '');

if ($role === '') {
    clearAuthSession();

    $_SESSION['login_error'] =
        'Role akun tidak ditemukan. Silakan masuk kembali.';

    appRedirect('login.php');
}

/*
|--------------------------------------------------------------------------
| 8. Validasi file database.php
|--------------------------------------------------------------------------
*/

if (!is_file($databaseFile) || !is_readable($databaseFile)) {
    error_log(
        '[YESNET REDIRECT] database.php tidak ditemukan atau '
        . 'tidak terbaca: ' . $databaseFile
    );

    http_response_code(500);
    exit('File konfigurasi database tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| 9. Muat koneksi database
|--------------------------------------------------------------------------
| database.php menggunakan environment variables:
| YESNET_DB_HOST, YESNET_DB_USER, YESNET_DB_PASSWORD,
| YESNET_DB_NAME, dan YESNET_DB_PORT.
|--------------------------------------------------------------------------
*/

try {
    require_once $databaseFile;
} catch (Throwable $e) {
    error_log(
        '[YESNET REDIRECT] Gagal memuat database.php: '
        . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );

    http_response_code(503);
    exit('Layanan database sedang tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| 10. Validasi koneksi mysqli
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log(
        '[YESNET REDIRECT] Koneksi mysqli $conn tidak tersedia.'
    );

    http_response_code(503);
    exit('Koneksi database tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| 11. Pastikan koneksi database aktif
|--------------------------------------------------------------------------
*/

try {
    if ($conn->connect_errno) {
        throw new RuntimeException(
            'Koneksi database tidak aktif.'
        );
    }

    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log(
        '[YESNET REDIRECT] Validasi koneksi gagal: '
        . $e->getMessage()
    );

    http_response_code(503);
    exit('Database tidak dapat digunakan saat ini.');
}

/*
|--------------------------------------------------------------------------
| 12. Redirect sesuai role
|--------------------------------------------------------------------------
| Mapping role tetap dikelola oleh config/auth.php.
|
| admin      -> admin/dashboard.php
| teknisi    -> teknisi/dashboard.php
| technician -> teknisi/dashboard.php
| customer   -> sesuai status akun, paket, tagihan, pembayaran,
|               pengajuan pemasangan, dan instalasi.
|--------------------------------------------------------------------------
*/

try {
    redirectRoleHome(
        $conn,
        $role,
        (int) $userId
    );

    /*
     * Berdasarkan auth.php yang diberikan, fungsi ini bertipe never.
     * Seharusnya eksekusi tidak pernah kembali ke bagian ini.
     */

    error_log(
        '[YESNET REDIRECT] redirectRoleHome kembali tanpa redirect. '
        . 'User ID: ' . (int) $userId
        . ' | Role: ' . $role
    );

    http_response_code(500);
    exit('Redirect tidak berhasil dijalankan.');

} catch (Throwable $e) {
    error_log(
        '[YESNET REDIRECT] Redirect gagal. '
        . 'User ID: ' . (int) $userId
        . ' | Role: ' . $role
        . ' | Error: ' . $e->getMessage()
        . ' | File: ' . $e->getFile()
        . ' | Baris: ' . $e->getLine()
    );

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit(
        'Terjadi kesalahan saat membuka dashboard. '
        . 'Silakan coba kembali.'
    );
}
