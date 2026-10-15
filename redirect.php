
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET - REDIRECT BERDASARKAN ROLE
|--------------------------------------------------------------------------
| File       : redirect.php
| Auth       : config/auth.php
| Database   : config/database.php
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config/auth.php';

/*
 * Pastikan sesi tersedia.
 * Tidak memulai sesi kedua jika sudah aktif.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| 1. Validasi sesi login
|--------------------------------------------------------------------------
*/
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    appRedirect('login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. Muat koneksi database
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/config/database.php';

/*
|--------------------------------------------------------------------------
| 3. Validasi koneksi database
|--------------------------------------------------------------------------
*/
if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log(
        '[YESNET] Redirect gagal: koneksi database tidak tersedia.'
    );

    http_response_code(500);
    exit('Terjadi kesalahan pada koneksi database.');
}

/*
|--------------------------------------------------------------------------
| 4. Validasi role
|--------------------------------------------------------------------------
| Jangan membuat mapping role baru di sini.
| Gunakan mapping yang sudah ditangani redirectRoleHome().
|--------------------------------------------------------------------------
*/
$role = trim((string) ($_SESSION['role'] ?? ''));

if ($role === '') {
    /*
     * Sesi tidak memiliki role.
     * Hapus sesi autentikasi agar tidak terjadi redirect berulang.
     */
    unset(
        $_SESSION['user_id'],
        $_SESSION['role']
    );

    appRedirect('login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 5. Redirect ke dashboard sesuai role
|--------------------------------------------------------------------------
*/
if (!function_exists('redirectRoleHome')) {
    error_log(
        '[YESNET] Fungsi redirectRoleHome() tidak tersedia.'
    );

    http_response_code(500);
    exit('Konfigurasi redirect belum lengkap.');
}

/*
 * Fungsi ini harus menentukan dashboard sesuai role
 * dan user_id berdasarkan implementasi asli di config/auth.php.
 */
redirectRoleHome(
    $conn,
    $role,
    $userId
);

/*
 * Pengaman jika fungsi redirectRoleHome() kembali tanpa redirect.
 * Tidak menebak dashboard berdasarkan role.
 */
exit;
