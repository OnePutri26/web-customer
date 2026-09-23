<?php

/*
|--------------------------------------------------------------------------
| AUTHENTICATION & CUSTOMER ACCESS CONTROL
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| WAJIB LOGIN
|--------------------------------------------------------------------------
*/

function requireLogin(): void
{
    if (
        !isset($_SESSION['user_id']) ||
        (int) $_SESSION['user_id'] <= 0
    ) {

        header("Location: ../login.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| WAJIB ROLE
|--------------------------------------------------------------------------
*/

function requireRole(string $requiredRole): void
{
    requireLogin();

    $role = strtolower(
        trim(
            (string) (
                $_SESSION['role'] ?? ''
            )
        )
    );

    if ($role !== strtolower($requiredRole)) {

        /*
        | Semua akses ilegal dikembalikan ke login.
        */

        unset(
            $_SESSION['user_id'],
            $_SESSION['nama'],
            $_SESSION['username'],
            $_SESSION['role'],
            $_SESSION['user_status'],
            $_SESSION['subscription_status']
        );

        header("Location: ../login.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| AMBIL STATUS LANGGANAN DARI DATABASE
|--------------------------------------------------------------------------
|
| Status session tidak dijadikan satu-satunya sumber kebenaran.
| Kita cek database agar URL tidak bisa dibypass.
|
*/

function getCustomerSubscriptionStatus(mysqli $conn, int $userId): string
{
    $stmt = $conn->prepare("
        SELECT status_langganan
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $customer = $result->fetch_assoc();

    $stmt->close();


    if (!$customer) {
        return 'belum_berlangganan';
    }


    $status = strtolower(
        trim(
            (string) (
                $customer['status_langganan'] ?? ''
            )
        )
    );


    if ($status === '') {
        return 'belum_berlangganan';
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALISASI
    |--------------------------------------------------------------------------
    */

    switch ($status) {

        case 'active':
        case 'aktif':
            return 'aktif';


        case 'belum berlangganan':
        case 'belum_langganan':
        case 'belum_berlangganan':
            return 'belum_berlangganan';


        case 'pending':
        case 'proses':
        case 'menunggu pemasangan':
        case 'menunggu_pemasangan':
            return 'pending';


        case 'suspended':
        case 'ditangguhkan':
            return 'suspended';


        case 'terminated':
        case 'dihentikan':
            return 'terminated';


        default:
            return $status;
    }
}


/*
|--------------------------------------------------------------------------
| CUSTOMER HANYA BOLEH MEMILIH PAKET
|--------------------------------------------------------------------------
|
| Dipakai di:
| customer/langganan.php
|
*/

function requireCustomerCanChoosePackage(mysqli $conn): void
{
    requireRole('customer');

    $userId =
        (int) $_SESSION['user_id'];

    $status =
        getCustomerSubscriptionStatus(
            $conn,
            $userId
        );


    /*
    | Kalau sudah aktif, tidak perlu memilih paket lagi.
    */

    if ($status === 'aktif') {

        header(
            "Location: dashboard.php"
        );

        exit;
    }


    /*
    | Suspended / terminated tidak boleh
    | melakukan proses langganan baru.
    */

    if (
        $status === 'suspended' ||
        $status === 'terminated'
    ) {

        header(
            "Location: dashboard.php"
        );

        exit;
    }


    /*
    | Status lain boleh masuk ke langganan.
    */

    $_SESSION['subscription_status'] = $status;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER SUDAH BERLANGGANAN
|--------------------------------------------------------------------------
|
| Dipakai untuk:
|
| dashboard.php
| payment.php
| pengaduan.php
| profile.php
| dll.
|
*/

function requireActiveCustomer(mysqli $conn): void
{
    requireRole('customer');

    $userId =
        (int) $_SESSION['user_id'];


    $status =
        getCustomerSubscriptionStatus(
            $conn,
            $userId
        );


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER AKTIF
    |--------------------------------------------------------------------------
    */

    if ($status === 'aktif') {

        $_SESSION['subscription_status'] = 'aktif';

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | BELUM BERLANGGANAN
    |--------------------------------------------------------------------------
    */

    if ($status === 'belum_berlangganan') {

        $_SESSION['subscription_status'] =
            'belum_berlangganan';

        header(
            "Location: langganan.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | PENDING
    |--------------------------------------------------------------------------
    */

    if ($status === 'pending') {

        $_SESSION['subscription_status'] =
            'pending';

        header(
            "Location: installation.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | SUSPENDED
    |--------------------------------------------------------------------------
    */

    if ($status === 'suspended') {

        $_SESSION['subscription_status'] =
            'suspended';

        header(
            "Location: dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TERMINATED
    |--------------------------------------------------------------------------
    */

    if ($status === 'terminated') {

        unset(
            $_SESSION['user_id'],
            $_SESSION['nama'],
            $_SESSION['username'],
            $_SESSION['role'],
            $_SESSION['user_status'],
            $_SESSION['subscription_status']
        );

        $_SESSION['login_error'] =
            "Layanan WiFi Anda telah dihentikan.";

        header(
            "Location: ../login.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS TIDAK DIKENALI
    |--------------------------------------------------------------------------
    */

    $_SESSION['login_error'] =
        "Status langganan tidak dikenali.";

    header(
        "Location: ../login.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER BOLEH AKSES PROSES PEMASANGAN
|--------------------------------------------------------------------------
*/

function requireCustomerInstallation(mysqli $conn): void
{
    requireRole('customer');

    $userId =
        (int) $_SESSION['user_id'];

    $status =
        getCustomerSubscriptionStatus(
            $conn,
            $userId
        );


    /*
    | Belum memilih paket
    */

    if ($status === 'belum_berlangganan') {

        header(
            "Location: langganan.php"
        );

        exit;
    }


    /*
    | Sudah aktif
    */

    if ($status === 'aktif') {

        header(
            "Location: dashboard.php"
        );

        exit;
    }


    /*
    | Pending boleh melihat installation.
    */

    if ($status === 'pending') {

        $_SESSION['subscription_status'] = 'pending';

        return;
    }


    /*
    | Status lainnya
    */

    header(
        "Location: dashboard.php"
    );

    exit;
}
