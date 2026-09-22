<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";

date_default_timezone_set("Asia/Jakarta");


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
        "UTF-8"
    );
}


/*
|--------------------------------------------------------------------------
| CEK DATABASE
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| CEK DATABASE YANG AKTIF
|--------------------------------------------------------------------------
*/

$dbResult = $conn->query("SELECT DATABASE() AS db");

$dbRow = $dbResult->fetch_assoc();

$databaseAktif = $dbRow["db"] ?? "";

if ($databaseAktif !== "wifi_management") {

    die(
        "PHP sedang menggunakan database: <b>" .
        e($databaseAktif) .
        "</b><br><br>" .
        "Seharusnya: <b>wifi_management</b>"
    );
}


/*
|--------------------------------------------------------------------------
| CEK TABLE
|--------------------------------------------------------------------------
*/

function tableExists(
    mysqli $conn,
    string $table
): bool {

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = ?
    ");

    $stmt->bind_param(
        "s",
        $table
    );

    $stmt->execute();

    $stmt->bind_result($count);

    $stmt->fetch();

    $stmt->close();

    return ((int) $count > 0);
}


if (!tableExists($conn, "users")) {

    die(
        "Tabel <b>users</b> tidak ditemukan."
    );
}


if (!tableExists($conn, "customers")) {

    die(
        "Tabel <b>customers</b> tidak ditemukan."
    );
}


/*
|--------------------------------------------------------------------------
| CEK COLUMN
|--------------------------------------------------------------------------
*/

function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = ?
        AND column_name = ?
    ");

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $stmt->bind_result($count);

    $stmt->fetch();

    $stmt->close();

    return ((int) $count > 0);
}


/*
|--------------------------------------------------------------------------
| CEK COLUMN WAJIB
|--------------------------------------------------------------------------
*/

$requiredUsersColumns = [
    "id",
    "username",
    "email",
    "telephone",
    "password",
    "role",
    "status"
];

$requiredCustomerColumns = [
    "id",
    "user_id",
    "nama",
    "telephone",
    "email",
    "nik",
    "alamat",
    "status_langganan"
];


foreach ($requiredUsersColumns as $column) {

    if (!columnExists(
        $conn,
        "users",
        $column
    )) {

        die(
            "Column <b>users.$column</b> tidak ditemukan."
        );
    }
}


foreach ($requiredCustomerColumns as $column) {

    if (!columnExists(
        $conn,
        "customers",
        $column
    )) {

        die(
            "Column <b>customers.$column</b> tidak ditemukan."
        );
    }
}


/*
|--------------------------------------------------------------------------
| VARIABLE
|--------------------------------------------------------------------------
*/

$error = "";

$nama = "";
$username = "";
$email = "";
$telephone = "";
$nik = "";
$alamat = "";


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | INPUT
    |--------------------------------------------------------------------------
    */

    $nama = trim(
        $_POST["nama"] ?? ""
    );

    $username = trim(
        $_POST["username"] ?? ""
    );

    $email = trim(
        $_POST["email"] ?? ""
    );

    $telephone = trim(
        $_POST["telephone"] ?? ""
    );

    $password = $_POST["password"] ?? "";

    $passwordConfirm =
        $_POST["password_confirm"] ?? "";

    $nik = trim(
        $_POST["nik"] ?? ""
    );

    $alamat = trim(
        $_POST["alamat"] ?? ""
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if ($nama === "") {

        $error = "Nama lengkap wajib diisi.";

    } elseif (strlen($nama) < 3) {

        $error =
            "Nama lengkap minimal 3 karakter.";

    } elseif ($username === "") {

        $error =
            "Username wajib diisi.";

    } elseif (
        !preg_match(
            "/^[a-zA-Z0-9._-]{4,50}$/",
            $username
        )
    ) {

        $error =
            "Username tidak valid.";

    } elseif ($email === "") {

        $error =
            "Email wajib diisi.";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error =
            "Format email tidak valid.";

    } elseif ($telephone === "") {

        $error =
            "Nomor telephone wajib diisi.";

    } elseif (
        !preg_match(
            "/^[0-9+\-\s]{8,20}$/",
            $telephone
        )
    ) {

        $error =
            "Nomor telephone tidak valid.";

    } elseif ($password === "") {

        $error =
            "Password wajib diisi.";

    } elseif (strlen($password) < 6) {

        $error =
            "Password minimal 6 karakter.";

    } elseif (
        $password !== $passwordConfirm
    ) {

        $error =
            "Konfirmasi password tidak sama.";

    } elseif ($nik === "") {

        $error =
            "NIK wajib diisi.";

    } elseif (
        !preg_match(
            "/^[0-9]{10,20}$/",
            $nik
        )
    ) {

        $error =
            "NIK harus berupa 10 sampai 20 digit.";

    } elseif ($alamat === "") {

        $error =
            "Alamat wajib diisi.";
    }


    /*
    |--------------------------------------------------------------------------
    | CEK DUPLIKAT
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            /*
            |--------------------------------------------------------------------------
            | USERNAME
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

            $stmt->store_result();

            if ($stmt->num_rows > 0) {

                $error =
                    "Username sudah digunakan.";
            }

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | EMAIL
            |--------------------------------------------------------------------------
            */

            if ($error === "") {

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

                $stmt->store_result();

                if ($stmt->num_rows > 0) {

                    $error =
                        "Email sudah terdaftar.";
                }

                $stmt->close();
            }


            /*
            |--------------------------------------------------------------------------
            | NIK
            |--------------------------------------------------------------------------
            */

            if ($error === "") {

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

                $stmt->store_result();

                if ($stmt->num_rows > 0) {

                    $error =
                        "NIK sudah terdaftar.";
                }

                $stmt->close();
            }

        } catch (Throwable $e) {

            $error =
                "Gagal mengecek data:<br><br>" .
                e($e->getMessage());
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REGISTER
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            /*
            |--------------------------------------------------------------------------
            | START TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();


            /*
            |--------------------------------------------------------------------------
            | PASSWORD HASH
            |--------------------------------------------------------------------------
            */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            if (!$passwordHash) {

                throw new Exception(
                    "Password gagal diproses."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT USERS
            |--------------------------------------------------------------------------
            |
            | TIDAK MEMAKAI users.nama.
            |
            | Data nama tetap disimpan di customers.nama.
            |
            */

            $role = "customer";

            $status = 1;

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    username,
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
                    ?
                )
            ");

            $stmt->bind_param(
                "sssssi",
                $username,
                $email,
                $telephone,
                $passwordHash,
                $role,
                $status
            );

            $stmt->execute();

            $userId =
                (int) $conn->insert_id;

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | VALIDASI USER ID
            |--------------------------------------------------------------------------
            */

            if ($userId <= 0) {

                throw new Exception(
                    "Gagal mendapatkan ID user."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT CUSTOMERS
            |--------------------------------------------------------------------------
            */

            $paketId = null;

            $statusLangganan =
                "belum_berlangganan";


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
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->bind_param(
                "iissssss",
                $userId,
                $paketId,
                $nama,
                $telephone,
                $email,
                $nik,
                $alamat,
                $statusLangganan
            );

            $stmt->execute();

            $customerId =
                (int) $conn->insert_id;

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | VALIDASI CUSTOMER ID
            |--------------------------------------------------------------------------
            */

            if ($customerId <= 0) {

                throw new Exception(
                    "Gagal membuat data customer."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $conn->commit();


            /*
            |--------------------------------------------------------------------------
            | LOGIN OTOMATIS
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);


            $_SESSION["user_id"] =
                $userId;

            $_SESSION["customer_id"] =
                $customerId;

            $_SESSION["username"] =
                $username;

            $_SESSION["nama"] =
                $nama;

            $_SESSION["email"] =
                $email;

            $_SESSION["telephone"] =
                $telephone;

            $_SESSION["role"] =
                "customer";

            $_SESSION["user_status"] =
                1;

            $_SESSION["status_langganan"] =
                "belum_berlangganan";


            /*
            |--------------------------------------------------------------------------
            | REDIRECT KE LANGGANAN
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

                // Abaikan
            }


            /*
            |--------------------------------------------------------------------------
            | ERROR
            |--------------------------------------------------------------------------
            */

            $error =
                "<b>Registrasi gagal.</b><br><br>" .
                "<b>Pesan:</b><br>" .
                e($e->getMessage()) .
                "<br><br>" .
                "<b>File:</b><br>" .
                e($e->getFile()) .
                "<br><br>" .
                "<b>Line:</b> " .
                e($e->getLine()) .
                "<br><br>" .
                "<b>Database:</b> " .
                e($databaseAktif);
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

    <style>

        body {
            min-height: 100vh;
            margin: 0;
            background: #f3f7ff;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .register-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .register-card {
            width: 100%;
            max-width: 1050px;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow:
                0 20px 60px
                rgba(0, 0, 0, .10);
        }

        .register-left {
            min-height: 100%;
            padding: 50px 40px;
            background:
                linear-gradient(
                    145deg,
                    #0f4cdb,
                    #1769e8
                );
            color: white;
        }

        .register-left h1 {
            font-weight: 800;
            margin-bottom: 15px;
        }

        .register-left p {
            line-height: 1.7;
            color: rgba(255,255,255,.85);
        }

        .brand-icon {
            width: 65px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background:
                rgba(255,255,255,.15);
            font-size: 30px;
            margin-bottom: 25px;
        }

        .feature {
            display: flex;
            gap: 13px;
            margin-top: 25px;
        }

        .feature i {
            font-size: 20px;
        }

        .feature strong {
            display: block;
        }

        .feature span {
            display: block;
            color: rgba(255,255,255,.75);
            font-size: 14px;
            margin-top: 4px;
        }

        .register-right {
            padding: 45px;
        }

        .form-title {
            font-size: 28px;
            font-weight: 800;
        }

        .form-subtitle {
            color: #6b7280;
            margin-bottom: 25px;
        }

        .form-label {
            font-weight: 600;
        }

        .form-control,
        .input-group-text {
            min-height: 48px;
            border-color: #dbe2ea;
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        .input-group-text {
            background: #fff;
            color: #6b7280;
        }

        .btn-register {
            width: 100%;
            min-height: 50px;
            border: 0;
            border-radius: 12px;
            background: #0f4cdb;
            color: white;
            font-weight: 700;
        }

        .btn-register:hover {
            background: #0b3db4;
        }

        .login-link {
            margin-top: 20px;
            text-align: center;
            color: #6b7280;
        }

        .login-link a {
            color: #0f4cdb;
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 767px) {

            .register-left {
                display: none;
            }

            .register-right {
                padding: 30px 20px;
            }

        }

    </style>

</head>

<body>

<div class="register-wrapper">

    <div class="register-card">

        <div class="row g-0">

            <!-- LEFT -->

            <div class="col-lg-5">

                <div class="register-left">

                    <div class="brand-icon">
                        <i class="bi bi-wifi"></i>
                    </div>

                    <h1>
                        WiFi Management
                    </h1>

                    <p>
                        Buat akun customer dan pilih
                        paket WiFi yang sesuai kebutuhan.
                    </p>

                    <div class="feature">

                        <i class="bi bi-check-circle-fill"></i>

                        <div>

                            <strong>
                                Pilih Paket WiFi
                            </strong>

                            <span>
                                Pilih paket setelah berhasil
                                melakukan pendaftaran.
                            </span>

                        </div>

                    </div>

                    <div class="feature">

                        <i class="bi bi-credit-card"></i>

                        <div>

                            <strong>
                                Pembayaran Online
                            </strong>

                            <span>
                                Pembayaran dapat dilakukan
                                setelah memilih paket.
                            </span>

                        </div>

                    </div>

                    <div class="feature">

                        <i class="bi bi-headset"></i>

                        <div>

                            <strong>
                                Customer Service
                            </strong>

                            <span>
                                Dapatkan bantuan melalui
                                customer service.
                            </span>

                        </div>

                    </div>

                </div>

            </div>


            <!-- RIGHT -->

            <div class="col-lg-7">

                <div class="register-right">

                    <div class="form-title">
                        Buat Akun
                    </div>

                    <div class="form-subtitle">
                        Lengkapi data berikut untuk mendaftar.
                    </div>


                    <?php if ($error !== ""): ?>

                        <div class="alert alert-danger">

                            <i
                                class="bi bi-exclamation-triangle-fill me-2"
                            ></i>

                            <?= $error ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action=""
                        autocomplete="off"
                    >

                        <!-- NAMA -->

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="nama"
                            >
                                Nama Lengkap
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    <i class="bi bi-person"></i>
                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="nama"
                                    name="nama"
                                    value="<?= e($nama) ?>"
                                    maxlength="100"
                                    required
                                >

                            </div>

                        </div>


                        <div class="row">

                            <!-- USERNAME -->

                            <div class="col-md-6 mb-3">

                                <label
                                    class="form-label"
                                    for="username"
                                >
                                    Username
                                </label>

                                <div class="input-group">

                                    <span class="input-group-text">
                                        <i class="bi bi-person-badge"></i>
                                    </span>

                                    <input
                                        type="text"
                                        class="form-control"
                                        id="username"
                                        name="username"
                                        value="<?= e($username) ?>"
                                        maxlength="50"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- EMAIL -->

                            <div class="col-md-6 mb-3">

                                <label
                                    class="form-label"
                                    for="email"
                                >
                                    Email
                                </label>

                                <div class="input-group">

                                    <span class="input-group-text">
                                        <i class="bi bi-envelope"></i>
                                    </span>

                                    <input
                                        type="email"
                                        class="form-control"
                                        id="email"
                                        name="email"
                                        value="<?= e($email) ?>"
                                        maxlength="100"
                                        required
                                    >

                                </div>

                            </div>

                        </div>


                        <div class="row">

                            <!-- TELEPHONE -->

                            <div class="col-md-6 mb-3">

                                <label
                                    class="form-label"
                                    for="telephone"
                                >
                                    Nomor Telephone
                                </label>

                                <div class="input-group">

                                    <span class="input-group-text">
                                        <i class="bi bi-telephone"></i>
                                    </span>

                                    <input
                                        type="text"
                                        class="form-control"
                                        id="telephone"
                                        name="telephone"
                                        value="<?= e($telephone) ?>"
                                        maxlength="20"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- NIK -->

                            <div class="col-md-6 mb-3">

                                <label
                                    class="form-label"
                                    for="nik"
                                >
                                    NIK
                                </label>

                                <div class="input-group">

                                    <span class="input-group-text">
                                        <i class="bi bi-card-text"></i>
                                    </span>

                                    <input
                                        type="text"
                                        class="form-control"
                                        id="nik"
                                        name="nik"
                                        value="<?= e($nik) ?>"
                                        maxlength="20"
                                        inputmode="numeric"
                                        required
                                    >

                                </div>

                            </div>

                        </div>


                        <!-- PASSWORD -->

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="password"
                            >
                                Password
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    <i class="bi bi-lock"></i>
                                </span>

                                <input
                                    type="password"
                                    class="form-control"
                                    id="password"
                                    name="password"
                                    minlength="6"
                                    required
                                >

                                <button
                                    type="button"
                                    class="input-group-text"
                                    id="togglePassword"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>


                        <!-- CONFIRM -->

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="password_confirm"
                            >
                                Konfirmasi Password
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    <i class="bi bi-shield-lock"></i>
                                </span>

                                <input
                                    type="password"
                                    class="form-control"
                                    id="password_confirm"
                                    name="password_confirm"
                                    minlength="6"
                                    required
                                >

                                <button
                                    type="button"
                                    class="input-group-text"
                                    id="togglePasswordConfirm"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>


                        <!-- ALAMAT -->

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="alamat"
                            >
                                Alamat
                            </label>

                            <textarea
                                class="form-control"
                                id="alamat"
                                name="alamat"
                                required
                            ><?= e($alamat) ?></textarea>

                        </div>


                        <!-- AGREEMENT -->

                        <div class="form-check mb-4">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="agreement"
                                required
                            >

                            <label
                                class="form-check-label"
                                for="agreement"
                            >
                                Saya menyetujui data yang saya
                                masukkan digunakan untuk
                                pendaftaran.
                            </label>

                        </div>


                        <!-- SUBMIT -->

                        <button
                            type="submit"
                            class="btn-register"
                        >

                            <i
                                class="bi bi-person-plus-fill me-2"
                            ></i>

                            Daftar Sekarang

                        </button>

                    </form>


                    <div class="login-link">

                        Sudah punya akun?

                        <a href="login.php">
                            Login di sini
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| TOGGLE PASSWORD
|--------------------------------------------------------------------------
*/

function togglePassword(
    inputId,
    buttonId
) {

    const input =
        document.getElementById(inputId);

    const button =
        document.getElementById(buttonId);

    if (!input || !button) {
        return;
    }

    button.addEventListener(
        "click",
        function () {

            const icon =
                button.querySelector("i");

            if (
                input.type === "password"
            ) {

                input.type = "text";

                icon.classList.remove(
                    "bi-eye"
                );

                icon.classList.add(
                    "bi-eye-slash"
                );

            } else {

                input.type = "password";

                icon.classList.remove(
                    "bi-eye-slash"
                );

                icon.classList.add(
                    "bi-eye"
                );
            }

        }
    );
}


togglePassword(
    "password",
    "togglePassword"
);

togglePassword(
    "password_confirm",
    "togglePasswordConfirm"
);


/*
|--------------------------------------------------------------------------
| NIK
|--------------------------------------------------------------------------
*/

const nik =
    document.getElementById("nik");

if (nik) {

    nik.addEventListener(
        "input",
        function () {

            this.value =
                this.value.replace(
                    /[^0-9]/g,
                    ""
                );

        }
    );
}


/*
|--------------------------------------------------------------------------
| TELEPHONE
|--------------------------------------------------------------------------
*/

const telephone =
    document.getElementById(
        "telephone"
    );

if (telephone) {

    telephone.addEventListener(
        "input",
        function () {

            this.value =
                this.value.replace(
                    /[^0-9+\-\s]/g,
                    ""
                );

        }
    );
}


/*
|--------------------------------------------------------------------------
| PASSWORD CONFIRMATION
|--------------------------------------------------------------------------
*/

const form =
    document.querySelector("form");

if (form) {

    form.addEventListener(
        "submit",
        function (event) {

            const password =
                document.getElementById(
                    "password"
                ).value;

            const confirmation =
                document.getElementById(
                    "password_confirm"
                ).value;

            if (
                password !== confirmation
            ) {

                event.preventDefault();

                alert(
                    "Konfirmasi password tidak sama."
                );

            }

        }
    );
}

</script>

</body>

</html>
