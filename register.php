<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| KONEKSI DATABASE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/config/database.php";


/*
|--------------------------------------------------------------------------
| VALIDASI KONEKSI
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}


/*
|--------------------------------------------------------------------------
| CEK DATABASE AKTIF
|--------------------------------------------------------------------------
*/

try {

    $resultDatabase = $conn->query("SELECT DATABASE()");
    $databaseAktif = $resultDatabase->fetch_row()[0] ?? '';

    if ($databaseAktif !== 'wifi_management') {

        die(
            "Konfigurasi database salah. " .
            "Database aktif: " .
            htmlspecialchars($databaseAktif, ENT_QUOTES, 'UTF-8')
        );
    }

} catch (Throwable $e) {

    die("Gagal memeriksa database: " . $e->getMessage());
}


/*
|--------------------------------------------------------------------------
| VARIABEL DEFAULT
|--------------------------------------------------------------------------
*/

$error = "";

$nama      = "";
$username  = "";
$email     = "";
$telephone = "";
$password  = "";
$nik       = "";
$alamat    = "";


/*
|--------------------------------------------------------------------------
| FUNGSI ESCAPE HTML
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| PROSES REGISTRASI
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /*
    |--------------------------------------------------------------------------
    | AMBIL DATA FORM
    |--------------------------------------------------------------------------
    */

    $nama      = trim($_POST['nama'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $nik       = trim($_POST['nik'] ?? '');
    $alamat    = trim($_POST['alamat'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | VALIDASI DASAR
    |--------------------------------------------------------------------------
    */

    if (
        $nama === '' ||
        $username === '' ||
        $email === '' ||
        $telephone === '' ||
        $password === '' ||
        $nik === '' ||
        $alamat === ''
    ) {

        $error = "Semua field wajib diisi.";

    } elseif (strlen($nama) < 3) {

        $error = "Nama minimal 3 karakter.";

    } elseif (strlen($username) < 4) {

        $error = "Username minimal 4 karakter.";

    } elseif (strlen($username) > 50) {

        $error = "Username maksimal 50 karakter.";

    } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {

        $error = "Username hanya boleh menggunakan huruf, angka, titik (.) dan underscore (_).";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Format email tidak valid.";

    } elseif (strlen($telephone) < 8) {

        $error = "Nomor telepon tidak valid.";

    } elseif (strlen($password) < 6) {

        $error = "Password minimal 6 karakter.";

    } elseif (!preg_match('/^[0-9]+$/', $nik)) {

        $error = "NIK hanya boleh berisi angka.";

    } elseif (strlen($nik) < 10) {

        $error = "NIK tidak valid.";

    }


    /*
    |--------------------------------------------------------------------------
    | CEK USERNAME
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $checkUsername = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $checkUsername->bind_param(
                "s",
                $username
            );

            $checkUsername->execute();

            $resultUsername = $checkUsername->get_result();

            if ($resultUsername->num_rows > 0) {

                $error = "Username sudah digunakan. Silakan pilih username lain.";
            }

            $checkUsername->close();

        } catch (Throwable $e) {

            $error = "Gagal memeriksa username: " . $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CEK EMAIL
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $checkEmail = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $checkEmail->bind_param(
                "s",
                $email
            );

            $checkEmail->execute();

            $resultEmail = $checkEmail->get_result();

            if ($resultEmail->num_rows > 0) {

                $error = "Email sudah terdaftar.";
            }

            $checkEmail->close();

        } catch (Throwable $e) {

            $error = "Gagal memeriksa email: " . $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CEK NIK
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $checkNik = $conn->prepare("
                SELECT id
                FROM customers
                WHERE nik = ?
                LIMIT 1
            ");

            $checkNik->bind_param(
                "s",
                $nik
            );

            $checkNik->execute();

            $resultNik = $checkNik->get_result();

            if ($resultNik->num_rows > 0) {

                $error = "NIK sudah terdaftar.";
            }

            $checkNik->close();

        } catch (Throwable $e) {

            $error = "Gagal memeriksa NIK: " . $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SIMPAN DATA
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        if ($passwordHash === false) {

            $error = "Gagal mengamankan password.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | MULAI TRANSACTION
                |--------------------------------------------------------------------------
                */

                $conn->begin_transaction();


                /*
                |--------------------------------------------------------------------------
                | INSERT USERS
                |--------------------------------------------------------------------------
                */

                $userStmt = $conn->prepare("
                    INSERT INTO users
                    (
                        username,
                        nama,
                        email,
                        telephone,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'customer',
                        1
                    )
                ");


                $userStmt->bind_param(
                    "sssss",
                    $username,
                    $nama,
                    $email,
                    $telephone,
                    $passwordHash
                );


                if (!$userStmt->execute()) {

                    throw new Exception(
                        "Gagal membuat akun: " .
                        $userStmt->error
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | ID USER BARU
                |--------------------------------------------------------------------------
                */

                $userId = (int) $userStmt->insert_id;

                $userStmt->close();


                /*
                |--------------------------------------------------------------------------
                | CUSTOMER BELUM MEMILIKI PAKET
                |--------------------------------------------------------------------------
                |
                | Kita gunakan NULL karena customer baru belum
                | memilih paket internet.
                |
                */

                $paketId = null;


                /*
                |--------------------------------------------------------------------------
                | INSERT CUSTOMERS
                |--------------------------------------------------------------------------
                */

                $customerStmt = $conn->prepare("
                    INSERT INTO customers
                    (
                        user_id,
                        paket_id,
                        nama,
                        telephone,
                        email,
                        nik,
                        alamat,
                        status_langganan
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'belum_berlangganan'
                    )
                ");


                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                |
                | bind_param() tidak selalu aman jika langsung diberi
                | NULL pada parameter integer.
                |
                | Karena paket_id boleh NULL, kita bind variabel integer
                | lalu set menjadi NULL.
                |
                */

                $customerStmt->bind_param(
                    "iisssss",
                    $userId,
                    $paketId,
                    $nama,
                    $telephone,
                    $email,
                    $nik,
                    $alamat
                );


                /*
                |--------------------------------------------------------------------------
                | PAKET ID = NULL
                |--------------------------------------------------------------------------
                */

                $customerStmt->send_long_data(1, '');


                /*
                |--------------------------------------------------------------------------
                | EXECUTE CUSTOMER
                |--------------------------------------------------------------------------
                */

                if (!$customerStmt->execute()) {

                    throw new Exception(
                        "Gagal menyimpan data customer: " .
                        $customerStmt->error
                    );
                }


                $customerStmt->close();


                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                $conn->commit();


                /*
                |--------------------------------------------------------------------------
                | BUAT SESSION
                |--------------------------------------------------------------------------
                */

                session_regenerate_id(true);

                $_SESSION['user_id']     = $userId;
                $_SESSION['username']    = $username;
                $_SESSION['nama']        = $nama;
                $_SESSION['email']       = $email;
                $_SESSION['telephone']   = $telephone;
                $_SESSION['role']        = 'customer';
                $_SESSION['user_status'] = 1;


                /*
                |--------------------------------------------------------------------------
                | REDIRECT
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: customer/langganan.php"
                );

                exit;


            } catch (Throwable $e) {

                /*
                |--------------------------------------------------------------------------
                | ROLLBACK
                |--------------------------------------------------------------------------
                */

                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                    // Abaikan jika transaction belum sempat dimulai.
                }


                /*
                |--------------------------------------------------------------------------
                | PESAN ERROR
                |--------------------------------------------------------------------------
                */

                $error = $e->getMessage();
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

    <title>Daftar Akun - WiFi Management</title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <!-- Custom CSS -->

    <link
        rel="stylesheet"
        href="assets/css/register.css"
    >

</head>


<body>


<!-- =========================================================
     BACKGROUND
========================================================= -->

<div class="background-circle circle-1"></div>
<div class="background-circle circle-2"></div>


<div class="register-wrapper">


    <div class="register-container">


        <!-- =================================================
             LEFT INFORMATION
        ================================================== -->

        <div class="register-info">


            <div class="wifi-icon">

                <img
                    src="logo-yesnet.png"
                    alt="WiFi Management"
                >

            </div>


            <h1>

                Selamat Datang di

                <span>
                    WiFi Management
                </span>

            </h1>


            <p>

                Buat akun pelanggan untuk mengelola
                layanan internet dengan lebih mudah,
                cepat, dan praktis.

            </p>


            <div class="feature-list">


                <!-- FEATURE 1 -->

                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="bi bi-wifi"></i>

                    </div>

                    <span>
                        Pantau penggunaan internet
                    </span>

                </div>


                <!-- FEATURE 2 -->

                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="bi bi-receipt"></i>

                    </div>

                    <span>
                        Cek tagihan dan pembayaran
                    </span>

                </div>


                <!-- FEATURE 3 -->

                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="bi bi-headset"></i>

                    </div>

                    <span>
                        Hubungi customer service
                    </span>

                </div>


                <!-- FEATURE 4 -->

                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="bi bi-lightning-charge"></i>

                    </div>

                    <span>
                        Pilih paket internet sesuai kebutuhan
                    </span>

                </div>


            </div>

        </div>


        <!-- =================================================
             REGISTER CARD
        ================================================== -->

        <div class="register-card">


            <div class="register-header">

                <h2>
                    Buat Akun Baru
                </h2>

                <p>

                    Daftarkan diri Anda untuk mulai menggunakan
                    layanan WiFi Management.

                </p>

            </div>


            <!-- =================================================
                 ERROR
            ================================================== -->

            <?php if ($error !== ''): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <i class="bi bi-exclamation-circle me-2"></i>

                    <?= e($error) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 FORM
            ================================================== -->

            <form
                method="POST"
                action=""
                autocomplete="off"
            >


                <!-- =================================================
                     DATA PRIBADI
                ================================================== -->

                <div class="section-title">

                    <span>
                        01
                    </span>

                    <div>

                        <strong>
                            Data Pribadi
                        </strong>

                        <small>
                            Masukkan informasi pribadi Anda
                        </small>

                    </div>

                </div>


                <!-- NAMA -->

                <div class="mb-3">

                    <label
                        for="nama"
                        class="form-label"
                    >
                        Nama Lengkap
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-person input-icon"
                        ></i>


                        <input
                            type="text"
                            id="nama"
                            name="nama"
                            class="form-control"
                            placeholder="Masukkan nama lengkap"
                            value="<?= e($nama) ?>"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >

                    </div>

                </div>


                <!-- EMAIL -->

                <div class="mb-3">

                    <label
                        for="email"
                        class="form-label"
                    >
                        Email
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-envelope input-icon"
                        ></i>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="nama@email.com"
                            value="<?= e($email) ?>"
                            maxlength="100"
                            autocomplete="email"
                            required
                        >

                    </div>

                </div>


                <!-- TELEPHONE -->

                <div class="mb-3">

                    <label
                        for="telephone"
                        class="form-label"
                    >
                        Nomor Telepon
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-telephone input-icon"
                        ></i>


                        <input
                            type="text"
                            id="telephone"
                            name="telephone"
                            class="form-control"
                            placeholder="08xxxxxxxxxx"
                            value="<?= e($telephone) ?>"
                            maxlength="20"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                        >

                    </div>

                </div>


                <!-- NIK -->

                <div class="mb-3">

                    <label
                        for="nik"
                        class="form-label"
                    >
                        NIK
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-card-text input-icon"
                        ></i>


                        <input
                            type="text"
                            id="nik"
                            name="nik"
                            class="form-control"
                            placeholder="Masukkan NIK"
                            value="<?= e($nik) ?>"
                            maxlength="20"
                            inputmode="numeric"
                            autocomplete="off"
                            required
                        >

                    </div>

                </div>


                <!-- ALAMAT -->

                <div class="mb-3">

                    <label
                        for="alamat"
                        class="form-label"
                    >
                        Alamat
                    </label>


                    <div class="input-wrapper textarea-wrapper">

                        <i
                            class="bi bi-geo-alt input-icon"
                        ></i>


                        <textarea
                            id="alamat"
                            name="alamat"
                            class="form-control"
                            placeholder="Masukkan alamat lengkap"
                            rows="4"
                            required
                        ><?= e($alamat) ?></textarea>

                    </div>

                </div>


                <!-- =================================================
                     DATA AKUN
                ================================================== -->

                <div class="section-title">

                    <span>
                        02
                    </span>

                    <div>

                        <strong>
                            Data Akun
                        </strong>

                        <small>
                            Buat username dan password untuk login
                        </small>

                    </div>

                </div>


                <!-- USERNAME -->

                <div class="mb-3">

                    <label
                        for="username"
                        class="form-label"
                    >
                        Username
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-person-badge input-icon"
                        ></i>


                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= e($username) ?>"
                            maxlength="50"
                            autocomplete="username"
                            required
                        >

                    </div>

                </div>


                <!-- PASSWORD -->

                <div class="mb-3">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Password
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-lock input-icon"
                        ></i>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword()"
                            aria-label="Tampilkan password"
                        >

                            <i
                                class="bi bi-eye"
                                id="passwordIcon"
                            ></i>

                        </button>

                    </div>


                    <div class="password-hint">

                        <i class="bi bi-info-circle"></i>

                        <span>
                            Password minimal 6 karakter.
                        </span>

                    </div>

                </div>


                <!-- =================================================
                     SUBMIT
                ================================================== -->

                <button
                    type="submit"
                    class="register-btn"
                >

                    <span>
                        Daftar Sekarang
                    </span>

                    <i class="bi bi-arrow-right"></i>

                </button>


            </form>


            <!-- =================================================
                 LOGIN
            ================================================== -->

            <div class="login-text">

                Sudah mempunyai akun?

                <a href="login.php">
                    Login di sini
                </a>

            </div>


            <!-- =================================================
                 SECURITY
            ================================================== -->

            <div class="security-text">

                <i class="bi bi-shield-check"></i>

                <span>
                    Data Anda akan disimpan dengan aman.
                </span>

            </div>


        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

function togglePassword() {

    const password = document.getElementById('password');
    const icon = document.getElementById('passwordIcon');

    if (!password || !icon) {
        return;
    }


    if (password.type === 'password') {

        password.type = 'text';

        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');

    } else {

        password.type = 'password';

        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');

    }

}

</script>


</body>

</html>
