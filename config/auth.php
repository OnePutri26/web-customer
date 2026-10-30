<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YESNET - AUTH & ROUTING HELPER
|--------------------------------------------------------------------------
| File    : config/auth.php
| Butuh   : PHP 8.1+ (memakai tipe `never`, `match`, `mixed`)
|--------------------------------------------------------------------------
*/

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('YESNET membutuhkan PHP 8.1 atau lebih baru.');
}

if (!defined('YESNET_AUTH_VERSION')) {
    define('YESNET_AUTH_VERSION', '2026.10.30');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Sesi
|--------------------------------------------------------------------------
*/

function clearAuthSession(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['customer_id'],
        $_SESSION['nama'],
        $_SESSION['username'],
        $_SESSION['email'],
        $_SESSION['telephone'],
        $_SESSION['role'],
        $_SESSION['user_status'],
        $_SESSION['status_langganan'],
        $_SESSION['subscription_status'],
        $_SESSION['paket_id'],
        $_SESSION['payment_customer_id'],
        $_SESSION['payment_package_id'],
        $_SESSION['dummy_payment'],
        $_SESSION['dummy_mode'],
        $_SESSION['dummy_order_id']
    );
}

/*
|--------------------------------------------------------------------------
| URL & redirect
|--------------------------------------------------------------------------
*/

function appBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($directory === '.') {
        return '';
    }

    foreach (['/customer/', '/admin/', '/teknisi/'] as $section) {
        $sectionPosition = strpos($scriptName, $section);
        if ($sectionPosition !== false) {
            $directory = substr($scriptName, 0, $sectionPosition);
            break;
        }
    }

    return $directory === '/' ? '' : $directory;
}

function appRedirect(string $path, int $statusCode = 302): never
{
    $path = ltrim($path, '/');
    header('Location: ' . appBasePath() . '/' . $path, true, $statusCode);
    exit;
}

/*
|--------------------------------------------------------------------------
| Normalisasi
|--------------------------------------------------------------------------
*/

function normalizeAuthRole(mixed $role): string
{
    $role = strtolower(trim((string) $role));
    return $role === 'technician' ? 'teknisi' : $role;
}

function normalizeCustomerStatus(mixed $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        '', 'belum berlangganan', 'belum_langganan', 'belum_berlangganan' => 'belum_berlangganan',
        'active', 'aktif', '1' => 'aktif',
        '3',
        'pending', 'proses', 'menunggu pemasangan', 'menunggu_pemasangan',
        'menunggu pembayaran', 'menunggu_pembayaran', 'pending_pembayaran',
        'pending_payment', 'menunggu survey', 'menunggu_survey', 'survey', 'approved',
        'scheduled', 'dijadwalkan', 'menunggu aktivasi', 'menunggu_aktivasi' => 'pending',
        'suspended', 'suspend', 'ditangguhkan' => 'suspended',
        'terminated', 'berhenti', 'dihentikan' => 'terminated',
        default => $status,
    };
}

/*
|--------------------------------------------------------------------------
| Status routing customer
|--------------------------------------------------------------------------
*/

function customerRoutingState(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT id, paket_id, status_langganan
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        return [
            'customer_exists' => false,
            'customer_status' => '',
            'paket_id' => 0,
            'subscription_id' => 0,
            'subscription_status' => '',
            'invoice_id' => 0,
            'invoice_status' => '',
            'payment_status' => '',
            'application_status' => '',
            'installation_status' => '',
        ];
    }

    $customerId = (int) $customer['id'];

    // Subscription terakhir
    $stmt = $conn->prepare("
        SELECT id, status
        FROM subscription
        WHERE id_customer = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $subscription = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $subscriptionId = (int) ($subscription['id'] ?? 0);

    // Tagihan terakhir
    $stmt = $conn->prepare("
        SELECT id, status
        FROM tagihan
        WHERE id_customer = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $invoiceId = (int) ($invoice['id'] ?? 0);

    // Pembayaran terakhir untuk tagihan tersebut
    $paymentStatus = '';
    if ($invoiceId > 0) {
        $stmt = $conn->prepare("
            SELECT status
            FROM pembayaran
            WHERE id_tagihan = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $paymentStatus = strtolower(trim((string) ($payment['status'] ?? '')));
    }

    // Pengajuan pemasangan terakhir
    $stmt = $conn->prepare("
        SELECT id, status
        FROM pengajuan_pemasangan
        WHERE id_customer = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    // Instalasi terakhir untuk pengajuan tersebut
    $installationStatus = '';
    $applicationId = (int) ($application['id'] ?? 0);
    if ($applicationId > 0) {
        $stmt = $conn->prepare("
            SELECT status
            FROM instalasi
            WHERE id_pengajuan = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $installation = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $installationStatus = strtolower(trim((string) ($installation['status'] ?? '')));
    }

    return [
        'customer_exists' => true,
        'customer_status' => normalizeCustomerStatus($customer['status_langganan'] ?? ''),
        'paket_id' => (int) ($customer['paket_id'] ?? 0),
        'subscription_id' => $subscriptionId,
        'subscription_status' => strtolower(trim((string) ($subscription['status'] ?? ''))),
        'invoice_id' => $invoiceId,
        'invoice_status' => strtolower(trim((string) ($invoice['status'] ?? ''))),
        'payment_status' => $paymentStatus,
        'application_status' => strtolower(trim((string) ($application['status'] ?? ''))),
        'installation_status' => $installationStatus,
    ];
}

function customerRedirectPath(array $state): string
{
    if (empty($state['customer_exists'])) {
        return 'login.php';
    }

    $customerStatus = normalizeCustomerStatus($state['customer_status'] ?? '');
    $subscriptionStatus = strtolower(trim((string) ($state['subscription_status'] ?? '')));
    $invoiceStatus = strtolower(trim((string) ($state['invoice_status'] ?? '')));
    $paymentStatus = strtolower(trim((string) ($state['payment_status'] ?? '')));
    $invoiceId = (int) ($state['invoice_id'] ?? 0);
    $subscriptionId = (int) ($state['subscription_id'] ?? 0);
    $applicationStatus = strtolower(trim((string) ($state['application_status'] ?? '')));
    $installationStatus = strtolower(trim((string) ($state['installation_status'] ?? '')));

    $paymentVerified = in_array($invoiceStatus, ['paid', 'lunas'], true)
        || in_array($paymentStatus, ['success', 'paid', 'settlement'], true);

    $paymentFailed = in_array(
        $paymentStatus,
        ['failed', 'deny', 'denied', 'expire', 'expired', 'cancelled'],
        true
    ) || in_array(
        $invoiceStatus,
        ['failed', 'expired', 'deny', 'denied', 'cancelled'],
        true
    );

    $installationInProgress = in_array(
        $applicationStatus,
        ['baru', 'pending', 'approved', 'menunggu_survey', 'survey', 'scheduled', 'dijadwalkan', 'proses', 'menunggu_pemasangan'],
        true
    ) || in_array(
        $installationStatus,
        ['pending', 'dijadwalkan', 'proses', 'scheduled'],
        true
    );

    // 1. Ada tagihan yang belum beres -> halaman pembayaran
    if (
        $invoiceId > 0 &&
        (
            $paymentFailed ||
            (
                !$paymentVerified &&
                in_array($invoiceStatus, ['unpaid', 'pending', 'belum_bayar', 'terlambat'], true)
            )
        )
    ) {
        return 'pembayaran.php?tagihan=' . $invoiceId;
    }

    // 2. Belum punya paket sama sekali
    if ((int) ($state['paket_id'] ?? 0) <= 0 && $subscriptionId <= 0) {
        return 'customer/packages.php';
    }

    $subscriptionActive = in_array($subscriptionStatus, ['active', 'aktif'], true);
    $customerActive = $customerStatus === 'aktif';

    // 3. Customer aktif -> dashboard
    if (
        !$installationInProgress &&
        $customerActive &&
        (
            ($subscriptionActive && ($paymentVerified || $invoiceId === 0)) ||
            ($subscriptionId === 0 && $invoiceId === 0)
        )
    ) {
        return 'customer/dashboard.php';
    }

    // 4. Ada progres pesanan / pembayaran sudah terverifikasi
    $hasOrderProgress = $subscriptionId > 0
        || $applicationStatus !== ''
        || $installationStatus !== '';

    if ($paymentVerified || $hasOrderProgress) {
        return $subscriptionId > 0
            ? 'status_order.php'
            : 'customer/validasi_cs.php';
    }

    // 5. Suspended
    if ($customerStatus === 'suspended') {
        return 'customer/dashboard.php';
    }

    return 'customer/packages.php';
}

/*
|--------------------------------------------------------------------------
| Redirect berdasarkan role
|--------------------------------------------------------------------------
*/

function redirectCustomer(mysqli $conn, int $userId): never
{
    if ($userId <= 0) {
        clearAuthSession();
        $_SESSION['login_error'] = 'Sesi login tidak valid. Silakan masuk kembali.';
        appRedirect('login.php');
    }

    $state = customerRoutingState($conn, $userId);

    if (empty($state['customer_exists'])) {
        clearAuthSession();
        $_SESSION['login_error'] = 'Data customer tidak ditemukan. Silakan hubungi administrator.';
        appRedirect('login.php');
    }

    $_SESSION['subscription_status'] = $state['customer_status'];
    $target = customerRedirectPath($state);

    // Jika paket sudah dipilih sebelumnya lewat flow, lanjut ke verifikasi
    if (
        $target === 'customer/packages.php'
        && !empty($_SESSION['flow']['tersedia'])
        && (int) ($_SESSION['flow']['paket_id'] ?? 0) > 0
    ) {
        $packageId = (int) $_SESSION['flow']['paket_id'];

        $stmt = $conn->prepare("
            SELECT id
            FROM paket_wifi
            WHERE id = ?
              AND (
                  status IS NULL
                  OR TRIM(status) = ''
                  OR LOWER(TRIM(status)) IN ('aktif', 'active', '1', 'tersedia', 'available')
              )
            LIMIT 1
        ");
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $package = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($package) {
            appRedirect('verifikasi.php');
        }

        unset($_SESSION['flow']['paket_id']);
    }

    if ($target === 'login.php') {
        clearAuthSession();
        $_SESSION['login_error'] = 'Status akun tidak dapat diverifikasi. Silakan masuk kembali.';
    }

    appRedirect($target);
}

function redirectRoleHome(mysqli $conn, string $role, int $userId): never
{
    $role = normalizeAuthRole($role);

    if ($role === 'admin') {
        appRedirect('admin/dashboard.php');
    }

    if ($role === 'teknisi') {
        appRedirect('teknisi/dashboard.php');
    }

    if ($role === 'customer') {
        redirectCustomer($conn, $userId);
    }

    clearAuthSession();
    $_SESSION['login_error'] = 'Role akun tidak dikenali.';
    appRedirect('login.php');
}

/*
|--------------------------------------------------------------------------
| Guard halaman
|--------------------------------------------------------------------------
*/

function requireLogin(): void
{
    if ((int) ($_SESSION['user_id'] ?? 0) <= 0) {
        appRedirect('login.php');
    }
}

function requireRole(string $requiredRole): void
{
    requireLogin();

    global $conn;

    $userId = (int) $_SESSION['user_id'];
    $sessionRole = normalizeAuthRole($_SESSION['role'] ?? '');
    $requiredRole = normalizeAuthRole($requiredRole);

    if (!isset($conn) || !$conn instanceof mysqli) {
        require_once __DIR__ . '/database.php';
    }

    if (!isset($conn) || !$conn instanceof mysqli) {
        error_log('Authentication database connection is unavailable after database bootstrap.');
        http_response_code(503);
        exit('Layanan autentikasi sementara tidak tersedia. Silakan coba kembali nanti.');
    }

    $stmt = $conn->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        clearAuthSession();
        $_SESSION['login_error'] = 'Akun tidak ditemukan. Silakan masuk kembali.';
        appRedirect('login.php');
    }

    $databaseRole = normalizeAuthRole($user['role'] ?? '');
    $userStatus = strtolower(trim((string) ($user['status'] ?? '')));

    if (!in_array($userStatus, ['1', 'active', 'aktif'], true)) {
        clearAuthSession();
        $_SESSION['login_error'] = 'Akun Anda sedang tidak aktif.';
        appRedirect('login.php');
    }

    if ($databaseRole !== $sessionRole) {
        clearAuthSession();
        $_SESSION['login_error'] = 'Role akun berubah. Silakan masuk kembali.';
        appRedirect('login.php');
    }

    if ($databaseRole !== $requiredRole) {
        redirectRoleHome($conn, $databaseRole, $userId);
    }
}

function getCustomerSubscriptionStatus(mysqli $conn, int $userId): string
{
    $state = customerRoutingState($conn, $userId);

    return empty($state['customer_exists'])
        ? 'belum_berlangganan'
        : $state['customer_status'];
}

/*
 * Dipakai di halaman pilih paket (customer/packages.php).
 * Hanya boleh tetap di halaman jika memang tujuannya packages.php;
 * selain itu diarahkan ke halaman yang sesuai (mencegah redirect loop).
 */
function requireCustomerCanChoosePackage(mysqli $conn): void
{
    requireRole('customer');

    $state = customerRoutingState($conn, (int) $_SESSION['user_id']);
    $_SESSION['subscription_status'] = $state['customer_status'];

    $target = customerRedirectPath($state);

    if ($target === 'customer/packages.php') {
        return;
    }

    appRedirect($target);
}

function requireActiveCustomer(mysqli $conn): void
{
    requireRole('customer');

    $state = customerRoutingState($conn, (int) $_SESSION['user_id']);
    $target = customerRedirectPath($state);

    if ($target === 'customer/dashboard.php') {
        if ($state['customer_status'] === 'suspended') {
            http_response_code(403);
            exit('Akun customer sedang ditangguhkan.');
        }

        $_SESSION['subscription_status'] = $state['customer_status'];
        return;
    }

    if ($state['customer_status'] === 'terminated') {
        clearAuthSession();
        $_SESSION['login_error'] = 'Layanan WiFi Anda telah dihentikan.';
        appRedirect('login.php');
    }

    redirectCustomer($conn, (int) $_SESSION['user_id']);
}

function requireCustomerInstallation(mysqli $conn): void
{
    requireRole('customer');

    $state = customerRoutingState($conn, (int) $_SESSION['user_id']);
    $target = customerRedirectPath($state);

    if (
        $target === 'customer/installation.php'
        || $target === 'customer/validasi_cs.php'
    ) {
        $_SESSION['subscription_status'] = $state['customer_status'];
        return;
    }

    redirectCustomer($conn, (int) $_SESSION['user_id']);
}