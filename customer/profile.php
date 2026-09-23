<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireActiveCustomer($conn);

// Cek login
if (!isset($_SESSION['user_id'])) { 
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Ambil data user
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
$customer = $result->fetch_assoc();

if (!$customer) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Inisial nama
$initial = strtoupper(substr(trim($customer['username']), 0, 1));
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Profile Saya - WiFi Management</title>

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

    <!-- CSS Profile -->
    <link rel="stylesheet" href="assets/css/profile.css">
</head>

<body>

<div class="profile-page">

    <!-- TOP NAVIGATION -->
    <nav class="profile-navbar">

        <div class="nav-left">

            <a href="dashboard.php" class="back-dashboard">
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>
                <h5>Profile Saya</h5>
                <span>Kelola informasi akun kamu</span>
            </div>

        </div>

        <a href="dashboard.php" class="dashboard-link">
            <i class="bi bi-grid-fill"></i>
            Dashboard ku
        </a>

    </nav>


    <!-- MAIN CONTENT -->
    <main class="profile-container">

        <!-- PROFILE HERO -->
        <section class="profile-hero">

            <div class="hero-background"></div>

            <div class="hero-content">

                <div class="avatar-wrapper">

                    <div class="profile-avatar">
                        <?= htmlspecialchars($initial) ?>
                    </div>

                    <span class="online-status"></span>

                </div>

                <div class="profile-main-info">

                    <span class="customer-badge">
                        <i class="bi bi-patch-check-fill"></i>
                        Customer
                    </span>

                    <h1>
                        <?= htmlspecialchars($customer['username']) ?>
                    </h1>

                    <p>
                        <i class="bi bi-envelope"></i>
                        <?= htmlspecialchars($customer['email']) ?>
                    </p>

                </div>

                <a href="profile_edit.php" class="edit-profile-btn">

                    <i class="bi bi-pencil-square"></i>

                    <span>Edit Profile</span>

                </a>

            </div>

        </section>


        <!-- CONTENT GRID -->
        <div class="profile-grid">

            <!-- INFORMASI PRIBADI -->
            <section class="profile-card">

                <div class="card-header-custom">

                    <div class="card-title">

                        <div class="title-icon blue">
                            <i class="bi bi-person-vcard-fill"></i>
                        </div>

                        <div>
                            <h3>Informasi Pribadi</h3>
                            <p>Informasi dasar akun kamu</p>
                        </div>

                    </div>

                </div>


                <div class="card-body-custom">

                    <!-- Username -->
                    <div class="info-row">

                        <div class="info-icon">
                            <i class="bi bi-person-fill"></i>
                        </div>

                        <div class="info-content">
                            <span>Nama Pengguna</span>
                            <strong>
                                <?= htmlspecialchars($customer['username']) ?>
                            </strong>
                        </div>

                    </div>


                    <!-- Email -->
                    <div class="info-row">

                        <div class="info-icon">
                            <i class="bi bi-envelope-fill"></i>
                        </div>

                        <div class="info-content">
                            <span>Email</span>
                            <strong>
                                <?= htmlspecialchars($customer['email']) ?>
                            </strong>
                        </div>

                    </div>


                    <!-- Telephone -->
                    <div class="info-row">

                        <div class="info-icon">
                            <i class="bi bi-telephone-fill"></i>
                        </div>

                        <div class="info-content">
                            <span>Nomor Telepon</span>

                            <strong>
                                <?= !empty($customer['telephone'])
                                    ? htmlspecialchars($customer['telephone'])
                                    : '-'
                                ?>
                            </strong>

                        </div>

                    </div>


                    <!-- Alamat -->
                    <div class="info-row">

                        <div class="info-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>

                        <div class="info-content">

                            <span>Alamat</span>

                            <strong>
                                <?= !empty($customer['alamat'])
                                    ? htmlspecialchars($customer['alamat'])
                                    : 'Alamat belum diisi'
                                ?>
                            </strong>

                        </div>

                    </div>

                </div>

            </section>


            <!-- STATUS AKUN -->
            <section class="profile-card">

                <div class="card-header-custom">

                    <div class="card-title">

                        <div class="title-icon green">
                            <i class="bi bi-shield-check"></i>
                        </div>

                        <div>
                            <h3>Status Akun</h3>
                            <p>Status akun customer</p>
                        </div>

                    </div>

                </div>


                <div class="card-body-custom status-body">

                    <div class="account-status">

                        <div class="status-check">
                            <i class="bi bi-check-lg"></i>
                        </div>

                        <div>
                            <span>Status</span>
                            <strong>Akun Aktif</strong>
                        </div>

                    </div>


                    <div class="account-status">

                        <div class="status-icon">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>

                        <div>
                            <span>Tipe Akun</span>
                            <strong>Customer</strong>
                        </div>

                    </div>


                    <div class="account-status">

                        <div class="status-icon">
                            <i class="bi bi-calendar-check-fill"></i>
                        </div>

                        <div>

                            <span>Bergabung Sejak</span>

                            <strong>
                                <?=
                                    !empty($customer['created_at'])
                                    ? date('d F Y', strtotime($customer['created_at']))
                                    : '-'
                                ?>
                            </strong>

                        </div>

                    </div>

                </div>

            </section>


            <!-- AKSI CEPAT -->
            <section class="profile-card quick-card">

                <div class="card-header-custom">

                    <div class="card-title">

                        <div class="title-icon purple">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </div>

                        <div>
                            <h3>Aksi Cepat</h3>
                            <p>Akses layanan customer</p>
                        </div>

                    </div>

                </div>


                <div class="quick-actions">

                    <a href="installation.php" class="quick-action">

                        <div class="quick-icon blue">
                            <i class="bi bi-router-fill"></i>
                        </div>

                        <div>
                            <strong>Instalasi</strong>
                            <span>Lihat instalasi WiFi</span>
                        </div>

                        <i class="bi bi-chevron-right arrow"></i>

                    </a>


                    <a href="complaint.php" class="quick-action">

                        <div class="quick-icon orange">
                            <i class="bi bi-ticket-detailed-fill"></i>
                        </div>

                        <div>
                            <strong>Complaint</strong>
                            <span>Lihat laporan gangguan</span>
                        </div>

                        <i class="bi bi-chevron-right arrow"></i>

                    </a>


                    <a href="profile_edit.php" class="quick-action">

                        <div class="quick-icon purple">
                            <i class="bi bi-pencil-square"></i>
                        </div>

                        <div>
                            <strong>Edit Profile</strong>
                            <span>Perbarui informasi akun</span>
                        </div>

                        <i class="bi bi-chevron-right arrow"></i>

                    </a>

                </div>

            </section>

        </div>


        <!-- FOOTER -->
        <div class="profile-footer">

            <span>
                <i class="bi bi-shield-lock-fill"></i>
                Data akun kamu tersimpan dengan aman
            </span>

            <a href="../logout.php">
                Logout
                <i class="bi bi-box-arrow-right"></i>
            </a>

        </div>

    </main>

</div>

</body>
</html>