
<?php

session_start();

date_default_timezone_set('Asia/Jakarta');

/*
|--------------------------------------------------------------------------
| ERROR HANDLING
|--------------------------------------------------------------------------
| Error dicatat ke log server, tidak ditampilkan kepada pengunjung.
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('[REGISTER] Koneksi database tidak ditemukan.');
    http_response_code(500);
    exit('Terjadi kesalahan koneksi database.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectRegister($url)
{
    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| SESSION DAN CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_register'])) {
    $_SESSION['csrf_register'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| FORM DEFAULT
|--------------------------------------------------------------------------
*/

$error = '';

$nama = '';
$username = '';
$email = '';
$telephone = '';
$nik = '';
$alamat = '';

$paketId = isset($_SESSION['flow']['paket_id'])
    ? (int)$_SESSION['flow']['paket_id']
    : 0;

/*
|--------------------------------------------------------------------------
| CEK PAKET DARI SESSION
|--------------------------------------------------------------------------
*/

if ($paketId > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM paket_wifi
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param('i', $paketId);
        $stmt->execute();

        $stmt->bind_result($foundPackageId);

        if (!$stmt->fetch()) {
            $paketId = 0;
        }

        $stmt->close();

    } catch (Throwable $ex) {
        error_log('[REGISTER] Cek paket: ' . $ex->getMessage());
        $paketId = 0;
    }
}

/*
|--------------------------------------------------------------------------
| PROSES REGISTRASI
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');

    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    $nik = trim($_POST['nik'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');

    $csrf = $_POST['csrf'] ?? '';

    $paketId = isset($_POST['paket_id'])
        ? (int)$_POST['paket_id']
        : $paketId;

    /*
    |--------------------------------------------------------------------------
    | VALIDASI CSRF
    |--------------------------------------------------------------------------
    */

    if (
        empty($csrf) ||
        !hash_equals($_SESSION['csrf_register'], $csrf)
    ) {
        $error = 'Sesi pendaftaran tidak valid. Silakan muat ulang halaman.';

    } elseif ($nama === '' || strlen($nama) < 3) {
        $error = 'Nama lengkap minimal 3 karakter.';

    } elseif (!preg_match('/^[a-zA-Z0-9._-]{4,50}$/', $username)) {
        $error = 'Username harus 4-50 karakter dan hanya menggunakan huruf, angka, titik, minus, atau garis bawah.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';

    } elseif (!preg_match('/^[0-9+\-\s]{8,20}$/', $telephone)) {
        $error = 'Nomor telepon tidak valid.';

    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';

    } elseif ($password !== $passwordConfirm) {
        $error = 'Konfirmasi password tidak sama.';

    } elseif (!preg_match('/^[0-9]{10,20}$/', $nik)) {
        $error = 'NIK harus berupa 10 sampai 20 digit angka.';

    } elseif ($alamat === '') {
        $error = 'Alamat lengkap wajib diisi.';
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI PAKET
    |--------------------------------------------------------------------------
    */

    if ($error === '' && $paketId > 0) {

        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM paket_wifi
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param('i', $paketId);
            $stmt->execute();
            $stmt->bind_result($validPackageId);

            if (!$stmt->fetch()) {
                $paketId = 0;
            }

            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[REGISTER] Validasi paket: ' . $ex->getMessage());
            $error = 'Paket yang dipilih tidak dapat diverifikasi.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CEK USERNAME
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stmt->bind_result($duplicateId);

            if ($stmt->fetch()) {
                $error = 'Username sudah digunakan.';
            }

            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[REGISTER] Cek username: ' . $ex->getMessage());
            $error = 'Gagal memeriksa username.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CEK EMAIL
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($duplicateId);

            if ($stmt->fetch()) {
                $error = 'Email sudah terdaftar.';
            }

            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[REGISTER] Cek email: ' . $ex->getMessage());
            $error = 'Gagal memeriksa email.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CEK NIK
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM customers
                WHERE nik = ?
                LIMIT 1
            ");

            $stmt->bind_param('s', $nik);
            $stmt->execute();
            $stmt->bind_result($duplicateId);

            if ($stmt->fetch()) {
                $error = 'NIK sudah terdaftar.';
            }

            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[REGISTER] Cek NIK: ' . $ex->getMessage());
            $error = 'Gagal memeriksa NIK.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SIMPAN REGISTRASI
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $conn->begin_transaction();

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $role = 'customer';
            $status = 1;

            /*
            |--------------------------------------------------------------------------
            | INSERT USERS
            |--------------------------------------------------------------------------
            */

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
                'ssssssi',
                $username,
                $nama,
                $email,
                $telephone,
                $passwordHash,
                $role,
                $status
            );

            $stmt->execute();

            $userId = (int)$conn->insert_id;

            $stmt->close();

            if ($userId <= 0) {
                throw new Exception('Gagal mendapatkan ID user.');
            }

            /*
            |--------------------------------------------------------------------------
            | INSERT CUSTOMERS
            |--------------------------------------------------------------------------
            */

            $statusLangganan = 'belum_berlangganan';

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
                'issssss',
                $userId,
                $nama,
                $telephone,
                $email,
                $nik,
                $alamat,
                $statusLangganan
            );

            $stmt->execute();

            $customerId = (int)$conn->insert_id;

            $stmt->close();

            if ($customerId <= 0) {
                throw new Exception('Gagal mendapatkan ID customer.');
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

            $_SESSION['user_id'] = $userId;
            $_SESSION['customer_id'] = $customerId;

            $_SESSION['username'] = $username;
            $_SESSION['nama'] = $nama;
            $_SESSION['email'] = $email;
            $_SESSION['telephone'] = $telephone;

            $_SESSION['role'] = 'customer';
            $_SESSION['user_status'] = 1;
            $_SESSION['status_langganan'] = $statusLangganan;

            unset($_SESSION['csrf_register']);

            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            */

            $coverageTersedia = !empty($_SESSION['flow']['tersedia']);

            if ($paketId > 0 && $coverageTersedia) {
                redirectRegister('verifikasi.php');
            }

            redirectRegister('coverage.php');

        } catch (Throwable $ex) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('[REGISTER] Rollback: ' . $rollbackError->getMessage());
            }

            error_log('[REGISTER] Simpan data: ' . $ex->getMessage());

            if (
                $ex instanceof mysqli_sql_exception &&
                (int)$ex->getCode() === 1062
            ) {
                $error = 'Username, email, atau NIK sudah terdaftar.';
            } else {
                $error = 'Pendaftaran gagal disimpan. Silakan periksa data dan coba kembali.';
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

<title>Daftar Akun | YESNET</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    rel="stylesheet"
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
    rel="stylesheet"
>

<style>

:root {
    --primary: #1555a0;
    --primary-dark: #103c70;
    --text-dark: #172b46;
    --muted: #8492a6;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    font-family: 'Inter', sans-serif;
    background: #f3f7fc;
    color: var(--text-dark);
    overflow-x: hidden;
}

/* BACKGROUND */

.page-background {
    position: fixed;
    inset: 0;
    z-index: -1;
    overflow: hidden;

    background:
        radial-gradient(circle at 10% 10%, rgba(37,99,235,.12), transparent 30%),
        radial-gradient(circle at 90% 90%, rgba(15,72,135,.12), transparent 35%),
        linear-gradient(135deg, #f8fbff, #edf4fc);
}

.circle {
    position: absolute;
    border-radius: 50%;
    filter: blur(2px);
    animation: floatCircle 12s ease-in-out infinite;
}

.circle-one {
    width: 330px;
    height: 330px;
    top: -100px;
    left: -90px;
    background: rgba(37,99,235,.12);
}

.circle-two {
    width: 430px;
    height: 430px;
    right: -150px;
    bottom: -180px;
    background: rgba(18,65,125,.12);
    animation-delay: -5s;
}

.circle-three {
    width: 120px;
    height: 120px;
    top: 65%;
    left: 12%;
    background: rgba(59,130,246,.10);
    animation-delay: -3s;
}

@keyframes floatCircle {
    0%, 100% {
        transform: translate(0, 0);
    }
    50% {
        transform: translate(25px, -30px);
    }
}

/* BACK BUTTON */

.back-button {
    position: fixed;
    top: 23px;
    left: 28px;
    z-index: 10;

    display: inline-flex;
    align-items: center;
    gap: 9px;

    padding: 11px 19px;

    border: 1px solid rgba(21,85,160,.15);
    border-radius: 50px;

    background: rgba(255,255,255,.9);
    color: #174779;

    font-size: 13px;
    font-weight: 700;
    text-decoration: none;

    box-shadow: 0 5px 20px rgba(15,45,80,.06);
    backdrop-filter: blur(12px);

    transition: .3s ease;

    animation: slideDown .7s ease both;
}

.back-button:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(21,85,160,.22);
}

.back-button i {
    transition: .3s ease;
}

.back-button:hover i {
    transform: translateX(-4px);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* WRAPPER */

.register-wrapper {
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 85px 20px 45px;
}

.register-container {
    width: 100%;
    max-width: 1120px;

    display: grid;
    grid-template-columns: .88fr 1.12fr;

    overflow: hidden;
    border-radius: 28px;

    background: white;

    box-shadow:
        0 30px 80px rgba(15,40,75,.12),
        0 5px 20px rgba(15,40,75,.04);

    animation: cardAppear .85s cubic-bezier(.2,.8,.2,1) both;
}

@keyframes cardAppear {
    from {
        opacity: 0;
        transform: translateY(35px) scale(.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* LEFT PANEL */

.register-info {
    position: relative;
    display: flex;
    align-items: center;
    padding: 65px 45px;
    overflow: hidden;

    color: white;

    background:
        radial-gradient(circle at 90% 10%, rgba(255,255,255,.13), transparent 30%),
        linear-gradient(145deg, #123c70, #1555a0 55%, #1c6bb8);
}

.register-info::before,
.register-info::after {
    content: "";
    position: absolute;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 50%;
    animation: rotateCircle 25s linear infinite;
}

.register-info::before {
    width: 330px;
    height: 330px;
    right: -140px;
    top: -140px;
}

.register-info::after {
    width: 220px;
    height: 220px;
    right: -80px;
    top: -80px;
    animation-direction: reverse;
}

@keyframes rotateCircle {
    to {
        transform: rotate(360deg);
    }
}

.info-content {
    position: relative;
    z-index: 2;
}

.wifi-icon {
    width: 95px;
    height: 95px;

    display: flex;
    align-items: center;
    justify-content: center;

    margin-bottom: 30px;
    border-radius: 24px;

    background: rgba(255,255,255,.13);
    border: 1px solid rgba(255,255,255,.22);

    animation: logoFloat 4s ease-in-out infinite;
}

.wifi-icon img {
    width: 75px;
    height: 75px;
    object-fit: contain;
}

@keyframes logoFloat {
    50% {
        transform: translateY(-9px);
    }
}

.register-info h1 {
    font-size: 40px;
    line-height: 1.12;
    font-weight: 900;
    letter-spacing: -1.8px;
    margin-bottom: 20px;
}

.register-info h1 span {
    color: #a9d6ff;
}

.register-info p {
    max-width: 390px;
    color: rgba(255,255,255,.78);
    font-size: 14px;
    line-height: 1.9;
    margin-bottom: 32px;
}

.feature-list {
    display: flex;
    flex-direction: column;
    gap: 17px;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 13px;
    color: rgba(255,255,255,.92);
    font-size: 13px;
    font-weight: 500;

    opacity: 0;
    animation: featureAppear .6s ease forwards;
}

.feature-item:nth-child(1) { animation-delay: .5s; }
.feature-item:nth-child(2) { animation-delay: .7s; }
.feature-item:nth-child(3) { animation-delay: .9s; }

@keyframes featureAppear {
    to {
        opacity: 1;
        transform: translateX(0);
    }
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
}

.feature-icon {
    width: 29px;
    height: 29px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(255,255,255,.17);
    border: 1px solid rgba(255,255,255,.25);
}

/* RIGHT PANEL */

.register-card {
    padding: 48px 50px;
    background: white;
    animation: fadeRight .9s ease both;
}

@keyframes fadeRight {
    from {
        opacity: 0;
        transform: translateX(25px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.register-header {
    margin-bottom: 28px;
}

.register-header h2 {
    color: #172b46;
    font-size: 28px;
    font-weight: 900;
    letter-spacing: -1px;
    margin-bottom: 9px;
}

.register-header p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    margin: 0;
}

/* FORM */

.form-group {
    margin-bottom: 17px;
}

.register-label {
    display: block;
    margin-bottom: 8px;
    color: #263b56;
    font-size: 12px;
    font-weight: 800;
}

.input-wrapper {
    position: relative;
}

.input-icon {
    position: absolute;
    top: 50%;
    left: 15px;
    transform: translateY(-50%);
    color: #8ba0b9;
    font-size: 16px;
    pointer-events: none;
    transition: color .3s ease;
}

.form-control {
    width: 100%;
    min-height: 49px;
    padding: 11px 44px;

    border: 1px solid #e0e8f1;
    border-radius: 11px;

    background: #f9fbfe;
    color: #1e293b;

    font-family: inherit;
    font-size: 12px;

    box-shadow: none;
    transition: all .3s ease;
}

.form-control::placeholder {
    color: #aab6c5;
}

.form-control:hover {
    border-color: #b8cce3;
}

.form-control:focus {
    background: white;
    border-color: #2869b2;
    box-shadow: 0 0 0 4px rgba(37,99,235,.10);
    outline: none;
}

.input-wrapper:focus-within .input-icon {
    color: #2563eb;
}

textarea.form-control {
    min-height: 95px;
    resize: vertical;
    padding-top: 13px;
}

.input-hint {
    margin-top: 6px;
    color: #94a3b8;
    font-size: 10px;
    line-height: 1.5;
}

/* PASSWORD */

.password-toggle {
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #8ba0b9;
    font-size: 16px;
    cursor: pointer;
}

.password-toggle:hover {
    color: var(--primary);
}

.password-strength {
    margin-top: 9px;
}

.strength-bars {
    display: flex;
    gap: 5px;
}

.strength-bars span {
    flex: 1;
    height: 4px;
    border-radius: 10px;
    background: #e5eaf1;
    transition: background .25s ease;
}

.strength-label {
    display: block;
    margin-top: 6px;
    color: #94a3b8;
    font-size: 10px;
}

/* ALERT */

.alert {
    border: none;
    border-radius: 12px;
    font-size: 12px;
    animation: alertAppear .4s ease both;
}

@keyframes alertAppear {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* BUTTON */

.register-btn {
    position: relative;
    overflow: hidden;

    display: flex;
    align-items: center;
    justify-content: center;

    width: 100%;
    min-height: 52px;

    border: none;
    border-radius: 12px;

    background: linear-gradient(110deg, #123f78, #1761aa);
    color: white;

    font-size: 13px;
    font-weight: 800;

    box-shadow: 0 8px 20px rgba(21,85,160,.18);
    transition: all .3s ease;
}

.register-btn::before {
    content: "";
    position: absolute;
    top: 0;
    left: -110%;
    width: 70%;
    height: 100%;

    background: linear-gradient(
        90deg,
        transparent,
        rgba(255,255,255,.25),
        transparent
    );

    transform: skewX(-20deg);
    transition: left .6s ease;
}

.register-btn:hover::before {
    left: 140%;
}

.register-btn:hover {
    background: linear-gradient(110deg, #0e315d, #1555a0);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 13px 28px rgba(21,85,160,.27);
}

.register-btn:disabled {
    opacity: .7;
    cursor: not-allowed;
    transform: none;
}

/* LOGIN */

.login-text {
    margin-top: 22px;
    text-align: center;
    color: #8492a6;
    font-size: 12px;
}

.login-text a {
    color: #1555a0;
    font-weight: 800;
    text-decoration: none;
}

.login-text a:hover {
    color: #0d315d;
    text-decoration: underline;
}

/* SECURITY */

.security-text {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;

    margin-top: 22px;
    padding-top: 17px;

    border-top: 1px solid #edf1f6;

    color: #8a9aaf;
    font-size: 10px;
    font-weight: 600;
}

.security-text i {
    color: #18a56b;
    font-size: 13px;
}

/* RESPONSIVE */

@media (max-width: 950px) {

    .register-container {
        max-width: 760px;
        grid-template-columns: .8fr 1.2fr;
    }

    .register-info {
        padding: 45px 30px;
    }

    .register-card {
        padding: 40px 30px;
    }

    .register-info h1 {
        font-size: 33px;
    }

    .register-header h2 {
        font-size: 25px;
    }

}

@media (max-width: 700px) {

    .register-wrapper {
        padding: 85px 15px 30px;
    }

    .register-container {
        max-width: 470px;
        grid-template-columns: 1fr;
        border-radius: 22px;
    }

    .register-info {
        min-height: auto;
        padding: 32px 25px;
        text-align: center;
    }

    .wifi-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 17px;
        border-radius: 18px;
    }

    .wifi-icon img {
        width: 58px;
        height: 58px;
    }

    .register-info h1 {
        font-size: 29px;
        margin-bottom: 10px;
    }

    .register-info p {
        margin: 0 auto;
        font-size: 12px;
    }

    .feature-list {
        display: none;
    }

    .register-card {
        padding: 35px 25px;
    }

    .register-header {
        margin-bottom: 23px;
    }

    .register-header h2 {
        font-size: 24px;
    }

    .back-button {
        top: 17px;
        left: 15px;
        padding: 9px 15px;
        font-size: 12px;
    }

}

@media (max-width: 380px) {

    .register-card {
        padding: 30px 18px;
    }

    .register-header h2 {
        font-size: 22px;
    }

    .form-control {
        min-height: 46px;
    }

}

@media (prefers-reduced-motion: reduce) {

    *,
    *::before,
    *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
        scroll-behavior: auto !important;
    }

}

</style>

</head>

<body>

<div class="page-background">

    <div class="circle circle-one"></div>
    <div class="circle circle-two"></div>
    <div class="circle circle-three"></div>

</div>

<!-- BACK BUTTON -->

<a href="index.php" class="back-button">

    <i class="bi bi-arrow-left"></i>

    <span>Kembali</span>

</a>

<main class="register-wrapper">

    <div class="register-container">

        <!-- LEFT PANEL -->

        <section class="register-info">

            <div class="info-content">

                <div class="wifi-icon">

                    <img
                        src="logo-yesnet.png"
                        alt="Logo YESNET"
                    >

                </div>

                <h1>
                    Bergabung<br>
                    Bersama <span>YESNET</span>
                </h1>

                <p>
                    Buat akun pelanggan YESNET dan nikmati
                    layanan internet yang cepat, stabil,
                    serta mudah dikelola.
                </p>

                <div class="feature-list">

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>Pendaftaran pelanggan mudah</span>

                    </div>

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>Pilihan paket sesuai kebutuhan</span>

                    </div>

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>Pantau layanan dalam satu akun</span>

                    </div>

                </div>

            </div>

        </section>

        <!-- REGISTER FORM -->

        <section class="register-card">

            <div class="register-header">

                <h2>Buat Akun Baru</h2>

                <p>
                    Lengkapi data berikut untuk membuat akun pelanggan YESNET.
                </p>

            </div>

            <?php if ($error !== ''): ?>

                <div class="alert alert-danger" role="alert">

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= e($error) ?>

                </div>

            <?php endif; ?>

            <form
                method="POST"
                action=""
                id="registerForm"
                autocomplete="on"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= e($_SESSION['csrf_register']) ?>"
                >

                <?php if ($paketId > 0): ?>

                    <input
                        type="hidden"
                        name="paket_id"
                        value="<?= (int)$paketId ?>"
                    >

                <?php endif; ?>

                <!-- NAMA -->

                <div class="form-group">

                    <label for="nama" class="register-label">
                        Nama Lengkap
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-person input-icon"></i>

                        <input
                            type="text"
                            class="form-control"
                            id="nama"
                            name="nama"
                            placeholder="Masukkan nama lengkap"
                            value="<?= e($nama) ?>"
                            required
                            minlength="3"
                            autocomplete="name"
                        >

                    </div>

                </div>

                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username" class="register-label">
                        Username
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-at input-icon"></i>

                        <input
                            type="text"
                            class="form-control"
                            id="username"
                            name="username"
                            placeholder="Contoh: pelanggan123"
                            value="<?= e($username) ?>"
                            required
                            minlength="4"
                            maxlength="50"
                            pattern="[a-zA-Z0-9._-]{4,50}"
                            autocomplete="username"
                        >

                    </div>

                    <div class="input-hint">
                        4-50 karakter. Gunakan huruf, angka, titik, garis bawah, atau minus.
                    </div>

                </div>

                <!-- EMAIL -->

                <div class="form-group">

                    <label for="email" class="register-label">
                        Email
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-envelope input-icon"></i>

                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="nama@email.com"
                            value="<?= e($email) ?>"
                            required
                            autocomplete="email"
                        >

                    </div>

                </div>

                <!-- TELEPON -->

                <div class="form-group">

                    <label for="telephone" class="register-label">
                        Nomor Telepon
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-telephone input-icon"></i>

                        <input
                            type="tel"
                            class="form-control"
                            id="telephone"
                            name="telephone"
                            placeholder="081234567890"
                            value="<?= e($telephone) ?>"
                            required
                            minlength="8"
                            maxlength="20"
                            autocomplete="tel"
                        >

                    </div>

                </div>

                <!-- PASSWORD -->

                <div class="form-group">

                    <label for="password" class="register-label">
                        Password
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-lock input-icon"></i>

                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Buat password"
                            required
                            minlength="6"
                            autocomplete="new-password"
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-target="password"
                            aria-label="Tampilkan password"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                    <div class="password-strength">

                        <div class="strength-bars">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>

                        <span class="strength-label" id="strengthLabel">
                            Minimal 6 karakter.
                        </span>

                    </div>

                </div>

                <!-- KONFIRMASI PASSWORD -->

                <div class="form-group">

                    <label for="password_confirm" class="register-label">
                        Konfirmasi Password
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-shield-lock input-icon"></i>

                        <input
                            type="password"
                            class="form-control"
                            id="password_confirm"
                            name="password_confirm"
                            placeholder="Masukkan ulang password"
                            required
                            minlength="6"
                            autocomplete="new-password"
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-target="password_confirm"
                            aria-label="Tampilkan konfirmasi password"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                    <div class="input-hint" id="passwordMatchHint">
                        Masukkan ulang password yang sama.
                    </div>

                </div>

                <!-- NIK -->

                <div class="form-group">

                    <label for="nik" class="register-label">
                        NIK
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-card-text input-icon"></i>

                        <input
                            type="text"
                            class="form-control"
                            id="nik"
                            name="nik"
                            placeholder="Masukkan NIK"
                            value="<?= e($nik) ?>"
                            required
                            minlength="10"
                            maxlength="20"
                            pattern="[0-9]{10,20}"
                            inputmode="numeric"
                        >

                    </div>

                    <div class="input-hint">
                        NIK hanya boleh berisi angka.
                    </div>

                </div>

                <!-- ALAMAT -->

                <div class="form-group">

                    <label for="alamat" class="register-label">
                        Alamat Lengkap
                    </label>

                    <div class="input-wrapper">

                        <i class="bi bi-geo-alt input-icon"></i>

                        <textarea
                            class="form-control"
                            id="alamat"
                            name="alamat"
                            rows="3"
                            placeholder="Masukkan alamat lengkap pemasangan"
                            required
                        ><?= e($alamat) ?></textarea>

                    </div>

                </div>

                <!-- SUBMIT -->

                <div class="mt-4">

                    <button
                        type="submit"
                        class="btn register-btn"
                        id="registerButton"
                    >

                        <i class="bi bi-person-plus me-2"></i>

                        Daftar sebagai Customer

                    </button>

                </div>

            </form>

            <div class="login-text">

                Sudah punya akun?

                <a href="login.php">Login sekarang</a>

            </div>

            <div class="security-text">

                <i class="bi bi-shield-lock-fill"></i>

                Data pendaftaran Anda diproses secara aman.

            </div>

        </section>

    </div>

</main>

<script>

/* SHOW / HIDE PASSWORD */

document.querySelectorAll('.password-toggle').forEach(function(button) {

    button.addEventListener('click', function() {

        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');

        if (!input || !icon) return;

        const show = input.type === 'password';

        input.type = show ? 'text' : 'password';

        icon.className = show
            ? 'bi bi-eye-slash'
            : 'bi bi-eye';

    });

});

/* PASSWORD STRENGTH */

const password = document.getElementById('password');
const bars = document.querySelectorAll('.strength-bars span');
const strengthLabel = document.getElementById('strengthLabel');

function checkPasswordStrength(value) {

    let score = 0;

    if (value.length >= 6) score++;
    if (value.length >= 8) score++;
    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
    if (/[0-9]/.test(value)) score++;
    if (/[^a-zA-Z0-9]/.test(value)) score++;

    score = Math.min(score, 4);

    const labels = [
        'Minimal 6 karakter.',
        'Password lemah',
        'Password cukup',
        'Password kuat',
        'Password sangat kuat'
    ];

    const colors = [
        '#e5eaf1',
        '#ef4444',
        '#f59e0b',
        '#3b82f6',
        '#16a34a'
    ];

    bars.forEach(function(bar, index) {

        bar.style.background = index < score
            ? colors[score]
            : '#e5eaf1';

    });

    strengthLabel.textContent = value.length === 0
        ? labels[0]
        : labels[score];

    strengthLabel.style.color = value.length === 0
        ? '#94a3b8'
        : colors[score];

}

password.addEventListener('input', function() {
    checkPasswordStrength(password.value);
    checkPasswordMatch();
});

/* PASSWORD MATCH */

const passwordConfirm = document.getElementById('password_confirm');
const passwordMatchHint = document.getElementById('passwordMatchHint');

function checkPasswordMatch() {

    if (passwordConfirm.value === '') {

        passwordConfirm.setCustomValidity('');

        passwordMatchHint.textContent =
            'Masukkan ulang password yang sama.';

        passwordMatchHint.style.color = '#94a3b8';

        return true;
    }

    const match = password.value === passwordConfirm.value;

    passwordConfirm.setCustomValidity(
        match ? '' : 'Konfirmasi password tidak sama.'
    );

    passwordMatchHint.textContent = match
        ? 'Password cocok.'
        : 'Password belum cocok.';

    passwordMatchHint.style.color = match
        ? '#16a34a'
        : '#ef4444';

    return match;
}

passwordConfirm.addEventListener('input', checkPasswordMatch);

/* NIK ONLY NUMBERS */

const nikInput = document.getElementById('nik');

nikInput.addEventListener('input', function() {

    nikInput.value = nikInput.value
        .replace(/[^0-9]/g, '')
        .slice(0, 20);

});

/* FORM SUBMIT */

const registerForm = document.getElementById('registerForm');
const registerButton = document.getElementById('registerButton');

registerForm.addEventListener('submit', function(event) {

    if (!checkPasswordMatch()) {

        event.preventDefault();
        passwordConfirm.focus();

        return;
    }

    if (!registerForm.checkValidity()) {
        return;
    }

    registerButton.disabled = true;

    registerButton.innerHTML = `
        <span
            class="spinner-border spinner-border-sm me-2"
            role="status"
            aria-hidden="true"
        ></span>
        Memproses pendaftaran...
    `;

});

</script>

</body>
</html>
