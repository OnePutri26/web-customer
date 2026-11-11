
<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/auth.php";

date_default_timezone_set("Asia/Jakarta");

/* =========================================================
   VARIABEL
========================================================= */

$error = "";
$usernameInput = "";

/* =========================================================
   HELPER
========================================================= */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/* =========================================================
   ERROR SESSION
========================================================= */

if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

/* =========================================================
   CEK SUDAH LOGIN
========================================================= */

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectRoleDashboard(
        $conn,
        (string)$_SESSION['role'],
        (int)$_SESSION['user_id']
    );
}

/* =========================================================
   PROSES LOGIN
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usernameInput = trim(
        (string)($_POST['username'] ?? '')
    );

    $password = (string)($_POST['password'] ?? '');

    /* VALIDASI */

    if ($usernameInput === '') {

        $error = "Username atau nama wajib diisi.";

    } elseif ($password === '') {

        $error = "Password wajib diisi.";

    } else {

        /* CARI USER */

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
                OR email = ?
                OR nama = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "sss",
            $usernameInput,
            $usernameInput,
            $usernameInput
        );

        $stmt->execute();

        $result = $stmt->get_result();

        /* USER TIDAK DITEMUKAN */

        if (!$result || $result->num_rows === 0) {

            $error = "Username/nama atau password salah.";

            $stmt->close();

        } else {

            $user = $result->fetch_assoc();

            /* CEK PASSWORD */

            if (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                $error = "Username/nama atau password salah.";

                $stmt->close();

            } else {

                /* CEK STATUS USER */

                $userStatus = strtolower(
                    trim((string)($user['status'] ?? ''))
                );

                $activeStatuses = [
                    'active',
                    'aktif',
                    '1'
                ];

                if (!in_array($userStatus, $activeStatuses, true)) {

                    $error = "Akun Anda sedang tidak aktif.";

                    $stmt->close();

                } else {

                    /* LOGIN BERHASIL */

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];

                    $_SESSION['role'] = strtolower(
                        trim((string)($user['role'] ?? ''))
                    );

                    $_SESSION['user_status'] = $user['status'];

                    $stmt->close();

                    redirectRoleDashboard(
                        $conn,
                        (string)$_SESSION['role'],
                        (int)$user['id']
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
        content="Login WiFi Management System YESNET"
    >

    <title>Login | YESNET</title>

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

    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet"
    >

    <style>

        /* =====================================================
           RESET
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1555a0;
            --primary-dark: #103c70;
            --primary-light: #eaf3ff;
            --text-dark: #14243a;
            --text-muted: #718096;
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;

            font-family: 'Inter', sans-serif;

            background: #f3f7fc;
            color: var(--text-dark);
        }

        /* =====================================================
           BACKGROUND ANIMATION
        ===================================================== */

        .page-background {
            position: fixed;
            inset: 0;
            z-index: -2;

            overflow: hidden;

            background:
                radial-gradient(
                    circle at 10% 10%,
                    rgba(37, 99, 235, .12),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 90% 90%,
                    rgba(15, 72, 135, .12),
                    transparent 35%
                ),
                linear-gradient(
                    135deg,
                    #f8fbff,
                    #edf4fc
                );
        }

        .background-circle {
            position: absolute;

            border-radius: 50%;

            filter: blur(2px);

            opacity: .45;

            animation: floatCircle 12s ease-in-out infinite;
        }

        .circle-1 {
            width: 330px;
            height: 330px;

            top: -100px;
            left: -90px;

            background: rgba(37, 99, 235, .12);
        }

        .circle-2 {
            width: 430px;
            height: 430px;

            right: -150px;
            bottom: -180px;

            background: rgba(18, 65, 125, .12);

            animation-delay: -5s;
        }

        .circle-3 {
            width: 120px;
            height: 120px;

            top: 65%;
            left: 12%;

            background: rgba(59, 130, 246, .10);

            animation-delay: -3s;
        }

        @keyframes floatCircle {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }

            50% {
                transform: translate(25px, -30px) scale(1.08);
            }
        }

        /* =====================================================
           BACK BUTTON
        ===================================================== */

        .back-button {
            position: fixed;
            top: 25px;
            left: 30px;
            z-index: 100;

            display: inline-flex;
            align-items: center;
            gap: 9px;

            padding: 11px 19px;

            border: 1px solid rgba(21, 85, 160, .15);
            border-radius: 50px;

            background: rgba(255, 255, 255, .85);

            color: #174779;

            font-size: 13px;
            font-weight: 700;

            text-decoration: none;

            box-shadow: 0 5px 20px rgba(15, 45, 80, .06);

            backdrop-filter: blur(12px);

            transition: all .3s ease;

            animation: slideDown .7s ease both;
        }

        .back-button i {
            font-size: 16px;
            transition: transform .3s ease;
        }

        .back-button:hover {
            background: #1555a0;
            color: #fff;

            border-color: #1555a0;

            transform: translateY(-3px);

            box-shadow: 0 10px 25px rgba(21, 85, 160, .22);
        }

        .back-button:hover i {
            transform: translateX(-4px);
        }

        /* =====================================================
           LOGIN WRAPPER
        ===================================================== */

        .login-wrapper {
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 85px 25px 40px;
        }

        .login-container {
            width: 100%;
            max-width: 1080px;

            min-height: 620px;

            display: grid;
            grid-template-columns: 1fr 1fr;

            background: #fff;

            border-radius: 28px;

            overflow: hidden;

            box-shadow:
                0 30px 80px rgba(15, 40, 75, .12),
                0 5px 20px rgba(15, 40, 75, .04);

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

        /* =====================================================
           LEFT PANEL
        ===================================================== */

        .login-info {
            position: relative;

            display: flex;
            flex-direction: column;
            justify-content: center;

            padding: 65px 55px;

            overflow: hidden;

            background:
                radial-gradient(
                    circle at 90% 10%,
                    rgba(255,255,255,.13),
                    transparent 30%
                ),
                linear-gradient(
                    145deg,
                    #123c70,
                    #1555a0 55%,
                    #1c6bb8
                );

            color: #fff;
        }

        .login-info::before {
            content: "";

            position: absolute;

            width: 320px;
            height: 320px;

            right: -130px;
            top: -130px;

            border: 1px solid rgba(255,255,255,.12);
            border-radius: 50%;

            animation: rotateCircle 25s linear infinite;
        }

        .login-info::after {
            content: "";

            position: absolute;

            width: 220px;
            height: 220px;

            right: -70px;
            top: -70px;

            border: 1px solid rgba(255,255,255,.10);
            border-radius: 50%;

            animation: rotateCircle 20s linear infinite reverse;
        }

        @keyframes rotateCircle {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .info-content {
            position: relative;
            z-index: 2;
        }

        /* LOGO */

        .wifi-icon {
            width: 105px;
            height: 105px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 30px;

            border-radius: 25px;

            background: rgba(255,255,255,.13);

            border: 1px solid rgba(255,255,255,.22);

            backdrop-filter: blur(10px);

            animation: logoFloat 4s ease-in-out infinite;
        }

        .wifi-icon img {
            width: 82px;
            height: 82px;

            object-fit: contain;

            filter: drop-shadow(0 5px 12px rgba(0,0,0,.15));
        }

        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-9px);
            }
        }

        .login-info h1 {
            font-size: 43px;
            line-height: 1.12;

            font-weight: 900;

            letter-spacing: -1.8px;

            margin-bottom: 22px;
        }

        .login-info h1 span {
            color: #a9d6ff;
        }

        .login-info > .info-content > p {
            max-width: 390px;

            color: rgba(255,255,255,.78);

            font-size: 14px;
            line-height: 1.9;

            margin-bottom: 35px;
        }

        /* =====================================================
           FEATURE LIST
        ===================================================== */

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

            font-size: 14px;
            font-weight: 500;

            opacity: 0;

            animation: featureAppear .6s ease forwards;
        }

        .feature-item:nth-child(1) {
            animation-delay: .5s;
        }

        .feature-item:nth-child(2) {
            animation-delay: .7s;
        }

        .feature-item:nth-child(3) {
            animation-delay: .9s;
        }

        @keyframes featureAppear {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
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

            color: #fff;

            font-size: 13px;
            font-weight: 900;
        }

        /* =====================================================
           RIGHT PANEL
        ===================================================== */

        .login-card {
            display: flex;
            flex-direction: column;
            justify-content: center;

            padding: 65px 55px;

            background: #fff;

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

        .login-header {
            margin-bottom: 35px;
        }

        .login-header h2 {
            color: #172b46;

            font-size: 29px;
            font-weight: 900;

            letter-spacing: -1px;

            margin-bottom: 10px;
        }

        .login-header p {
            color: #8492a6;

            font-size: 13px;
            line-height: 1.7;

            margin: 0;
        }

        /* =====================================================
           ALERT
        ===================================================== */

        .alert {
            border: none;
            border-radius: 12px;

            font-size: 13px;

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

        /* =====================================================
           FORM INPUT
        ===================================================== */

        .form-label {
            color: #263b56;

            font-size: 13px;
            font-weight: 700;

            margin-bottom: 9px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;

            top: 50%;
            left: 16px;

            transform: translateY(-50%);

            color: #8ba0b9;

            font-size: 17px;

            pointer-events: none;

            transition: color .3s ease;
        }

        .form-control {
            width: 100%;
            height: 54px;

            padding: 12px 48px;

            border: 1px solid #e0e8f1;
            border-radius: 12px;

            background: #f9fbfe;

            color: #1e293b;

            font-size: 13px;

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
            background: #fff;

            border-color: #2869b2;

            box-shadow: 0 0 0 4px rgba(37,99,235,.10);

            outline: none;
        }

        .input-wrapper:focus-within .input-icon {
            color: #2563eb;
        }

        /* SHOW PASSWORD */

        .password-toggle {
            position: absolute;

            top: 50%;
            right: 15px;

            transform: translateY(-50%);

            border: none;
            background: transparent;

            color: #8ba0b9;

            font-size: 17px;

            cursor: pointer;

            transition: color .2s ease;
        }

        .password-toggle:hover {
            color: #1555a0;
        }

        /* =====================================================
           LOGIN BUTTON
        ===================================================== */

        .login-btn {
            position: relative;
            overflow: hidden;

            display: flex;
            align-items: center;
            justify-content: center;

            height: 54px;

            border: none;
            border-radius: 12px;

            background: linear-gradient(
                110deg,
                #123f78,
                #1761aa
            );

            color: #fff;

            font-size: 14px;
            font-weight: 800;

            box-shadow: 0 8px 20px rgba(21,85,160,.18);

            transition: all .3s ease;
        }

        .login-btn::before {
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

        .login-btn:hover::before {
            left: 140%;
        }

        .login-btn:hover {
            background: linear-gradient(
                110deg,
                #0e315d,
                #1555a0
            );

            color: #fff;

            transform: translateY(-3px);

            box-shadow: 0 13px 28px rgba(21,85,160,.27);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        /* =====================================================
           REGISTER
        ===================================================== */

        .register-text {
            margin-top: 25px;

            text-align: center;

            color: #8492a6;

            font-size: 13px;
        }

        .register-text a {
            color: #1555a0;

            font-weight: 800;

            text-decoration: none;

            transition: color .2s ease;
        }

        .register-text a:hover {
            color: #0d315d;
            text-decoration: underline;
        }

        /* =====================================================
           SECURITY
        ===================================================== */

        .security-text {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;

            margin-top: 28px;
            padding-top: 20px;

            border-top: 1px solid #edf1f6;

            color: #8a9aaf;

            font-size: 11px;
            font-weight: 600;
        }

        .security-text i {
            color: #18a56b;
            font-size: 14px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 900px) {

            .login-container {
                max-width: 760px;
            }

            .login-info {
                padding: 50px 35px;
            }

            .login-card {
                padding: 50px 35px;
            }

            .login-info h1 {
                font-size: 35px;
            }

            .login-header h2 {
                font-size: 25px;
            }
        }

        @media (max-width: 650px) {

            .login-wrapper {
                padding: 90px 16px 30px;
            }

            .login-container {
                max-width: 430px;

                grid-template-columns: 1fr;

                border-radius: 22px;
            }

            .login-info {
                min-height: auto;

                padding: 35px 28px;

                text-align: center;
            }

            .wifi-icon {
                width: 75px;
                height: 75px;

                margin: 0 auto 20px;

                border-radius: 20px;
            }

            .wifi-icon img {
                width: 60px;
                height: 60px;
            }

            .login-info h1 {
                font-size: 30px;
                margin-bottom: 12px;
            }

            .login-info > .info-content > p {
                margin: 0 auto 22px;
                font-size: 12px;
            }

            .feature-list {
                display: none;
            }

            .login-card {
                padding: 38px 27px;
            }

            .login-header {
                margin-bottom: 25px;
            }

            .login-header h2 {
                font-size: 25px;
            }

            .back-button {
                top: 18px;
                left: 16px;

                padding: 9px 15px;

                font-size: 12px;
            }
        }

        @media (max-width: 380px) {

            .login-info {
                padding: 30px 20px;
            }

            .login-card {
                padding: 32px 20px;
            }

            .login-header h2 {
                font-size: 23px;
            }
        }

        /* =====================================================
           ACCESSIBILITY
        ===================================================== */

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

<!-- BACKGROUND -->

<div class="page-background">

    <div class="background-circle circle-1"></div>
    <div class="background-circle circle-2"></div>
    <div class="background-circle circle-3"></div>

</div>

<!-- TOMBOL KEMBALI -->

<a href="index.php" class="back-button">

    <i class="bi bi-arrow-left"></i>

    <span>Kembali</span>

</a>

<!-- LOGIN WRAPPER -->

<div class="login-wrapper">

    <div class="login-container">

        <!-- =================================================
             LEFT PANEL
        ================================================== -->

        <div class="login-info">

            <div class="info-content">

                <div class="wifi-icon">

                    <img
                        src="logo-yesnet.png"
                        alt="Logo YESNET"
                    >

                </div>

                <h1>
                    WiFi<br>
                    <span>Management</span>
                </h1>

                <p>
                    Kelola layanan WiFi dengan lebih mudah,
                    cepat, dan terorganisir dalam satu sistem.
                </p>

                <div class="feature-list">

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>
                            Kelola data pelanggan
                        </span>

                    </div>

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>
                            Pantau instalasi WiFi
                        </span>

                    </div>

                    <div class="feature-item">

                        <div class="feature-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <span>
                            Sistem terintegrasi
                        </span>

                    </div>

                </div>

            </div>

        </div>

        <!-- =================================================
             RIGHT PANEL
        ================================================== -->

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

            <!-- FORM LOGIN -->

            <form
                method="POST"
                action=""
                autocomplete="on"
            >

                <!-- USERNAME -->

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

                        <button
                            type="button"
                            class="password-toggle"
                            id="togglePassword"
                            aria-label="Tampilkan password"
                        >
                            <i class="bi bi-eye" id="passwordIcon"></i>
                        </button>

                    </div>

                </div>

                <!-- LOGIN BUTTON -->

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

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    /* =====================================================
       SHOW / HIDE PASSWORD
    ===================================================== */

    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const passwordIcon = document.getElementById('passwordIcon');

    if (passwordInput && togglePassword && passwordIcon) {

        togglePassword.addEventListener('click', function () {

            const isPassword = passwordInput.type === 'password';

            passwordInput.type = isPassword ? 'text' : 'password';

            passwordIcon.className = isPassword
                ? 'bi bi-eye-slash'
                : 'bi bi-eye';

            togglePassword.setAttribute(
                'aria-label',
                isPassword ? 'Sembunyikan password' : 'Tampilkan password'
            );

        });

    }

    /* =====================================================
       LOADING EFFECT SAAT LOGIN
    ===================================================== */

    const loginForm = document.querySelector('.login-card form');
    const loginButton = document.querySelector('.login-btn');

    if (loginForm && loginButton) {

        loginForm.addEventListener('submit', function () {

            if (!loginForm.checkValidity()) {
                return;
            }

            loginButton.disabled = true;

            loginButton.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                ></span>
                Memproses login...
            `;

        });

    }

});

</script>

</body>
</html>
