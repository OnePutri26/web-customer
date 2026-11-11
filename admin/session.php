<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET ADMIN SESSION MANAGEMENT
|--------------------------------------------------------------------------
| Session khusus aplikasi internal YESNET.
| Tidak mengubah session Customer.
|
| PENTING:
| - Jangan memanggil session_start() di tempat lain sebelum file ini.
| - Muat file ini dengan require_once paling awal di setiap halaman admin.
|--------------------------------------------------------------------------
*/

if (defined('YESNET_ADMIN_SESSION_LOADED')) {
    return;
}
define('YESNET_ADMIN_SESSION_LOADED', true);

const ADMIN_SESSION_NAME         = 'YESNET_ADMIN_SESSID';
const ADMIN_SESSION_IDLE_TIMEOUT = 7200; // detik (2 jam tanpa aktivitas)

/*
|--------------------------------------------------------------------------
| BASE PATH (dipakai untuk cookie path dan URL login)
|--------------------------------------------------------------------------
*/

function adminScriptName(): string
{
    return str_replace(
        '\\',
        '/',
        (string) ($_SERVER['SCRIPT_NAME'] ?? '')
    );
}

function adminBasePath(): string
{
    $scriptName    = adminScriptName();
    $adminPosition = stripos($scriptName, '/admin/');

    $basePath = $adminPosition === false
        ? ''
        : substr($scriptName, 0, $adminPosition);

    return rtrim($basePath, '/');
}

function adminSessionPath(): string
{
    return adminBasePath() . '/admin/';
}

function adminLoginUrl(): string
{
    return adminBasePath() . '/admin/login.php';
}

/*
|--------------------------------------------------------------------------
| DETEKSI HTTPS (termasuk di belakang reverse proxy / Cloudflare)
|--------------------------------------------------------------------------
*/

function adminIsHttps(): bool
{
    if (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/*
|--------------------------------------------------------------------------
| START SESSION
|--------------------------------------------------------------------------
*/

function adminStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {

        if (session_name() !== ADMIN_SESSION_NAME) {
            http_response_code(500);
            exit('Sesi administrator tidak dapat diinisialisasi dengan aman. '
                . 'Ada session_start() lain yang berjalan sebelum session.php.');
        }

        return;
    }

    if (session_status() !== PHP_SESSION_NONE) {
        http_response_code(500);
        exit('Sesi administrator tidak tersedia.');
    }

    if (headers_sent($file, $line)) {
        http_response_code(500);
        exit('Sesi administrator tidak dapat dimulai: output sudah dikirim '
            . "sebelum session.php ({$file}:{$line}).");
    }

    session_name(ADMIN_SESSION_NAME);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => adminSessionPath(),
        'domain'   => '',
        'secure'   => adminIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('Sesi administrator tidak dapat dimulai.');
    }

    /*
    | Timeout karena tidak ada aktivitas.
    */
    $now  = time();
    $last = $_SESSION['admin_last_activity'] ?? null;

    if (is_int($last) && ($now - $last) > ADMIN_SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $_SESSION['admin_last_activity'] = $now;

    /*
    | Halaman admin tidak boleh di-cache browser/proxy.
    */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/*
|--------------------------------------------------------------------------
| LOGIN / LOGOUT HELPER
|--------------------------------------------------------------------------
| Panggil adminLogin() dari login.php setelah password terverifikasi.
|--------------------------------------------------------------------------
*/

function adminLogin(int $userId, string $role): void
{
    adminStartSession();

    // Cegah session fixation.
    session_regenerate_id(true);

    $role = strtolower(trim($role));

    $_SESSION['user_id']             = $userId;
    $_SESSION['role']                = $role;
    $_SESSION['admin_permissions']   = adminPermissionsForRole($role);
    $_SESSION['admin_last_activity'] = time();

    unset($_SESSION['admin_csrf']);
}

function adminClearSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => ($params['samesite'] ?? '') !== ''
                    ? $params['samesite']
                    : 'Lax',
            ]
        );
    }

    session_destroy();
}

/*
|--------------------------------------------------------------------------
| ADMIN LOGIN CHECK
|--------------------------------------------------------------------------
*/

function adminRequireLogin(): void
{
    adminStartSession();

    $userId = $_SESSION['user_id'] ?? null;

    $role = strtolower(
        trim((string) ($_SESSION['role'] ?? ''))
    );

    if (
        empty($userId) ||
        !is_numeric($userId) ||
        (int) $userId <= 0 ||
        $role !== 'admin'
    ) {
        header('Location: ' . adminLoginUrl());
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
*/

function adminPermissionsForRole(string $role): array
{
    if (strtolower(trim($role)) !== 'admin') {
        return [];
    }

    return [
        'portal.access',
        'dashboard.view',
        'customers.view',
        'leads.view',
        'sales.view',
        'finance.view',
        'billing.view',
        'billing.manage',
        'installation.view',
        'technicians.view',
        'tickets.view',
        'tickets.manage',
        'network.view',
        'network.manage',
        'inventory.view',
        'warehouse.view',
        'employees.view',
        'reports.view',
        'settings.manage',
        'packages.manage',
        'complaints.manage',
        'coverage.manage',
    ];
}

/*
|--------------------------------------------------------------------------
| CHECK PERMISSION
|--------------------------------------------------------------------------
| Izin selalu dihitung dari role di session, sehingga tidak bergantung
| pada login.php yang harus mengisi $_SESSION['admin_permissions'].
|--------------------------------------------------------------------------
*/

function adminHasPermission(string $permission): bool
{
    $role = (string) ($_SESSION['role'] ?? '');

    $permissions = adminPermissionsForRole($role);

    $_SESSION['admin_permissions'] = $permissions;

    return in_array($permission, $permissions, true);
}

function adminRequirePermission(string $permission): void
{
    adminRequireLogin();

    if (!adminHasPermission($permission)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk mengakses fitur ini.');
    }
}

/*
|--------------------------------------------------------------------------
| REQUEST PERMISSION
|--------------------------------------------------------------------------
*/

function adminPermissionForRequest(): string
{
    $scriptName = strtolower(adminScriptName());

    $adminPosition = strpos($scriptName, '/admin/');

    $route = $adminPosition === false
        ? ''
        : substr($scriptName, $adminPosition + strlen('/admin/'));

    if ($route === 'dashboard.php') {
        return 'dashboard.view';
    }

    if ($route === 'overview.php') {

        return match ((string) ($_GET['section'] ?? '')) {
            'customers'   => 'customers.view',
            'packages'    => 'packages.manage',
            'invoices'    => 'billing.view',
            'payments'    => 'finance.view',
            'technicians' => 'technicians.view',
            'network'     => 'network.view',
            default       => 'portal.access',
        };
    }

    if (
        $route === 'complaints.php' ||
        $route === 'update_complaint.php'
    ) {
        return 'complaints.manage';
    }

    if ($route === 'coverage_requests.php') {
        return 'coverage.manage';
    }

    /*
    | $route tidak diawali dengan admin/.
    */

    if (
        str_starts_with($route, 'paket/') ||
        str_starts_with($route, 'packages/')
    ) {
        return 'packages.manage';
    }

    if (
        str_starts_with($route, 'tagihan/') ||
        str_starts_with($route, 'billing/')
    ) {
        return 'billing.manage';
    }

    if (
        str_starts_with($route, 'jaringan/') ||
        str_starts_with($route, 'network/')
    ) {
        return 'network.manage';
    }

    if (
        str_starts_with($route, 'pelanggan/') ||
        str_starts_with($route, 'customers/')
    ) {
        return 'customers.view';
    }

    if (str_starts_with($route, 'pengaduan/')) {
        return 'tickets.manage';
    }

    return 'portal.access';
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

function adminCsrfToken(): string
{
    if (
        !isset($_SESSION['admin_csrf']) ||
        !is_string($_SESSION['admin_csrf']) ||
        $_SESSION['admin_csrf'] === ''
    ) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function adminVerifyCsrf(mixed $token): bool
{
    $sessionToken = $_SESSION['admin_csrf'] ?? '';

    return is_string($token)
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

/*
|--------------------------------------------------------------------------
| INITIALIZE
|--------------------------------------------------------------------------
*/

adminStartSession();