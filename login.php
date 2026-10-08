<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";

date_default_timezone_set("Asia/Jakarta");


/*
|--------------------------------------------------------------------------
| VARIABEL
|--------------------------------------------------------------------------
*/

$error = "";
$usernameInput = "";


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CLEAR LOGIN SESSION
|--------------------------------------------------------------------------
*/

function clearLoginSession(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['nama'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['user_status'],
        $_SESSION['subscription_status']
    );
}


/*
|--------------------------------------------------------------------------
| REDIRECT CUSTOMER
|--------------------------------------------------------------------------
|
| Menentukan halaman berdasarkan status langganan.
|
*/

function redirectCustomer(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            paket_id,
            nama,
            telephone,
            email,
            nik,
            alamat,
            status_langganan
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);

    $stmt->execute();

    $result = $stmt->get_result();

    $customer = $result->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | DATA CUSTOMER TIDAK ADA
    |--------------------------------------------------------------------------
    */

    if (!$customer) {

        /*
        | Customer belum mempunyai record customers.
        | Hanya boleh menuju halaman langganan.
        */

        $_SESSION['subscription_status'] =
            'belum_berlangganan';

        header("Location: customer/langganan.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | AMBIL STATUS LANGGANAN
    |--------------------------------------------------------------------------
    */

    $status = strtolower(
        trim(
            (string) (
                $customer['status_langganan'] ?? ''
            )
        )
    );


    /*
    |--------------------------------------------------------------------------
    | STATUS KOSONG
    |--------------------------------------------------------------------------
    */

    if ($status === '') {
        $status = 'belum_berlangganan';
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALISASI STATUS
    |--------------------------------------------------------------------------
    */

    switch ($status) {

        case 'active':
        case 'aktif':

            $status = 'aktif';

            break;


        case 'belum berlangganan':
        case 'belum_langganan':
        case 'belum_berlangganan':

            $status = 'belum_berlangganan';

            break;


        case 'pending':
        case 'proses':
        case 'menunggu pemasangan':
        case 'menunggu_pemasangan':

            $status = 'pending';

            break;


        case 'suspended':
        case 'ditangguhkan':

            $status = 'suspended';

            break;


        case 'terminated':
        case 'dihentikan':

            $status = 'terminated';

            break;
    }


    /*
    |--------------------------------------------------------------------------
    | SIMPAN STATUS KE SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['subscription_status'] = $status;


    /*
    |--------------------------------------------------------------------------
    | BELUM BERLANGGANAN
    |--------------------------------------------------------------------------
    */

    if ($status === 'belum_berlangganan') {

        header(
            "Location: customer/langganan.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | MENUNGGU / PROSES PEMASANGAN
    |--------------------------------------------------------------------------
    */

    if ($status === 'pending') {

        header(
            "Location: customer/installation.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER AKTIF
    |--------------------------------------------------------------------------
    */

    if ($status === 'aktif') {

        header(
            "Location: customer/dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | SUSPENDED
    |--------------------------------------------------------------------------
    */

    if ($status === 'suspended') {

        header(
            "Location: customer/dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TERMINATED
    |--------------------------------------------------------------------------
    */

    if ($status === 'terminated') {

        clearLoginSession();

        $_SESSION['login_error'] =
            "Layanan WiFi Anda telah dihentikan.";

        header("Location: login.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS TIDAK DIKENALI
    |--------------------------------------------------------------------------
    */

    clearLoginSession();

    $_SESSION['login_error'] =
        "Status langganan akun tidak dikenali.";

    header("Location: login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ERROR DARI SESSION
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['login_error'])) {

    $error = $_SESSION['login_error'];

    unset($_SESSION['login_error']);
}


/*
|--------------------------------------------------------------------------
| CEK SUDAH LOGIN
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role'])
) {

    $existingUserId =
        (int) $_SESSION['user_id'];

    $existingRole =
        strtolower(
            trim(
                (string) $_SESSION['role']
            )
        );


    /*
    |--------------------------------------------------------------------------
    | ADMIN
    |--------------------------------------------------------------------------
    */

    if ($existingRole === 'admin') {

        header(
            "Location: admin/dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TEKNISI
    |--------------------------------------------------------------------------
    */

    if (
        $existingRole === 'teknisi' ||
        $existingRole === 'technician'
    ) {

        header(
            "Location: teknisi/dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER
    |--------------------------------------------------------------------------
    */

    if ($existingRole === 'customer') {

        redirectCustomer(
            $conn,
            $existingUserId
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | ROLE TIDAK DIKENALI
    |--------------------------------------------------------------------------
    */

    clearLoginSession();
}


/*
|--------------------------------------------------------------------------
| PROSES LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usernameInput =
        trim(
            $_POST['username'] ?? ''
        );

    $password =
        $_POST['password'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if ($usernameInput === '') {

        $error =
            "Username atau nama wajib diisi.";

    } elseif ($password === '') {

        $error =
            "Password wajib diisi.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | CARI USER
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                nama,
                email,
                telephone,
                password,
                role,
                status
            FROM users
            WHERE
                username = ?
                OR nama = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "ss",
            $usernameInput,
            $usernameInput
        );

        $stmt->execute();

        $result = $stmt->get_result();


        /*
        |--------------------------------------------------------------------------
        | USER TIDAK DITEMUKAN
        |--------------------------------------------------------------------------
        */

        if (
            !$result ||
            $result->num_rows === 0
        ) {

            $error =
                "Username/nama atau password salah.";

            $stmt->close();

        } else {

            $user =
                $result->fetch_assoc();


            /*
            |--------------------------------------------------------------------------
            | CEK PASSWORD
            |--------------------------------------------------------------------------
            */

            if (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                $error =
                    "Username/nama atau password salah.";

                $stmt->close();

            } else {

                /*
                |--------------------------------------------------------------------------
                | STATUS USER
                |--------------------------------------------------------------------------
                */

                $userStatus =
                    strtolower(
                        trim(
                            (string) (
                                $user['status'] ?? ''
                            )
                        )
                    );


                $activeStatuses = [
                    'active',
                    'aktif',
                    '1'
                ];


                /*
                |--------------------------------------------------------------------------
                | AKUN TIDAK AKTIF
                |--------------------------------------------------------------------------
                */

                if (
                    !in_array(
                        $userStatus,
                        $activeStatuses,
                        true
                    )
                ) {

                    $error =
                        "Akun Anda sedang tidak aktif.";

                    $stmt->close();

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | LOGIN BERHASIL
                    |--------------------------------------------------------------------------
                    */

                    session_regenerate_id(true);


                    $_SESSION['user_id'] =
                        (int) $user['id'];

                    $_SESSION['nama'] =
                        $user['nama'];

                    $_SESSION['username'] =
                        $user['username'];

                    $_SESSION['role'] =
                        strtolower(
                            trim(
                                (string) (
                                    $user['role'] ?? ''
                                )
                            )
                        );

                    $_SESSION['user_status'] =
                        $user['status'];


                    $role =
                        $_SESSION['role'];


                    /*
                    |--------------------------------------------------------------------------
                    | ADMIN
                    |--------------------------------------------------------------------------
                    */

                    if ($role === 'admin') {

                        $stmt->close();

                        header(
                            "Location: admin/dashboard.php"
                        );

                        exit;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | TEKNISI
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $role === 'teknisi' ||
                        $role === 'technician'
                    ) {

                        $stmt->close();

                        header(
                            "Location: teknisi/dashboard.php"
                        );

                        exit;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | CUSTOMER
                    |--------------------------------------------------------------------------
                    */

                    if ($role === 'customer') {

                        $stmt->close();

                        redirectCustomer(
                            $conn,
                            (int) $user['id']
                        );

                        exit;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | ROLE TIDAK DIKENALI
                    |--------------------------------------------------------------------------
                    */

                    clearLoginSession();

                    $error =
                        "Role akun tidak dikenali.";

                    $stmt->close();
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Login WiFi Management System"
    >

    <title>
        Login | WiFi Management System
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/login.css?v=11"
    >

</head>


<body>


<div class="background-circle circle-1"></div>

<div class="background-circle circle-2"></div>


<div class="login-wrapper">

    <div class="login-container">


        <!-- LEFT -->

        <div class="login-info">

            <div class="wifi-icon">

                <img
                    src="logo-yesnet.png"
                    alt="Logo WiFi"
                >

            </div>


            <h1>

                WiFi<br>

                <span>
                    Management
                </span>

            </h1>


            <p>

                Kelola layanan WiFi dengan lebih mudah,
                cepat, dan terorganisir dalam satu sistem.

            </p>


            <div class="feature-list">


                <div class="feature-item">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Kelola data pelanggan
                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Pantau instalasi WiFi
                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Sistem terintegrasi
                    </span>

                </div>


            </div>

        </div>


        <!-- RIGHT -->

        <div class="login-card">


            <div class="login-header">

                <h2>
                    Selamat Datang 👋
                </h2>

                <p>
                    Masuk menggunakan username atau nama Anda.
                </p>

            </div>


            <?php if ($error !== ''): ?>

                <div
                    class="alert alert-danger mb-4"
                    role="alert"
                >

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= e($error) ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                action=""
                autocomplete="on"
            >


                <div class="mb-3">

                    <label
                        for="username"
                        class="form-label"
                    >
                        Username / Nama
                    </label>


                    <div class="input-wrapper">

                        <span class="input-icon">
                            <i class="bi bi-person"></i>
                        </span>


                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username atau nama"
                            value="<?= e($usernameInput) ?>"
                            autocomplete="username"
                            required
                            autofocus
                        >

                    </div>

                </div>


                <div class="mb-4">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Password
                    </label>


                    <div class="input-wrapper">

                        <span class="input-icon">
                            <i class="bi bi-lock"></i>
                        </span>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >

                    </div>

                </div>


                <button
                    type="submit"
                    class="btn login-btn w-100"
                >

                    <i class="bi bi-box-arrow-in-right me-2"></i>

                    Masuk ke Dashboard

                </button>


            </form>


            <div class="register-text">

                Belum punya akun?

                <a href="register.php">
                    Daftar sekarang
                </a>

            </div>


            <div class="security-text">

                <i class="bi bi-shield-lock-fill"></i>

                Sistem login aman &amp; terproteksi

            </div>


        </div>

    </div>

</div>


</body>

</html>
