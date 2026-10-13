<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/auth.php";

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

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectRoleHome(
        $conn,
        (string) $_SESSION['role'],
        (int) $_SESSION['user_id']
    );
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


                    $stmt->close();
                    redirectRoleHome(
                        $conn,
                        (string) $_SESSION['role'],
                        (int) $user['id']
                    );
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
