<?php
declare(strict_types=1);

function adminSessionPath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminPosition = strpos($scriptName, '/admin/');
    $basePath = $adminPosition === false ? '' : substr($scriptName, 0, $adminPosition);

    return rtrim($basePath, '/') . '/admin/';
}

function adminStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== 'YESNET_ADMIN_SESSID') {
            http_response_code(500);
            exit('Sesi administrator tidak dapat diinisialisasi dengan aman.');
        }

        return;
    }

    if (session_status() !== PHP_SESSION_NONE) {
        http_response_code(500);
        exit('Sesi administrator tidak tersedia.');
    }

    session_name('YESNET_ADMIN_SESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => adminSessionPath(),
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('Sesi administrator tidak dapat dimulai.');
    }
}

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

function adminHasPermission(string $permission): bool
{
    $permissions = $_SESSION['admin_permissions'] ?? [];

    return is_array($permissions) && in_array($permission, $permissions, true);
}

function adminRequirePermission(string $permission): void
{
    if (adminHasPermission($permission)) {
        return;
    }

    http_response_code(403);
    exit('Anda tidak memiliki izin untuk mengakses fitur ini.');
}

function adminPermissionForRequest(): string
{
    $scriptName = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $adminPosition = strpos($scriptName, '/admin/');
    $route = $adminPosition === false
        ? ''
        : substr($scriptName, $adminPosition + strlen('/admin/'));

    if ($route === 'dashboard.php') {
        return 'dashboard.view';
    }

    if ($route === 'overview.php') {
        return match ((string) ($_GET['section'] ?? '')) {
            'customers' => 'customers.view',
            'packages' => 'packages.manage',
            'invoices' => 'billing.view',
            'payments' => 'finance.view',
            'technicians' => 'technicians.view',
            'network' => 'network.view',
            default => 'portal.access',
        };
    }

    if ($route === 'complaints.php' || $route === 'update_complaint.php') {
        return 'complaints.manage';
    }

    if ($route === 'coverage_requests.php') {
        return 'coverage.manage';
    }

    if (str_starts_with($route, 'admin/paket/')) {
        return 'packages.manage';
    }

    if (str_starts_with($route, 'admin/tagihan/')) {
        return 'billing.manage';
    }

    if (str_starts_with($route, 'admin/jaringan/')) {
        return 'network.manage';
    }

    if (str_starts_with($route, 'admin/pelanggan/')) {
        return 'customers.view';
    }

    if (str_starts_with($route, 'admin/admin/pengaduan/')) {
        return 'tickets.manage';
    }

    return 'portal.access';
}

function adminClearSession(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function adminLoginUrl(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminPosition = strpos($scriptName, '/admin/');
    $basePath = $adminPosition === false ? '' : substr($scriptName, 0, $adminPosition);

    return rtrim($basePath, '/') . '/admin/login.php';
}

function adminCsrfToken(): string
{
    if (
        !isset($_SESSION['admin_csrf'])
        || !is_string($_SESSION['admin_csrf'])
        || $_SESSION['admin_csrf'] === ''
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

adminStartSession();
