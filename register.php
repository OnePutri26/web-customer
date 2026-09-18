<?php

session_start();

require_once "config/database.php";
echo "Database aktif: " . $conn->query("SELECT DATABASE()")->fetch_row()[0];

$error = "";

$nama      = "";
$username  = "";
$email     = "";
$telephone = "";
$nik       = "";
$alamat    = "";


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

    } elseif (strlen($password) < 6) {

        $error = "Password minimal 6 karakter.";

    } elseif (!preg_match('/^[0-9]+$/', $nik)) {

        $error = "NIK hanya boleh berisi angka.";

    } elseif (strlen($nik) < 10) {

        $error = "NIK tidak valid.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | CEK USERNAME
        |--------------------------------------------------------------------------
        */

        $checkUsername = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        if (!$checkUsername) {

            $error = "Gagal memeriksa username: " . $conn->error;

        } else {

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
        }


        /*
        |--------------------------------------------------------------------------
        | CEK EMAIL
        |--------------------------------------------------------------------------
        */

        if ($error === "") {

            $checkEmail = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            if (!$checkEmail) {

                $error = "Gagal memeriksa email: " . $conn->error;

            } else {

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
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CEK NIK
        |--------------------------------------------------------------------------
        */

        if ($error === "") {

            $checkNik = $conn->prepare("
                SELECT id
                FROM customers
                WHERE nik = ?
                LIMIT 1
            ");

            if (!$checkNik) {

                $error = "Gagal memeriksa NIK: " . $conn->error;

            } else {

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
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SIMPAN DATA
        |--------------------------------------------------------------------------
        */

        if ($error === "") {

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            /*
            |--------------------------------------------------------------------------
            | MULAI TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();

            try {

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

                if (!$userStmt) {

                    throw new Exception(
                        "Gagal menyiapkan data user: " .
                        $conn->error
                    );
                }


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

                $userId = $userStmt->insert_id;

                $userStmt->close();


                /*
                |--------------------------------------------------------------------------
                | CUSTOMER BELUM MEMILIKI PAKET
                |--------------------------------------------------------------------------
                |
                | NULL berarti customer belum memilih paket.
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

                if (!$customerStmt) {

                    throw new Exception(
                        "Gagal menyiapkan data customer: " .
                        $conn->error
                    );
                }


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

                $_SESSION['user_id']     = (int) $userId;
                $_SESSION['username']    = $username;
                $_SESSION['nama']        = $nama;
                $_SESSION['email']       = $email;
                $_SESSION['role']        = 'customer';
                $_SESSION['user_status'] = 1;


                /*
                |--------------------------------------------------------------------------
                | REDIRECT KE PILIH PAKET
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: customer/langganan.php"
                );

                exit;


            } catch (Exception $e) {

                /*
                |--------------------------------------------------------------------------
                | ROLLBACK
                |--------------------------------------------------------------------------
                */

                $conn->rollback();

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


<!-- BACKGROUND -->

<div class="background-circle circle-1"></div>
<div class="background-circle circle-2"></div>


<div class="register-wrapper">


    <div class="register-container">


        <!-- =====================================================
             LEFT INFORMATION
        ====================================================== -->

        <div class="register-info">


            <div class="wifi-icon">

                <img
                    src="logo-yesnet.png"
                    alt="WiFi Management"
                >

            </div>


            <h1>
                Selamat Datang di
                <span>WiFi Management</span>
            </h1>


            <p>
                Buat akun pelanggan untuk mengelola
                layanan internet dengan lebih mudah,
                cepat, dan praktis.
            </p>


            <div class="feature-list">


                <div class="feature-item">

                    <div class="feature-icon">
                        <i class="bi bi-wifi"></i>
                    </div>

                    <span>
                        Pantau penggunaan internet
                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">
                        <i class="bi bi-receipt"></i>
                    </div>

                    <span>
                        Cek tagihan dan pembayaran
                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">
                        <i class="bi bi-headset"></i>
                    </div>

                    <span>
                        Hubungi customer service
                    </span>

                </div>


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


        <!-- =====================================================
             REGISTER CARD
        ====================================================== -->

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


            <!-- ERROR -->

            <?php if ($error !== ""): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <i class="bi bi-exclamation-circle me-2"></i>

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 FORM
            ================================================== -->

            <form
                method="POST"
                action=""
            >


                <!-- =============================================
                     DATA PRIBADI
                ============================================== -->

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

                        <i class="bi bi-person input-icon"></i>

                        <input
                            type="text"
                            id="nama"
                            name="nama"
                            class="form-control"
                            placeholder="Masukkan nama lengkap"
                            value="<?= htmlspecialchars($nama) ?>"
                            maxlength="100"
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

                        <i class="bi bi-envelope input-icon"></i>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="nama@email.com"
                            value="<?= htmlspecialchars($email) ?>"
                            maxlength="100"
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

                        <i class="bi bi-telephone input-icon"></i>

                        <input
                            type="text"
                            id="telephone"
                            name="telephone"
                            class="form-control"
                            placeholder="08xxxxxxxxxx"
                            value="<?= htmlspecialchars($telephone) ?>"
                            maxlength="20"
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

                        <i class="bi bi-card-text input-icon"></i>

                        <input
                            type="text"
                            id="nik"
                            name="nik"
                            class="form-control"
                            placeholder="Masukkan NIK"
                            value="<?= htmlspecialchars($nik) ?>"
                            maxlength="20"
                            inputmode="numeric"
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

                        <i class="bi bi-geo-alt input-icon"></i>

                        <textarea
                            id="alamat"
                            name="alamat"
                            class="form-control"
                            placeholder="Masukkan alamat lengkap"
                            required
                        ><?= htmlspecialchars($alamat) ?></textarea>

                    </div>

                </div>


                <!-- =============================================
                     DATA AKUN
                ============================================== -->

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

                        <i class="bi bi-person-badge input-icon"></i>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= htmlspecialchars($username) ?>"
                            maxlength="50"
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

                        <i class="bi bi-lock input-icon"></i>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            minlength="6"
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


                <!-- SUBMIT -->

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


            <!-- LOGIN -->

            <div class="login-text">

                Sudah mempunyai akun?

                <a href="login.php">
                    Login di sini
                </a>

            </div>


            <!-- SECURITY -->

            <div class="security-text">

                <i class="bi bi-shield-check"></i>

                <span>
                    Data Anda akan disimpan dengan aman.
                </span>

            </div>


        </div>

    </div>

</div>


<script>

function togglePassword() {

    const password = document.getElementById('password');
    const icon = document.getElementById('passwordIcon');

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