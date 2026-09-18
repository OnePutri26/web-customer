<?php

session_start();

require_once "config/database.php";


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
        $_SESSION['user_status']
    );
}


/*
|--------------------------------------------------------------------------
| REDIRECT CUSTOMER
|--------------------------------------------------------------------------
*/

function redirectCustomer(mysqli $conn, int $userId): void
{
    /*
    |--------------------------------------------------------------------------
    | CEK DATA CUSTOMER
    |--------------------------------------------------------------------------
    */

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

    if (!$stmt) {

        $_SESSION['login_error'] =
            "Data customer tidak dapat diperiksa.";

        clearLoginSession();

        header("Location: login.php");
        exit;
    }


    $stmt->bind_param(
        "i",
        $userId
    );


    if (!$stmt->execute()) {

        $stmt->close();

        $_SESSION['login_error'] =
            "Gagal memeriksa data customer.";

        clearLoginSession();

        header("Location: login.php");
        exit;
    }


    $result = $stmt->get_result();

    $customer = $result
        ? $result->fetch_assoc()
        : null;


    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER TIDAK ADA
    |--------------------------------------------------------------------------
    */

    if (!$customer) {

        /*
        | Kalau user customer belum mempunyai
        | data customers, arahkan ke halaman langganan.
        */

        header(
            "Location: customer/langganan.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS LANGGANAN
    |--------------------------------------------------------------------------
    */

    $status = strtolower(
        trim(
            (string) (
                $customer['status_langganan']
                ?? ''
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

    if ($status === 'active') {
        $status = 'aktif';
    }


    /*
    |--------------------------------------------------------------------------
    | BELUM BERLANGGANAN
    |--------------------------------------------------------------------------
    */

    if (
        $status === 'belum_berlangganan' ||
        $status === 'belum berlangganan'
    ) {

        header(
            "Location: customer/langganan.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | PENDING
    |--------------------------------------------------------------------------
    */

    if (
        $status === 'pending' ||
        $status === 'proses' ||
        $status === 'menunggu_pemasangan'
    ) {

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
    | CUSTOMER SUSPENDED
    |--------------------------------------------------------------------------
    */

    if (
        $status === 'suspended' ||
        $status === 'ditangguhkan'
    ) {

        /*
        | Tetap masuk dashboard.
        | Dashboard dapat menampilkan status suspended.
        */

        header(
            "Location: customer/dashboard.php"
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER TERMINATED
    |--------------------------------------------------------------------------
    */

    if (
        $status === 'terminated' ||
        $status === 'dihentikan'
    ) {

        $_SESSION['login_error'] =
            "Layanan WiFi Anda telah dihentikan.";

        clearLoginSession();

        header("Location: login.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS TIDAK DIKENALI
    |--------------------------------------------------------------------------
    */

    $_SESSION['login_error'] =
        "Status langganan tidak dikenali: " . $status;

    clearLoginSession();

    header("Location: login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ERROR DARI SESSION
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['login_error'])) {

    $error =
        $_SESSION['login_error'];

    unset(
        $_SESSION['login_error']
    );
}


/*
|--------------------------------------------------------------------------
| CEK JIKA SUDAH LOGIN
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


    /*
    |--------------------------------------------------------------------------
    | AMBIL INPUT
    |--------------------------------------------------------------------------
    */

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
        |
        | LOGIN BISA MENGGUNAKAN:
        |
        | 1. username
        | 2. nama
        |
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                nama,
                password,
                role,
                status
            FROM users
            WHERE
                username = ?
                OR nama = ?
            LIMIT 1
        ");


        /*
        |--------------------------------------------------------------------------
        | QUERY GAGAL
        |--------------------------------------------------------------------------
        */

        if (!$stmt) {

            $error =
                "Query login gagal: " .
                $conn->error;

        } else {


            /*
            |--------------------------------------------------------------------------
            | BIND
            |--------------------------------------------------------------------------
            */

            $stmt->bind_param(
                "ss",
                $usernameInput,
                $usernameInput
            );


            /*
            |--------------------------------------------------------------------------
            | EXECUTE
            |--------------------------------------------------------------------------
            */

            if (!$stmt->execute()) {

                $error =
                    "Proses login gagal: " .
                    $stmt->error;

            } else {


                /*
                |--------------------------------------------------------------------------
                | RESULT
                |--------------------------------------------------------------------------
                */

                $result =
                    $stmt->get_result();


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

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | AMBIL USER
                    |--------------------------------------------------------------------------
                    */

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
                                        $user['status']
                                        ?? ''
                                    )
                                )
                            );


                        /*
                        |--------------------------------------------------------------------------
                        | STATUS USER AKTIF
                        |--------------------------------------------------------------------------
                        |
                        | Mendukung:
                        |
                        | active
                        | aktif
                        | 1
                        |
                        |--------------------------------------------------------------------------
                        */

                        $activeStatuses = [
                            'active',
                            'aktif',
                            '1'
                        ];


                        /*
                        |--------------------------------------------------------------------------
                        | USER TIDAK AKTIF
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

                        } else {


                            /*
                            |--------------------------------------------------------------------------
                            | LOGIN BERHASIL
                            |--------------------------------------------------------------------------
                            */

                            session_regenerate_id(true);


                            /*
                            |--------------------------------------------------------------------------
                            | SIMPAN SESSION
                            |--------------------------------------------------------------------------
                            */

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
                                            $user['role']
                                            ?? ''
                                        )
                                    )
                                );


                            $_SESSION['user_status'] =
                                $user['status'];


                            /*
                            |--------------------------------------------------------------------------
                            | ROLE
                            |--------------------------------------------------------------------------
                            */

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

                            $error =
                                "Role akun tidak dikenali.";

                            clearLoginSession();
                        }
                    }
                }
            }


            $stmt->close();
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


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Custom CSS -->

    <link
        rel="stylesheet"
        href="assets/css/login.css?v=10"
    >

</head>


<body>


<!-- ==========================================================
     BACKGROUND
========================================================== -->

<div class="background-circle circle-1"></div>

<div class="background-circle circle-2"></div>



<!-- ==========================================================
     LOGIN WRAPPER
========================================================== -->

<div class="login-wrapper">


    <div class="login-container">


        <!-- ==================================================
             LEFT
        =================================================== -->

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



        <!-- ==================================================
             RIGHT
        =================================================== -->

        <div class="login-card">


            <div class="login-header">

                <h2>
                    Selamat Datang 👋
                </h2>

                <p>
                    Masuk menggunakan username atau nama Anda.
                </p>

            </div>



            <!-- ERROR -->

            <?php if ($error !== ''): ?>

                <div
                    class="alert alert-danger mb-4"
                    role="alert"
                >

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= e($error) ?>

                </div>

            <?php endif; ?>



            <!-- LOGIN FORM -->

            <form
                method="POST"
                action=""
                autocomplete="on"
            >


                <!-- USERNAME / NAMA -->

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



                <!-- PASSWORD -->

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



                <!-- LOGIN -->

                <button
                    type="submit"
                    class="btn login-btn w-100"
                >

                    <i class="bi bi-box-arrow-in-right me-2"></i>

                    Masuk ke Dashboard

                </button>


            </form>



            <!-- REGISTER -->

            <div class="register-text">

                Belum punya akun?

                <a href="register.php">
                    Daftar sekarang
                </a>

            </div>



            <!-- SECURITY -->

            <div class="security-text">

                <i class="bi bi-shield-lock-fill"></i>

                Sistem login aman &amp; terproteksi

            </div>


        </div>


    </div>

</div>


</body>

</html>
