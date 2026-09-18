<?php

session_start();
require_once '../config/database.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$message = "";
$error = "";

$stmt = $conn->prepare("
    SELECT id, 
        nama,
        username,
        email,
        telephone,
        password,
        role,
        status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $nama    = trim($_POST['nama'] ?? '');


    // Validasi
    if ($username === '') {

        $error = "Nama pengguna wajib diisi.";

    } elseif ($email === '') {

        $error = "Email wajib diisi.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Format email tidak valid.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        $update = $conn->prepare("
            UPDATE users
            SET username = ?,
                email = ?,
                telephone = ?,
                nama = ?
            WHERE id = ?
        ");

        $update->bind_param(
            "ssssi",
            $username,
            $email,
            $telephone,
            $nama,
            $user_id
        );


        if ($update->execute()) {

            // Update session
            $_SESSION['nama'] = $username;
            $_SESSION['username'] = $username;

            $message = "Profile berhasil diperbarui.";

            // Update data yang ditampilkan
            $user['username']  = $username;
            $user['email']     = $email;
            $user['telephone'] = $telephone;
            $user['nama']    = $nama;

        } else {

            $error = "Gagal memperbarui profile.";

        }

        $update->close();
    }
}


// Inisial avatar
$initial = strtoupper(
    substr(
        trim($user['username'] ?? 'U'),
        0,
        1
    )
);

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Edit Profile - WiFi Management</title>


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


    <!-- CSS -->
    <link
        rel="stylesheet"
        href="assets/css/profile-edit.css"
    >

</head>


<body>


<div class="edit-page">

    <nav class="edit-navbar">

        <div class="nav-left">

            <a
                href="profile.php"
                class="back-button"
            >
                <i class="bi bi-arrow-left"></i>
            </a>


            <div class="nav-title">

                <h5>Edit Profile</h5>

                <span>
                    Perbarui informasi akun kamu
                </span>

            </div>

        </div>


        <a
            href="dashboard.php"
            class="dashboard-button"
        >

            <i class="bi bi-grid-fill"></i>

            <span>Dashboard</span>

        </a>

    </nav>



    <!-- =====================================
         MAIN
    ====================================== -->

    <main class="edit-container">


        <!-- =====================================
             HEADER PROFILE
        ====================================== -->

        <section class="edit-hero">

            <div class="hero-circle circle-one"></div>

            <div class="hero-circle circle-two"></div>


            <div class="hero-content">

                <div class="avatar-wrapper">

                    <div class="profile-avatar">
                        <?= htmlspecialchars($initial) ?>
                    </div>

                    <div class="avatar-camera">
                        <i class="bi bi-pencil-fill"></i>
                    </div>

                </div>


                <div class="hero-info">

                    <span class="customer-label">

                        <i class="bi bi-person-check-fill"></i>

                        CUSTOMER ACCOUNT

                    </span>


                    <h1>
                        <?= htmlspecialchars($user['username']) ?>
                    </h1>


                    <p>
                        Perbarui informasi pribadi kamu di sini.
                    </p>

                </div>

            </div>

        </section>



        <!-- =====================================
             FORM CARD
        ====================================== -->

        <section class="edit-card">


            <!-- CARD HEADER -->

            <div class="edit-card-header">

                <div class="header-icon">

                    <i class="bi bi-person-vcard-fill"></i>

                </div>


                <div>

                    <h2>Informasi Profile</h2>

                    <p>
                        Pastikan informasi yang kamu masukkan sudah benar.
                    </p>

                </div>

            </div>



            <!-- CARD BODY -->

            <div class="edit-card-body">


                <!-- SUCCESS -->

                <?php if ($message): ?>

                    <div class="custom-alert success-alert">

                        <div class="alert-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <div>

                            <strong>Berhasil</strong>

                            <span>
                                <?= htmlspecialchars($message) ?>
                            </span>

                        </div>

                    </div>

                <?php endif; ?>



                <!-- ERROR -->

                <?php if ($error): ?>

                    <div class="custom-alert error-alert">

                        <div class="alert-icon">
                            <i class="bi bi-exclamation-lg"></i>
                        </div>

                        <div>

                            <strong>Terjadi Kesalahan</strong>

                            <span>
                                <?= htmlspecialchars($error) ?>
                            </span>

                        </div>

                    </div>

                <?php endif; ?>



                <form method="POST">


                    <!-- =====================================
                         USERNAME
                    ====================================== -->

                    <div class="form-group">

                        <label for="username">

                            <i class="bi bi-person-fill"></i>

                            Nama Pengguna

                        </label>


                        <div class="input-box">

                            <i class="bi bi-person input-icon"></i>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                                placeholder="Masukkan nama pengguna"
                                autocomplete="username"
                                required
                            >

                        </div>

                    </div>



                    <!-- =====================================
                         EMAIL
                    ====================================== -->

                    <div class="form-group">

                        <label for="email">

                            <i class="bi bi-envelope-fill"></i>

                            Email

                        </label>


                        <div class="input-box">

                            <i class="bi bi-envelope input-icon"></i>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                placeholder="contoh@email.com"
                                autocomplete="email"
                                required
                            >

                        </div>

                    </div>



                    <!-- =====================================
                         TELEPHONE
                    ====================================== -->

                    <div class="form-group">

                        <label for="telephone">

                            <i class="bi bi-telephone-fill"></i>

                            Nomor Telepon

                        </label>


                        <div class="input-box">

                            <i class="bi bi-phone input-icon"></i>

                            <input
                                type="text"
                                id="telephone"
                                name="telephone"
                                value="<?= htmlspecialchars($user['telephone'] ?? '') ?>"
                                placeholder="08xxxxxxxxxx"
                                autocomplete="tel"
                            >

                        </div>

                    </div>



                    <!-- =====================================
                         ALAMAT
                    ====================================== -->

                    <div class="form-group">

                        <label for="alamat">

                            <i class="bi bi-geo-alt-fill"></i>

                            Alamat

                        </label>


                        <div class="textarea-box">

                            <i class="bi bi-geo-alt input-icon"></i>

                            <textarea
                                id="alamat"
                                name="alamat"
                                rows="4"
                                placeholder="Masukkan alamat lengkap"
                            ><?= htmlspecialchars($user['alamat'] ?? '') ?></textarea>

                        </div>

                    </div>



                    <!-- =====================================
                         BUTTON
                    ====================================== -->

                    <div class="form-actions">


                        <a
                            href="profile.php"
                            class="cancel-button"
                        >

                            <i class="bi bi-x-lg"></i>

                            Batal

                        </a>


                        <button
                            type="submit"
                            class="save-button"
                        >

                            <i class="bi bi-check-lg"></i>

                            Simpan Perubahan

                        </button>


                    </div>


                </form>

            </div>

        </section>



        <!-- =====================================
             SECURITY INFO
        ====================================== -->

        <div class="security-info">

            <div class="security-icon">

                <i class="bi bi-shield-check"></i>

            </div>


            <div>

                <strong>Informasi akun aman</strong>

                <span>
                    Perubahan profile hanya dapat dilakukan oleh pemilik akun.
                </span>

            </div>

        </div>


    </main>

</div>


</body>

</html>