<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET - REDIRECT BERDASARKAN ROLE
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/auth.php';


/*
|--------------------------------------------------------------------------
| 2. Muat auth.php
|--------------------------------------------------------------------------
*/

$authFile = __DIR__ . '/config/auth.php';

if (!is_file($authFile) || !is_readable($authFile)) {
    error_log('[YESNET] File auth tidak ditemukan: ' . $authFile);

    http_response_code(500);
    exit('File konfigurasi autentikasi tidak ditemukan.');
}

require_once $authFile;

/*
|--------------------------------------------------------------------------
| 3. Validasi fungsi autentikasi
|--------------------------------------------------------------------------
*/

if (
    !function_exists('appRedirect') ||
    !function_exists('clearAuthSession') ||
    !function_exists('normalizeAuthRole') ||
    !function_exists('redirectRoleHome')
) {
    error_log(
        '[YESNET] Fungsi autentikasi tidak lengkap. File: '
        . (realpath($authFile) ?: $authFile)
    );

    http_response_code(500);
    exit('Konfigurasi redirect belum lengkap.');
}

/*
|--------------------------------------------------------------------------
| 4. Validasi sesi login
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
| 5. Muat database.php
|--------------------------------------------------------------------------
| File database Anda mengatur koneksi melalui environment variables.
| Jangan mendefinisikan ulang kredensial database di file ini.
|--------------------------------------------------------------------------
*/

$databaseFile = __DIR__ . '/config/database.php';

if (!is_file($databaseFile) || !is_readable($databaseFile)) {
    error_log(
        '[YESNET] File database tidak ditemukan: ' . $databaseFile
    );

    http_response_code(500);
    exit('File konfigurasi database tidak ditemukan.');
}

try {
    require_once $databaseFile;
} catch (Throwable $e) {
    error_log(
        '[YESNET] Gagal memuat database.php: '
        . $e->getMessage()
    );

    http_response_code(503);
    exit('Layanan database sedang tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| 6. Validasi koneksi mysqli
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log(
        '[YESNET] Koneksi mysqli $conn tidak tersedia setelah '
        . 'database.php dimuat.'
    );

    http_response_code(503);
    exit('Koneksi database tidak tersedia.');
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
| 8. Redirect berdasarkan role
|--------------------------------------------------------------------------
| Seluruh mapping role tetap menggunakan config/auth.php.
|
| admin      -> admin/dashboard.php
| teknisi    -> teknisi/dashboard.php
| technician -> teknisi/dashboard.php
| customer   -> mengikuti status langganan, tagihan, pembayaran,
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
     * redirectRoleHome() pada auth.php memiliki return type never.
     * Baris ini hanya pengaman jika implementasinya diubah kelak.
     */

    error_log(
        '[YESNET] redirectRoleHome kembali tanpa redirect. '
        . 'Role: ' . $role
        . ', user_id: ' . (int) $userId
    );

    http_response_code(500);
    exit('Redirect tidak berhasil dijalankan.');

} catch (Throwable $e) {
    error_log(
        '[YESNET] Kesalahan saat menentukan dashboard. '
        . 'Role: ' . $role
        . ', user_id: ' . (int) $userId
        . ', Error: ' . $e->getMessage()
        . ', File: ' . $e->getFile()
        . ', Line: ' . $e->getLine()
    );

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit(
        'Terjadi kesalahan saat membuka dashboard. '
        . 'Silakan coba kembali.'
    );
}
