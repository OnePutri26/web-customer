<?php
declare(strict_types=1);

session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("Asia/Jakarta");

require_once __DIR__ . "/config/database.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

$conn->set_charset("utf8mb4");

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count > 0;
}

if (!tableExists($conn, "users") || !tableExists($conn, "customers")) {
    die("Tabel users atau customers tidak ditemukan.");
}

$error = "";
$nama = trim((string) ($_POST["nama"] ?? ""));
$username = trim((string) ($_POST["username"] ?? ""));
$email = trim((string) ($_POST["email"] ?? ""));
$telephone = trim((string) ($_POST["telephone"] ?? ""));
$password = (string) ($_POST["password"] ?? "");
$passwordConfirm = (string) ($_POST["password_confirm"] ?? "");
$nik = trim((string) ($_POST["nik"] ?? ""));
$alamat = trim((string) ($_POST["alamat"] ?? ""));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($nama === "" || mb_strlen($nama) < 3) {
        $error = "Nama lengkap minimal 3 karakter.";
    } elseif (!preg_match("/^[a-zA-Z0-9._-]{4,50}$/", $username)) {
        $error = "Username hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus dengan panjang 4-50 karakter.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } elseif (!preg_match("/^[0-9+\-\s]{8,20}$/", $telephone)) {
        $error = "Nomor telepon tidak valid.";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    } elseif ($password !== $passwordConfirm) {
        $error = "Konfirmasi password tidak sama.";
    } elseif (!preg_match("/^[0-9]{10,20}$/", $nik)) {
        $error = "NIK harus berupa 10 sampai 20 digit.";
    } elseif ($alamat === "") {
        $error = "Alamat wajib diisi.";
    }

    if ($error === "") {
        try {
            foreach ([
                ["users", "username", $username, "Username sudah digunakan."],
                ["users", "email", $email, "Email sudah terdaftar."],
                ["customers", "nik", $nik, "NIK sudah terdaftar."],
            ] as [$table, $column, $value, $message]) {
                $stmt = $conn->prepare("SELECT id FROM {$table} WHERE {$column} = ? LIMIT 1");
                $stmt->bind_param("s", $value);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $error = $message;
                    $stmt->close();
                    break;
                }
                $stmt->close();
            }
        } catch (Throwable $exception) {
            $error = "Gagal memeriksa data pendaftaran.";
        }
    }

    if ($error === "") {
        try {
            $conn->begin_transaction();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $role = "customer";
            $status = 1;

            if (columnExists($conn, "users", "nama")) {
                $stmt = $conn->prepare("INSERT INTO users (username, nama, email, telephone, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssi", $username, $nama, $email, $telephone, $passwordHash, $role, $status);
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, email, telephone, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssi", $username, $email, $telephone, $passwordHash, $role, $status);
            }
            $stmt->execute();
            $userId = (int) $conn->insert_id;
            $stmt->close();

            $statusLangganan = "belum_berlangganan";
            $stmt = $conn->prepare("INSERT INTO customers (user_id, paket_id, nama, telephone, email, nik, alamat, status_langganan) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $userId, $nama, $telephone, $email, $nik, $alamat, $statusLangganan);
            $stmt->execute();
            $customerId = (int) $conn->insert_id;
            $stmt->close();
            $conn->commit();

            session_regenerate_id(true);
            $_SESSION["user_id"] = $userId;
            $_SESSION["customer_id"] = $customerId;
            $_SESSION["username"] = $username;
            $_SESSION["nama"] = $nama;
            $_SESSION["email"] = $email;
            $_SESSION["telephone"] = $telephone;
            $_SESSION["role"] = $role;
            $_SESSION["user_status"] = $status;
            $_SESSION["status_langganan"] = $statusLangganan;
            header("Location: customer/langganan.php");
            exit;
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
            $error = "Registrasi gagal: " . $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - WiFi Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/register.css">
</head>
<body>
    <div class="background-circle circle-1"></div>
    <div class="background-circle circle-2"></div>
    <main class="register-wrapper">
        <section class="register-container">
            <div class="register-info">
                <div class="wifi-icon"><img src="logo-yesnet.png" alt="WiFi Management"></div>
                <h1>Internet Cepat.<br><span>Tanpa Batas.</span></h1>
                <p>Kelola layanan internet Anda dengan mudah, cepat, dan transparan.</p>
                <div class="feature-list">
                    <div class="feature-item"><div class="feature-icon"><i class="bi bi-lightning-charge-fill"></i></div><span>Kecepatan internet stabil</span></div>
                    <div class="feature-item"><div class="feature-icon"><i class="bi bi-shield-check"></i></div><span>Pembayaran aman dan mudah</span></div>
                    <div class="feature-item"><div class="feature-icon"><i class="bi bi-headset"></i></div><span>Dukungan pelanggan 24/7</span></div>
                </div>
            </div>
            <div class="register-card">
                <div class="register-content">
                    <header class="register-header"><h2>Buat Akun Baru</h2><p>Daftar untuk mulai menikmati layanan kami.</p></header>
                    <?php if ($error !== ""): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                    <form method="post">
                        <div class="section-title"><span>1</span><div><strong>Data Akun</strong><small>Informasi untuk login</small></div></div>
                        <div class="mb-3"><label class="form-label">Nama Lengkap</label><div class="input-wrapper"><i class="bi bi-person input-icon"></i><input class="form-control" name="nama" value="<?= e($nama) ?>" placeholder="Masukkan nama lengkap" required></div></div>
                        <div class="mb-3"><label class="form-label">Username</label><div class="input-wrapper"><i class="bi bi-at input-icon"></i><input class="form-control" name="username" value="<?= e($username) ?>" placeholder="Masukkan username" required></div></div>
                        <div class="mb-3"><label class="form-label">Email</label><div class="input-wrapper"><i class="bi bi-envelope input-icon"></i><input type="email" class="form-control" name="email" value="<?= e($email) ?>" placeholder="nama@email.com" required></div></div>
                        <div class="mb-3"><label class="form-label">Nomor Telepon</label><div class="input-wrapper"><i class="bi bi-telephone input-icon"></i><input class="form-control" name="telephone" value="<?= e($telephone) ?>" placeholder="08xxxxxxxxxx" required></div></div>
                        <div class="mb-3"><label class="form-label">Password</label><div class="input-wrapper"><i class="bi bi-lock input-icon"></i><input type="password" class="form-control" name="password" placeholder="Minimal 6 karakter" required></div></div>
                        <div class="mb-3"><label class="form-label">Konfirmasi Password</label><div class="input-wrapper"><i class="bi bi-lock-fill input-icon"></i><input type="password" class="form-control" name="password_confirm" placeholder="Ulangi password" required></div></div>
                        <div class="section-title"><span>2</span><div><strong>Data Pelanggan</strong><small>Lengkapi data layanan Anda</small></div></div>
                        <div class="mb-3"><label class="form-label">NIK</label><div class="input-wrapper"><i class="bi bi-card-text input-icon"></i><input class="form-control" name="nik" value="<?= e($nik) ?>" placeholder="10 sampai 20 digit" required></div></div>
                        <div class="mb-3"><label class="form-label">Alamat</label><div class="input-wrapper textarea-wrapper"><i class="bi bi-geo-alt input-icon"></i><textarea class="form-control" name="alamat" placeholder="Masukkan alamat lengkap" required><?= e($alamat) ?></textarea></div></div>
                        <button class="register-btn" type="submit">Daftar Sekarang <i class="bi bi-arrow-right"></i></button>
                    </form>
                    <div class="login-text">Sudah punya akun? <a href="login.php">Masuk sekarang</a></div>
                    <div class="security-text"><i class="bi bi-shield-check"></i> Data Anda terlindungi dengan aman</div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
