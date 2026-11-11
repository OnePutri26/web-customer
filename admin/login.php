<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ADMIN LOGIN
|--------------------------------------------------------------------------
| File: admin/login.php
| Catatan:
| - Menggunakan session khusus administrator.
| - Tidak mengubah session customer.
| - Hanya akun dengan role admin yang dapat login.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
| VALIDASI KONEKSI DATABASE
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

/*
|--------------------------------------------------------------------------
| CEK SESSION ADMIN YANG SUDAH LOGIN
|--------------------------------------------------------------------------
*/

$error = (string) ($_SESSION['admin_login_error'] ?? '');
unset($_SESSION['admin_login_error']);

if (isset($_SESSION['user_id'], $_SESSION['role'])) {

    $existingUserId = (int) $_SESSION['user_id'];

    if ($existingUserId > 0) {

        $stmt = $conn->prepare("
            SELECT role, status
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param('i', $existingUserId);
        $stmt->execute();

        $existingUser = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        $existingRole = strtolower(trim(
            (string) ($existingUser['role'] ?? '')
        ));

        $sessionRole = strtolower(trim(
            (string) ($_SESSION['role'] ?? '')
        ));

        $existingStatus = strtolower(trim(
            (string) ($existingUser['status'] ?? '')
        ));

        $isActive = in_array(
            $existingStatus,
            ['1', 'active', 'aktif'],
            true
        );

        if (
            $existingUser &&
            $existingRole === 'admin' &&
            $sessionRole === 'admin' &&
            $isActive
        ) {
            header('Location: dashboard.php');
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION TIDAK VALID
    |--------------------------------------------------------------------------
    | Hapus sesi lama dan mulai ulang proses melalui request baru.
    */

    adminClearSession();

    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PROSES LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim(
        (string) ($_POST['username'] ?? '')
    );

    $password = (string) ($_POST['password'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDASI CSRF
    |--------------------------------------------------------------------------
    */

    if (!adminVerifyCsrf($_POST['csrf'] ?? null)) {

        $error = 'Form login kedaluwarsa. Muat ulang halaman lalu coba kembali.';

    } elseif ($username === '' || $password === '') {

        $error = 'Username dan password wajib diisi.';

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
                password,
                role,
                status
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->bind_param('s', $username);
        $stmt->execute();

        $user = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | VALIDASI AKUN
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            $error = 'Username atau password salah.';

        } elseif (
            !password_verify(
                $password,
                (string) $user['password']
            )
        ) {

            $error = 'Username atau password salah.';

        } elseif (
            !in_array(
                strtolower(trim((string) ($user['status'] ?? ''))),
                ['1', 'active', 'aktif'],
                true
            )
        ) {

            $error = 'Akun sedang tidak aktif.';

        } elseif (
            strtolower(trim((string) ($user['role'] ?? ''))) !== 'admin'
        ) {

            $error = 'Akun tidak memiliki akses administrator.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | LOGIN BERHASIL
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = (string) $user['username'];
            $_SESSION['nama'] = (string) ($user['nama'] ?? '');
            $_SESSION['email'] = (string) ($user['email'] ?? '');
            $_SESSION['role'] = 'admin';
            $_SESSION['user_status'] = (string) $user['status'];

            /*
            |--------------------------------------------------------------------------
            | HAK AKSES ADMIN
            |--------------------------------------------------------------------------
            */

            $_SESSION['admin_permissions'] =
                adminPermissionsForRole('admin');

            /*
            |--------------------------------------------------------------------------
            | TOKEN CSRF BARU
            |--------------------------------------------------------------------------
            */

            $_SESSION['admin_csrf'] = bin2hex(
                random_bytes(32)
            );

            /*
            |--------------------------------------------------------------------------
            | REDIRECT DASHBOARD
            |--------------------------------------------------------------------------
            */

            header('Location: dashboard.php');
            exit;
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
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <title>Login Admin - YESNET</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(
                135deg,
                #0f172a,
                #1e3a8a,
                #2563eb
            );
        }

        .login-wrapper {
            min-height: 100vh;
        }

        .login-card {
            border: 0;
            border-radius: 18px;
            overflow: hidden;
            animation: fadeIn .5s ease;
        }

        .login-header {
            background: #ffffff;
            padding: 32px 24px 10px;
            text-align: center;
        }

        .login-body {
            padding: 24px 30px 30px;
        }

        .brand {
            font-size: 27px;
            font-weight: 800;
            color: #2563eb;
            letter-spacing: 1px;
        }

        .form-control {
            min-height: 46px;
            border-radius: 9px;
        }

        .btn-login {
            min-height: 47px;
            border-radius: 9px;
            font-weight: 600;
            background: #2563eb;
            border: none;
        }

        .btn-login:hover {
            background: #1d4ed8;
        }

        .login-footer {
            font-size: 12px;
            color: #64748b;
            text-align: center;
            margin-top: 22px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

</head>

<body>

<div class="container">

    <div class="row justify-content-center align-items-center login-wrapper">

        <div class="col-12 col-sm-10 col-md-6 col-lg-4">

            <div class="card shadow-lg login-card">

                <div class="login-header">

                    <div class="brand">
                        YESNET
                    </div>

                    <h4 class="mt-3 mb-1">
                        Administrator Login
                    </h4>

                    <p class="text-muted small mb-0">
                        Internal Management System
                    </p>

                </div>

                <div class="login-body">

                    <?php if ($error !== ''): ?>

                        <div
                            class="alert alert-danger alert-dismissible fade show"
                            role="alert"
                        >

                            <?= e($error) ?>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                                aria-label="Close"
                            ></button>

                        </div>

                    <?php endif; ?>

                    <form method="POST" action="" autocomplete="on">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(adminCsrfToken()) ?>"
                        >

                        <div class="mb-3">

                            <label
                                for="username"
                                class="form-label fw-semibold"
                            >
                                Username
                            </label>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="form-control"
                                placeholder="Masukkan username"
                                required
                                maxlength="100"
                                autocomplete="username"
                                autofocus
                            >

                        </div>

                        <div class="mb-4">

                            <label
                                for="password"
                                class="form-label fw-semibold"
                            >
                                Password
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                placeholder="Masukkan password"
                                required
                                autocomplete="current-password"
                            >

                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary btn-login w-100"
                        >
                            Login Administrator
                        </button>

                    </form>

                    <div class="login-footer">

                        YESNET Internal Management System

                        <br>

                        &copy; <?= date('Y') ?> YESNET

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>