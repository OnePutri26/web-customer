<?php

session_start();

require_once "../config/database.php";

if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role']) &&
    $_SESSION['role'] === 'admin'
) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

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

        } elseif ((int)$user['status'] !== 1) {

            $error = "Akun sedang tidak aktif.";

        } elseif ($user['role'] !== 'admin') {

            $error = "Akun tidak memiliki akses administrator.";

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_status'] = $user['status'];

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