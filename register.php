<?php

declare(strict_types=1);

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

date_default_timezone_set("Asia/Jakarta");

require_once __DIR__ . "/config/database.php";


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
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

$dbResult = $conn->query("SELECT DATABASE()");
$databaseAktif = (string) ($dbResult->fetch_row()[0] ?? "");

if ($databaseAktif !== "wifi_management") {
    die(
        "Konfigurasi database salah.<br><br>" .
        "Database aktif: <b>" . e($databaseAktif) . "</b><br>" .
        "Seharusnya: <b>wifi_management</b>"
    );
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_register"])) {
    $_SESSION["csrf_register"] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| VARIABEL FORM
|--------------------------------------------------------------------------
| $role dan $success WAJIB didefinisikan di sini karena dipakai di HTML.
| Halaman ini khusus pendaftaran pelanggan, jadi role selalu "customer"
| dan tidak pernah diambil dari input pengguna.
|--------------------------------------------------------------------------
*/

$error   = "";
$success = "";
$role    = "customer";

$nama            = "";
$username        = "";
$email           = "";
$telephone       = "";
$nik             = "";
$alamat          = "";
$password        = "";
$passwordConfirm = "";

$coveragePackageId = filter_var(
    $_GET["paket_id"] ?? $_POST["paket_id"] ?? 0,
    FILTER_VALIDATE_INT
) ?: 0;

if ($coveragePackageId > 0 && !empty($_SESSION["flow"]["tersedia"])) {

    $packageStmt = $conn->prepare("
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
    $packageStmt->bind_param("i", $coveragePackageId);
    $packageStmt->execute();
    $selectedPackage = $packageStmt->get_result()->fetch_assoc();
    $packageStmt->close();

    if ($selectedPackage) {
        $_SESSION["flow"]["paket_id"] = $coveragePackageId;
        unset($_SESSION["flow"]["verified"]);
    } else {
        $coveragePackageId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| PROSES REGISTRASI
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* ---------- Ambil data form ---------- */

    $nama            = trim((string) ($_POST["nama"] ?? ""));
    $username        = trim((string) ($_POST["username"] ?? ""));
    $email           = trim((string) ($_POST["email"] ?? ""));
    $telephone       = trim((string) ($_POST["telephone"] ?? ""));
    $password        = (string) ($_POST["password"] ?? "");
    $passwordConfirm = (string) ($_POST["password_confirm"] ?? "");
    $nik             = trim((string) ($_POST["nik"] ?? ""));
    $alamat          = trim((string) ($_POST["alamat"] ?? ""));

    $csrfPost = (string) ($_POST["csrf"] ?? "");


    /* ---------- Validasi ---------- */

    if (!hash_equals($_SESSION["csrf_register"], $csrfPost)) {

        $error = "Sesi formulir kedaluwarsa. Silakan muat ulang halaman dan coba lagi.";

    } elseif ($nama === "") {

        $error = "Nama lengkap wajib diisi.";

    } elseif (preg_match_all('/./us', $nama) < 3) {

        $error = "Nama lengkap minimal 3 karakter.";

    } elseif ($username === "") {

        $error = "Username wajib diisi.";

    } elseif (!preg_match("/^[a-zA-Z0-9._-]{4,50}$/", $username)) {

        $error =
            "Username hanya boleh berisi huruf, angka, " .
            "titik, garis bawah, atau tanda minus " .
            "dengan panjang 4-50 karakter.";

    } elseif ($email === "") {

        $error = "Email wajib diisi.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Format email tidak valid.";

    } elseif ($telephone === "") {

        $error = "Nomor telepon wajib diisi.";

    } elseif (!preg_match("/^[0-9+\-\s]{8,20}$/", $telephone)) {

        $error = "Nomor telepon tidak valid.";

    } elseif ($password === "") {

        $error = "Password wajib diisi.";

    } elseif (strlen($password) < 6) {

        $error = "Password minimal 6 karakter.";

    } elseif ($password !== $passwordConfirm) {

        $error = "Konfirmasi password tidak sama.";

    } elseif ($nik === "") {

        $error = "NIK wajib diisi.";

    } elseif (!preg_match("/^[0-9]{10,20}$/", $nik)) {

        $error = "NIK harus berupa 10 sampai 20 digit.";

    } elseif ($alamat === "") {

        $error = "Alamat wajib diisi.";
    }


    /* ---------- Cek data duplikat ---------- */

    if ($error === "") {

        try {

            // Username
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = "Username sudah digunakan.";
            }
            $stmt->close();

            // Email
            if ($error === "") {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $error = "Email sudah terdaftar.";
                }
                $stmt->close();
            }

            // NIK
            if ($error === "") {
                $stmt = $conn->prepare("SELECT id FROM customers WHERE nik = ? LIMIT 1");
                $stmt->bind_param("s", $nik);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $error = "NIK sudah terdaftar.";
                }
                $stmt->close();
            }

        } catch (Throwable $ex) {

            error_log("[register] cek duplikat: " . $ex->getMessage());
            $error = "Gagal memeriksa data pendaftaran.";
        }
    }


    /* ---------- Simpan data ---------- */

    if ($error === "") {

        try {

            $conn->begin_transaction();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // $role sudah "customer" dari bagian atas file.
            $status = 1;


            // INSERT users
            $userStmt = $conn->prepare("
                INSERT INTO users
                    (username, nama, email, telephone, password, role, status)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)
            ");

            $userStmt->bind_param(
                "ssssssi",
                $username,
                $nama,
                $email,
                $telephone,
                $passwordHash,
                $role,
                $status
            );

            $userStmt->execute();
            $userId = (int) $conn->insert_id;
            $userStmt->close();

            if ($userId <= 0) {
                throw new RuntimeException("Gagal mendapatkan ID user.");
            }


            // INSERT customers (paket_id NULL: belum memilih paket)
            $statusLangganan = "belum_berlangganan";

            $customerStmt = $conn->prepare("
                INSERT INTO customers
                    (user_id, paket_id, nama, telephone, email, nik, alamat, status_langganan)
                VALUES
                    (?, NULL, ?, ?, ?, ?, ?, ?)
            ");

            $customerStmt->bind_param(
                "issssss",
                $userId,
                $nama,
                $telephone,
                $email,
                $nik,
                $alamat,
                $statusLangganan
            );

            $customerStmt->execute();
            $customerId = (int) $conn->insert_id;
            $customerStmt->close();

            if ($customerId <= 0) {
                throw new RuntimeException("Gagal membuat data customer.");
            }


            $conn->commit();


            /* ---------- Login otomatis ---------- */

            session_regenerate_id(true);

            $_SESSION["user_id"]          = $userId;
            $_SESSION["customer_id"]      = $customerId;
            $_SESSION["username"]         = $username;
            $_SESSION["nama"]             = $nama;
            $_SESSION["email"]            = $email;
            $_SESSION["telephone"]        = $telephone;
            $_SESSION["role"]             = "customer";
            $_SESSION["user_status"]      = 1;
            $_SESSION["status_langganan"] = $statusLangganan;

            unset($_SESSION["csrf_register"]);

            $nextPage = ($coveragePackageId > 0 && !empty($_SESSION["flow"]["tersedia"]))
                ? "verifikasi.php"
                : "coverage.php";

            header("Location: " . $nextPage, true, 302);
            exit;

        } catch (Throwable $ex) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Abaikan jika rollback gagal.
            }

            // Detail teknis hanya masuk log server, tidak ditampilkan ke pengguna.
            error_log("[register] simpan: " . $ex->getMessage());

            // Error 1062 = duplicate entry (UNIQUE index), misalnya akibat
            // dua pendaftar bersamaan yang lolos pengecekan di atas.
            if ($ex instanceof mysqli_sql_exception && (int) $ex->getCode() === 1062) {
                $error = "Username, email, atau NIK sudah terdaftar.";
            } else {
                $error = "Registrasi gagal. Silakan coba lagi.";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register - WiFi Management</title>

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

    <!-- Register CSS -->
    <link rel="stylesheet" href="assets/css/register.css">
</head>

<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">

            <div class="card shadow border-0">
                <div class="card-body p-4 p-md-5">

                    <!-- HEADER -->
                    <div class="text-center mb-4">
                        <h2 class="fw-bold">Selamat Datang di WiFi Management</h2>
                        <p class="text-muted mb-0">Buat akun pelanggan baru</p>
                    </div>

                    <!-- ERROR -->
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <!-- SUCCESS -->
                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="bi bi-check-circle me-2"></i>
                            <?= e($success) ?>
                        </div>
                    <?php endif; ?>

                    <!-- FORM -->
                    <form method="POST" action="" autocomplete="off">

                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_register']) ?>">

                        <?php if ($coveragePackageId > 0): ?>
                            <input type="hidden" name="paket_id" value="<?= (int) $coveragePackageId ?>">
                        <?php endif; ?>

                        <!-- NAMA -->
                        <div class="mb-3">
                            <label for="nama" class="form-label">Nama Lengkap</label>
                            <input
                                type="text"
                                class="form-control"
                                id="nama"
                                name="nama"
                                value="<?= e($nama) ?>"
                                required
                                minlength="3"
                                autocomplete="name"
                            >
                        </div>

                        <!-- USERNAME -->
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                value="<?= e($username) ?>"
                                required
                                minlength="4"
                                maxlength="50"
                                pattern="[a-zA-Z0-9._\-]{4,50}"
                                autocomplete="username"
                            >
                            <div class="form-text">
                                Huruf, angka, titik, garis bawah, dan minus (4-50 karakter).
                            </div>
                        </div>

                        <!-- EMAIL -->
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                value="<?= e($email) ?>"
                                required
                                autocomplete="email"
                            >
                        </div>

                        <!-- TELEPHONE -->
                        <div class="mb-3">
                            <label for="telephone" class="form-label">Nomor Telepon</label>
                            <input
                                type="text"
                                class="form-control"
                                id="telephone"
                                name="telephone"
                                value="<?= e($telephone) ?>"
                                required
                                autocomplete="tel"
                                inputmode="tel"
                            >
                            <div class="form-text">Contoh: 081234567890</div>
                        </div>

                        <!-- PASSWORD -->
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                required
                                minlength="6"
                                autocomplete="new-password"
                            >
                            <div class="form-text">Minimal 6 karakter.</div>
                        </div>

                        <!-- KONFIRMASI PASSWORD -->
                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Konfirmasi Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="password_confirm"
                                name="password_confirm"
                                required
                                minlength="6"
                                autocomplete="new-password"
                            >
                        </div>

                        <!-- NIK -->
                        <div class="mb-3">
                            <label for="nik" class="form-label">NIK</label>
                            <input
                                type="text"
                                class="form-control"
                                id="nik"
                                name="nik"
                                value="<?= e($nik) ?>"
                                required
                                minlength="10"
                                maxlength="20"
                                pattern="[0-9]{10,20}"
                                inputmode="numeric"
                            >
                            <div class="form-text">NIK hanya boleh berisi angka (10-20 digit).</div>
                        </div>

                        <!-- ALAMAT -->
                        <div class="mb-3">
                            <label for="alamat" class="form-label">Alamat</label>
                            <textarea
                                class="form-control"
                                id="alamat"
                                name="alamat"
                                rows="3"
                                required
                            ><?= e($alamat) ?></textarea>
                        </div>

                        <!-- BUTTON -->
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-person-plus me-2"></i>
                                Daftar sebagai Customer
                            </button>
                        </div>

                    </form>

                    <!-- LOGIN -->
                    <div class="text-center mt-4">
                        <span class="text-muted">Sudah punya akun?</span>
                        <a href="login.php" class="text-decoration-none fw-semibold">Login</a>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>