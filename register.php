<?php

session_start();

require_once "config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| CEK DATABASE
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

try {
    $dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];

    if ($dbName !== "wifi_management") {
        die("Database aktif bukan wifi_management.");
    }
} catch (Exception $e) {
    die("Gagal memeriksa database.");
}


/*
|--------------------------------------------------------------------------
| VARIABLE
|--------------------------------------------------------------------------
*/

$error = "";
$success = "";

$nama       = "";
$username   = "";
$email      = "";
$telephone  = "";
$nik        = "";
$alamat     = "";


/*
|--------------------------------------------------------------------------
| ROLE
|--------------------------------------------------------------------------
|
| Default = customer
|
| Untuk admin:
| register.php?role=admin
|
*/

$requestedRole = $_GET['role'] ?? 'customer';

if ($requestedRole === 'admin') {
    $role = 'admin';
} else {
    $role = 'customer';
}


/*
|--------------------------------------------------------------------------
| PROSES REGISTER
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | AMBIL DATA
    |--------------------------------------------------------------------------
    */

    $nama       = trim($_POST['nama'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $telephone  = trim($_POST['telephone'] ?? '');
    $password   = $_POST['password'] ?? '';
    $nik        = trim($_POST['nik'] ?? '');
    $alamat     = trim($_POST['alamat'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | ROLE
    |--------------------------------------------------------------------------
    |
    | Jangan percaya role dari user.
    | Role tetap mengikuti URL halaman.
    |
    */

    $postRole = $_POST['role'] ?? 'customer';

    if (!in_array($postRole, ['customer', 'admin'], true)) {
        $postRole = 'customer';
    }

    $role = $postRole;


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if (
        $nama === '' ||
        $username === '' ||
        $email === '' ||
        $telephone === '' ||
        $password === '' ||
        $alamat === ''
    ) {
        $error = "Semua field wajib diisi.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI NAMA
    |--------------------------------------------------------------------------
    */

    elseif (mb_strlen($nama) < 3) {
        $error = "Nama minimal 3 karakter.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI USERNAME
    |--------------------------------------------------------------------------
    */

    elseif (
        mb_strlen($username) < 4 ||
        mb_strlen($username) > 50
    ) {
        $error = "Username harus 4 sampai 50 karakter.";
    }

    elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
        $error = "Username hanya boleh menggunakan huruf, angka, titik, dan underscore.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI EMAIL
    |--------------------------------------------------------------------------
    */

    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI PASSWORD
    |--------------------------------------------------------------------------
    */

    elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI NIK CUSTOMER
    |--------------------------------------------------------------------------
    |
    | Admin tidak wajib memiliki NIK.
    |
    */

    elseif ($role === 'customer') {

        if ($nik === '') {
            $error = "NIK wajib diisi.";
        }

        elseif (!preg_match('/^[0-9]+$/', $nik)) {
            $error = "NIK hanya boleh berisi angka.";
        }

        elseif (strlen($nik) < 10) {
            $error = "NIK minimal 10 digit.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | JIKA VALIDASI AMAN
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            /*
            |--------------------------------------------------------------------------
            | START TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();


            /*
            |--------------------------------------------------------------------------
            | CEK DUPLICATE USERNAME
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "s",
                $username
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $stmt->close();

                throw new Exception(
                    "Username sudah digunakan."
                );
            }

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | CEK DUPLICATE EMAIL
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "s",
                $email
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $stmt->close();

                throw new Exception(
                    "Email sudah digunakan."
                );
            }

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | CEK DUPLICATE NIK
            |--------------------------------------------------------------------------
            |
            | Hanya customer.
            |
            */

            if ($role === 'customer') {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM customers
                    WHERE nik = ?
                    LIMIT 1
                ");

                $stmt->bind_param(
                    "s",
                    $nik
                );

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();

                    throw new Exception(
                        "NIK sudah terdaftar."
                    );
                }

                $stmt->close();
            }


            /*
            |--------------------------------------------------------------------------
            | HASH PASSWORD
            |--------------------------------------------------------------------------
            */

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new Exception(
                    "Password gagal diproses."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT USERS
            |--------------------------------------------------------------------------
            */

            $status = 1;

            $stmt = $conn->prepare("
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
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "ssssssi",
                $username,
                $nama,
                $email,
                $telephone,
                $passwordHash,
                $role,
                $status
            );

            $stmt->execute();

            /*
            |--------------------------------------------------------------------------
            | AMBIL USER ID
            |--------------------------------------------------------------------------
            */

            $userId = (int) $conn->insert_id;

            $stmt->close();

            if ($userId <= 0) {
                throw new Exception(
                    "User ID tidak valid setelah proses register."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CUSTOMER
            |--------------------------------------------------------------------------
            |
            | Customer disimpan ke tabel customers.
            |
            */

            if ($role === 'customer') {

                $statusLangganan = "belum_berlangganan";

                $stmt = $conn->prepare("
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
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "issssss",
                    $userId,
                    $nama,
                    $telephone,
                    $email,
                    $nik,
                    $alamat,
                    $statusLangganan
                );

                $stmt->execute();

                $customerId = (int) $conn->insert_id;

                $stmt->close();

                if ($customerId <= 0) {
                    throw new Exception(
                        "Customer ID tidak valid."
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $conn->commit();


            /*
            |--------------------------------------------------------------------------
            | SESSION
            |--------------------------------------------------------------------------
            */

            $_SESSION['user_id']     = $userId;
            $_SESSION['username']    = $username;
            $_SESSION['nama']        = $nama;
            $_SESSION['email']       = $email;
            $_SESSION['telephone']   = $telephone;
            $_SESSION['role']        = $role;
            $_SESSION['user_status'] = $status;


            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            */

            if ($role === 'customer') {

                header(
                    "Location: customer/langganan.php"
                );

                exit;

            } else {

                header(
                    "Location: admin/index.php"
                );

                exit;
            }


        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK
            |--------------------------------------------------------------------------
            */

            try {
                $conn->rollback();
            } catch (Exception $rollbackError) {
                // Abaikan error rollback.
            }

            $error = $e->getMessage();
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

    <title>
        Register - WiFi Management
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="assets/css/register.css"
    >

</head>

<body>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-6 col-md-8">

            <div class="card shadow border-0">

                <div class="card-body p-4 p-md-5">

                    <div class="text-center mb-4">

                        <h2 class="fw-bold">
                            Selamat Datang di WiFi Management
                        </h2>

                        <p class="text-muted mb-0">

                            <?php if ($role === 'admin'): ?>

                                Buat akun administrator

                            <?php else: ?>

                                Buat akun pelanggan baru

                            <?php endif; ?>

                        </p>

                    </div>


                    <?php if ($error !== ''): ?>

                        <div
                            class="alert alert-danger"
                            role="alert"
                        >
                            <i class="bi bi-exclamation-triangle me-2"></i>

                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    <?php endif; ?>


                    <?php if ($success !== ''): ?>

                        <div
                            class="alert alert-success"
                            role="alert"
                        >
                            <?= htmlspecialchars(
                                $success,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action=""
                        autocomplete="off"
                    >

                        <!-- ROLE -->

                        <input
                            type="hidden"
                            name="role"
                            value="<?= htmlspecialchars(
                                $role,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >


                        <!-- NAMA -->

                        <div class="mb-3">

                            <label
                                for="nama"
                                class="form-label"
                            >
                                Nama Lengkap
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="nama"
                                name="nama"
                                value="<?= htmlspecialchars(
                                    $nama,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required
                                minlength="3"
                            >

                        </div>


                        <!-- USERNAME -->

                        <div class="mb-3">

                            <label
                                for="username"
                                class="form-label"
                            >
                                Username
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                value="<?= htmlspecialchars(
                                    $username,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required
                                minlength="4"
                                maxlength="50"
                            >

                            <div class="form-text">
                                Huruf, angka, titik, dan underscore.
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

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars(
                                    $email,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- TELEPHONE -->

                        <div class="mb-3">

                            <label
                                for="telephone"
                                class="form-label"
                            >
                                Nomor Telepon
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="telephone"
                                name="telephone"
                                value="<?= htmlspecialchars(
                                    $telephone,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- PASSWORD -->

                        <div class="mb-3">

                            <label
                                for="password"
                                class="form-label"
                            >
                                Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                required
                                minlength="6"
                            >

                            <div class="form-text">
                                Minimal 6 karakter.
                            </div>

                        </div>


                        <?php if ($role === 'customer'): ?>

                            <!-- NIK -->

                            <div class="mb-3">

                                <label
                                    for="nik"
                                    class="form-label"
                                >
                                    NIK
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="nik"
                                    name="nik"
                                    value="<?= htmlspecialchars(
                                        $nik,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    required
                                    inputmode="numeric"
                                >

                            </div>


                            <!-- ALAMAT -->

                            <div class="mb-3">

                                <label
                                    for="alamat"
                                    class="form-label"
                                >
                                    Alamat
                                </label>

                                <textarea
                                    class="form-control"
                                    id="alamat"
                                    name="alamat"
                                    rows="3"
                                    required
                                ><?= htmlspecialchars(
                                    $alamat,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></textarea>

                            </div>

                        <?php endif; ?>


                        <!-- SUBMIT -->

                        <div class="d-grid mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary btn-lg"
                            >

                                <?php if ($role === 'admin'): ?>

                                    <i class="bi bi-person-gear me-2"></i>
                                    Daftar Admin

                                <?php else: ?>

                                    <i class="bi bi-person-plus me-2"></i>
                                    Daftar sebagai Customer

                                <?php endif; ?>

                            </button>

                        </div>

                    </form>


                    <!-- LOGIN -->

                    <div class="text-center mt-4">

                        <span class="text-muted">
                            Sudah punya akun?
                        </span>

                        <a
                            href="login.php"
                            class="text-decoration-none fw-semibold"
                        >
                            Login
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>
