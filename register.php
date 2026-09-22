<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/auth.php";

date_default_timezone_set("Asia/Jakarta");

/* =====================================================
   HELPER
===================================================== */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/* =====================================================
   DATABASE CHECK
===================================================== */

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

try {
    $databaseResult = $conn->query("SELECT DATABASE() AS db_name");
    $databaseRow = $databaseResult->fetch_assoc();

    if (($databaseRow["db_name"] ?? "") !== "wifi_management") {
        die("Database aktif bukan wifi_management.");
    }
} catch (Throwable $e) {
    die("Database tidak dapat diperiksa.");
}

/* =====================================================
   VARIABLE
===================================================== */

$error = "";

$nama = "";
$username = "";
$email = "";
$telephone = "";
$nik = "";
$alamat = "";

$password = "";
$passwordConfirm = "";

/* =====================================================
   PROCESS REGISTER
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nama = trim($_POST["nama"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $telephone = trim($_POST["telephone"] ?? "");
    $password = $_POST["password"] ?? "";
    $passwordConfirm = $_POST["password_confirm"] ?? "";
    $nik = trim($_POST["nik"] ?? "");
    $alamat = trim($_POST["alamat"] ?? "");

    /* =================================================
       VALIDATION
    ================================================= */

    if (
        $nama === "" ||
        $username === "" ||
        $email === "" ||
        $telephone === "" ||
        $password === "" ||
        $passwordConfirm === "" ||
        $nik === "" ||
        $alamat === ""
    ) {
        $error = "Semua field wajib diisi.";
    }

    elseif (mb_strlen($nama) < 3) {
        $error = "Nama minimal 3 karakter.";
    }

    elseif (
        mb_strlen($username) < 4 ||
        mb_strlen($username) > 50
    ) {
        $error = "Username harus 4-50 karakter.";
    }

    elseif (!preg_match('/^[A-Za-z0-9._]+$/', $username)) {
        $error = "Username hanya boleh menggunakan huruf, angka, titik dan underscore.";
    }

    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    }

    elseif (mb_strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    }

    elseif ($password !== $passwordConfirm) {
        $error = "Konfirmasi password tidak sesuai.";
    }

    elseif (!preg_match('/^[0-9]+$/', $nik)) {
        $error = "NIK hanya boleh berisi angka.";
    }

    elseif (mb_strlen($nik) < 10) {
        $error = "NIK minimal 10 digit.";
    }

    /* =================================================
       CHECK DUPLICATE
    ================================================= */

    if ($error === "") {

        try {

            /* Username */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $error = "Username sudah digunakan.";
            }

            $stmt->close();


            /* Email */

            if ($error === "") {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $error = "Email sudah terdaftar.";
                }

                $stmt->close();
            }


            /* NIK */

            if ($error === "") {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM customers
                    WHERE nik = ?
                    LIMIT 1
                ");

                $stmt->bind_param("s", $nik);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $error = "NIK sudah terdaftar.";
                }

                $stmt->close();
            }


            /* =================================================
               INSERT DATA
            ================================================= */

            if ($error === "") {

                $conn->begin_transaction();

                try {

                    $hashedPassword = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    /*
                     * USERS
                     */

                    $stmtUser = $conn->prepare("
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
                        VALUES (?, ?, ?, ?, ?, 'customer', 1)
                    ");

                    $stmtUser->bind_param(
                        "sssss",
                        $username,
                        $nama,
                        $email,
                        $telephone,
                        $hashedPassword
                    );

                    $stmtUser->execute();

                    $userId = (int) $conn->insert_id;

                    $stmtUser->close();


                    /*
                     * CUSTOMERS
                     */

                    $paketId = null;
                    $statusLangganan = "belum_berlangganan";

                    $stmtCustomer = $conn->prepare("
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
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmtCustomer->bind_param(
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

                    $stmtCustomer->execute();

                    $customerId = (int) $conn->insert_id;

                    $stmtCustomer->close();


                    /*
                     * COMMIT
                     */

                    $conn->commit();


                    /*
                     * SESSION
                     */

                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $userId;
                    $_SESSION["customer_id"] = $customerId;
                    $_SESSION["username"] = $username;
                    $_SESSION["nama"] = $nama;
                    $_SESSION["email"] = $email;
                    $_SESSION["telephone"] = $telephone;
                    $_SESSION["role"] = "customer";
                    $_SESSION["user_status"] = 1;
                    $_SESSION["status_langganan"] = "belum_berlangganan";


                    /*
                     * REDIRECT KE COVERAGE
                     */

                    header("Location: customer/coverage.php");
                    exit;

                } catch (Throwable $e) {

                    $conn->rollback();

                    $error = "Registrasi gagal. Silakan coba kembali.";
                }
            }

        } catch (Throwable $e) {

            $error = "Terjadi kesalahan saat memproses registrasi.";
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

    <title>Register - WiFi Management</title>

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
        href="assets/css/register.css"
    >

</head>

<body>

<div class="register-wrapper">

    <div class="register-card">

        <div class="register-logo">

            <img
                src="logo-yesnet.png"
                alt="YESNET"
            >

        </div>

        <div class="register-header">

            <h1>Selamat Datang di WiFi Management</h1>

            <p>
                Buat akun untuk berlangganan layanan internet
                YESNET.
            </p>

        </div>


        <?php if ($error !== ""): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-circle-fill"></i>

                <?= e($error) ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            action=""
            autocomplete="off"
        >

            <div class="row">

                <!-- NAMA -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Nama Lengkap
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>

                        <input
                            type="text"
                            name="nama"
                            class="form-control"
                            placeholder="Masukkan nama lengkap"
                            value="<?= e($nama) ?>"
                            required
                        >

                    </div>

                </div>


                <!-- USERNAME -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Username
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-person-badge"></i>
                        </span>

                        <input
                            type="text"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= e($username) ?>"
                            required
                        >

                    </div>

                </div>


                <!-- EMAIL -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Email
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="contoh@email.com"
                            value="<?= e($email) ?>"
                            required
                        >

                    </div>

                </div>


                <!-- TELEPHONE -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Nomor Telepon
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-telephone"></i>
                        </span>

                        <input
                            type="text"
                            name="telephone"
                            id="telephone"
                            class="form-control"
                            placeholder="08xxxxxxxxxx"
                            value="<?= e($telephone) ?>"
                            inputmode="numeric"
                            required
                        >

                    </div>

                </div>


                <!-- PASSWORD -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            placeholder="Minimal 6 karakter"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="togglePassword"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>


                <!-- CONFIRM PASSWORD -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Konfirmasi Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock-fill"></i>
                        </span>

                        <input
                            type="password"
                            name="password_confirm"
                            id="passwordConfirm"
                            class="form-control"
                            placeholder="Ulangi password"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="togglePasswordConfirm"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>


                <!-- NIK -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        NIK
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-card-text"></i>
                        </span>

                        <input
                            type="text"
                            name="nik"
                            id="nik"
                            class="form-control"
                            placeholder="Masukkan NIK"
                            value="<?= e($nik) ?>"
                            inputmode="numeric"
                            required
                        >

                    </div>

                </div>


                <!-- ALAMAT -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Alamat
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-geo-alt"></i>
                        </span>

                        <textarea
                            name="alamat"
                            class="form-control"
                            rows="1"
                            placeholder="Masukkan alamat lengkap"
                            required
                        ><?= e($alamat) ?></textarea>

                    </div>

                </div>

            </div>


            <button
                type="submit"
                class="btn-register"
            >
                <i class="bi bi-person-plus-fill"></i>
                Daftar Sekarang
            </button>


            <div class="register-footer">

                <span>
                    Sudah punya akun?
                </span>

                <a href="login.php">
                    Login di sini
                </a>

            </div>

        </form>

    </div>

</div>


<script>

function togglePasswordField(buttonId, inputId)
{
    const button = document.getElementById(buttonId);
    const input = document.getElementById(inputId);

    button.addEventListener("click", function ()
    {
        if (input.type === "password")
        {
            input.type = "text";

            this.innerHTML =
                '<i class="bi bi-eye-slash"></i>';
        }
        else
        {
            input.type = "password";

            this.innerHTML =
                '<i class="bi bi-eye"></i>';
        }
    });
}

togglePasswordField(
    "togglePassword",
    "password"
);

togglePasswordField(
    "togglePasswordConfirm",
    "passwordConfirm"
);


/* NIK */

document.getElementById("nik")
    .addEventListener("input", function ()
    {
        this.value = this.value.replace(/\D/g, "");
    });


/* TELEPHONE */

document.getElementById("telephone")
    .addEventListener("input", function ()
    {
        this.value = this.value.replace(/\D/g, "");
    });

</script>

</body>
</html>