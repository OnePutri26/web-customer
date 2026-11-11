<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

$error = (string) ($_SESSION['admin_login_error'] ?? '');
unset($_SESSION['admin_login_error']);

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $existingUserId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $existingUserId);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        $existingUser &&
        strtolower(trim((string) ($existingUser['role'] ?? ''))) === 'admin' &&
        strtolower(trim((string) ($_SESSION['role'] ?? ''))) === 'admin' &&
        in_array(strtolower(trim((string) ($existingUser['status'] ?? ''))), ['1', 'active', 'aktif'], true)
    ) {
        header('Location: dashboard.php');
        exit;
    }

    adminClearSession();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!adminVerifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Form login kedaluwarsa. Muat ulang halaman lalu coba kembali.';
    } elseif ($username === '' || $password === '') {

        $error = "Username dan password wajib diisi.";

    } else {

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

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {

            $error = "Username atau password salah.";

        } elseif (!password_verify($password, $user['password'])) {

            $error = "Username atau password salah.";

        } elseif (!in_array(strtolower(trim((string) ($user['status'] ?? ''))), ['1', 'active', 'aktif'], true)) {

            $error = "Akun sedang tidak aktif.";

        } elseif (strtolower(trim((string) ($user['role'] ?? ''))) !== 'admin') {

            $error = "Akun tidak memiliki akses administrator.";

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = 'admin';
            $_SESSION['user_status'] = $user['status'];
            $_SESSION['admin_permissions'] = adminPermissionsForRole('admin');
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

            header("Location: dashboard.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Login Admin - WiFi Management</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">

        <div class="col-md-5">

            <div class="card shadow border-0">

                <div class="card-body p-4">

                    <h3 class="text-center mb-4">
                        Admin Login
                    </h3>

                    <?php if ($error !== ""): ?>

                        <div class="alert alert-danger">
                            <?= htmlspecialchars($error) ?>
                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= htmlspecialchars(adminCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <div class="mb-3">
                            <label class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                required
                                autocomplete="username"
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                required
                                autocomplete="current-password"
                            >
                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >
                            Login Admin
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>
</div>

</body>
</html>