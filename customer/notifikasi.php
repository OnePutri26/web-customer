<?php
session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

/*
|--------------------------------------------------------------------------
| CEK LOGIN CUSTOMER
|--------------------------------------------------------------------------
| Jangan langsung bergantung pada satu nama session.
| Beberapa project memakai id_user, sebagian memakai id_user.
|--------------------------------------------------------------------------
*/

$userId = 0;

if (isset($_SESSION['id_user'])) {
    $userId = (int) $_SESSION['id_user'];
} elseif (isset($_SESSION['id_user'])) {
    $userId = (int) $_SESSION['id_user'];
}

/*
|--------------------------------------------------------------------------
| CEK ROLE
|--------------------------------------------------------------------------
*/

$sessionRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

if ($sessionRole !== '' && strtolower($sessionRole) !== 'customer') {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$customerName = "Customer";

$customerStmt = mysqli_prepare(
    $conn,
    "SELECT nama FROM customers WHERE id_user = ? LIMIT 1"
);

if ($customerStmt) {

    mysqli_stmt_bind_param(
        $customerStmt,
        "i",
        $userId
    );

    mysqli_stmt_execute($customerStmt);

    $customerResult = mysqli_stmt_get_result($customerStmt);

    if ($customerResult && mysqli_num_rows($customerResult) > 0) {

        $customerData = mysqli_fetch_assoc($customerResult);

        if (!empty($customerData['nama'])) {
            $customerName = $customerData['nama'];
        }
    }

    mysqli_stmt_close($customerStmt);
}

/*
|--------------------------------------------------------------------------
| MARK SEMUA NOTIFIKASI SUDAH DIBACA
|--------------------------------------------------------------------------
*/

if (isset($_GET['read_all']) && $_GET['read_all'] === '1') {

    $updateQuery = "
        UPDATE notifications
        SET dibaca = 1
        WHERE id_user = ? OR id_user IS NULL
    ";

    $updateStmt = mysqli_prepare(
        $conn,
        $updateQuery
    );

    if ($updateStmt) {

        mysqli_stmt_bind_param(
            $updateStmt,
            "i",
            $userId
        );

        mysqli_stmt_execute($updateStmt);

        mysqli_stmt_close($updateStmt);
    }

    header("Location: notifikasi.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL NOTIFIKASI
|--------------------------------------------------------------------------
|
| id_user = user tertentu
| id_user IS NULL = notifikasi untuk semua customer
|
*/

$notifications = [];

$query = "
    SELECT
        id,
        id_user,
        judul,
        message,
        type,
        dibaca,
        created_at
    FROM notifications
    WHERE id_user = ? OR id_user IS NULL
    ORDER BY created_at DESC
";

$stmt = mysqli_prepare(
    $conn,
    $query
);

if (!$stmt) {
    die(
        "Gagal menyiapkan query notifikasi: " .
        htmlspecialchars(mysqli_error($conn))
    );
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $userId
);

if (!mysqli_stmt_execute($stmt)) {
    die(
        "Gagal menjalankan query notifikasi: " .
        htmlspecialchars(mysqli_stmt_error($stmt))
    );
}

$result = mysqli_stmt_get_result($stmt);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
    }
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| HITUNG NOTIFIKASI BELUM DIBACA
|--------------------------------------------------------------------------
*/

$unreadCount = 0;

foreach ($notifications as $notification) {

    if ((int) $notification['dibaca'] === 0) {
        $unreadCount++;
    }
}

/*
|--------------------------------------------------------------------------
| DATA TYPE NOTIFIKASI
|--------------------------------------------------------------------------
*/

$typeDataList = [

    'promo' => [
        'icon' => 'bi-gift-fill',
        'name' => 'Promo'
    ],

    'gangguan' => [
        'icon' => 'bi-exclamation-triangle-fill',
        'name' => 'Gangguan'
    ],

    'pembayaran' => [
        'icon' => 'bi-credit-card-fill',
        'name' => 'Pembayaran'
    ],

    'info' => [
        'icon' => 'bi-info-circle-fill',
        'name' => 'Informasi'
    ]
];

/*
|--------------------------------------------------------------------------
| FORMAT WAKTU
|--------------------------------------------------------------------------
*/

function formatNotificationDate($date)
{
    if (empty($date)) {
        return "-";
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return htmlspecialchars($date);
    }

    return date('d M Y, H:i', $timestamp);
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

    <title>Notifikasi - Customer</title>

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- CSS Notifikasi -->
    <link
        rel="stylesheet"
        href="assets/css/notifikasi.css"
    >

</head>

<body>

<div class="dashboard">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">

        <div class="sidebar-header">

            <div class="logo-box">
                <i class="bi bi-wifi"></i>
            </div>

            <div>
                <h2>My WiFi</h2>
                <span>Customer Portal</span>
            </div>

        </div>


        <!-- MENU -->

        <nav class="sidebar-menu">

            <a href="dashboard.php">
                <i class="bi bi-grid-fill"></i>
                <span>Dashboard</span>
            </a>

            <a href="billing.php">
                <i class="bi bi-receipt"></i>
                <span>Tagihan</span>
            </a>

            <a href="speedtest.php">
                <i class="bi bi-speedometer2"></i>
                <span>Speed Test</span>
            </a>

            <a href="complaint.php">
                <i class="bi bi-headset"></i>
                <span>Pengaduan</span>
            </a>

            <a href="network_status.php">
                <i class="bi bi-router-fill"></i>
                <span>Status Jaringan</span>
            </a>

            <a
                href="notifikasi.php"
                class="active"
            >
                <i class="bi bi-bell-fill"></i>
                <span>Notifikasi</span>

                <?php if ($unreadCount > 0): ?>

                    <span class="menu-badge">
                        <?= $unreadCount; ?>
                    </span>

                <?php endif; ?>

            </a>

            <a href="profile.php">
                <i class="bi bi-person-circle"></i>
                <span>Profil</span>
            </a>

        </nav>


        <!-- SIDEBAR FOOTER -->

        <div class="sidebar-footer">

            <div class="user-mini">

                <div class="user-avatar">
                    <?= strtoupper(substr($customerName, 0, 1)); ?>
                </div>

                <div class="user-info">

                    <strong>
                        <?= htmlspecialchars($customerName); ?>
                    </strong>

                    <span>Customer</span>

                </div>

            </div>

            <a
                href="../logout.php"
                class="logout-btn"
            >
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="topbar-left">

                <div class="page-title">

                    <span>Customer Portal</span>

                    <h1>Notifikasi</h1>

                </div>

            </div>


            <div class="topbar-right">

                <!-- BELL -->

                <a
                    href="notifikasi.php"
                    class="notification-btn"
                    title="Notifikasi"
                    aria-label="Notifikasi"
                >

                    <i class="bi bi-bell-fill"></i>

                    <?php if ($unreadCount > 0): ?>

                        <span class="notification-badge">
                            <?= $unreadCount; ?>
                        </span>

                    <?php endif; ?>

                </a>


                <!-- PROFILE -->

                <a
                    href="profile.php"
                    class="profile-btn"
                >

                    <div class="profile-avatar">
                        <?= strtoupper(substr($customerName, 0, 1)); ?>
                    </div>

                    <div class="profile-text">

                        <strong>
                            <?= htmlspecialchars($customerName); ?>
                        </strong>

                        <span>Customer</span>

                    </div>

                    <i class="bi bi-chevron-down"></i>

                </a>

            </div>

        </header>


        <!-- =================================================
             PAGE
        ================================================== -->

        <section class="notification-page">

            <div class="notification-header">

                <div>

                    <span class="section-label">
                        PUSAT INFORMASI
                    </span>

                    <h2>Notifikasi Anda</h2>

                    <p>
                        Informasi terbaru mengenai layanan,
                        pembayaran, gangguan, dan promo.
                    </p>

                </div>


                <?php if ($unreadCount > 0): ?>

                    <a
                        href="notifikasi.php?read_all=1"
                        class="read-all-btn"
                    >

                        <i class="bi bi-check2-all"></i>

                        Tandai semua sudah dibaca

                    </a>

                <?php endif; ?>

            </div>


            <!-- STAT -->

            <div class="notification-stat">

                <div class="stat-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>

                <div>

                    <span>Total Notifikasi</span>

                    <strong>
                        <?= count($notifications); ?>
                    </strong>

                </div>


                <div class="stat-divider"></div>


                <div class="stat-icon unread-icon">
                    <i class="bi bi-envelope-fill"></i>
                </div>

                <div>

                    <span>Belum Dibaca</span>

                    <strong>
                        <?= $unreadCount; ?>
                    </strong>

                </div>

            </div>


            <!-- =================================================
                 LIST NOTIFIKASI
            ================================================== -->

            <div class="notification-list">

                <?php if (empty($notifications)): ?>

                    <!-- EMPTY -->

                    <div class="empty-notification">

                        <div class="empty-icon">
                            <i class="bi bi-bell-slash"></i>
                        </div>

                        <h3>Belum Ada Notifikasi</h3>

                        <p>
                            Saat ini belum ada informasi baru
                            untuk akun Anda.
                        </p>

                    </div>

                <?php else: ?>

                    <?php foreach ($notifications as $notification): ?>

                        <?php

                        $type = strtolower(
                            trim($notification['type'] ?? 'info')
                        );

                        if (!isset($typeDataList[$type])) {
                            $type = 'info';
                        }

                        $typeData = $typeDataList[$type];

                        $isRead = (int) (
                            $notification['dibaca'] ?? 0
                        );

                        ?>

                        <article
                            class="notification-card <?= $isRead === 0 ? 'unread' : ''; ?>"
                        >

                            <div class="notification-icon type-<?= htmlspecialchars($type); ?>">

                                <i class="bi <?= htmlspecialchars($typeData['icon']); ?>"></i>

                            </div>


                            <div class="notification-content">

                                <div class="notification-top">

                                    <div class="notification-type">

                                        <span class="type-badge type-<?= htmlspecialchars($type); ?>">

                                            <i class="bi <?= htmlspecialchars($typeData['icon']); ?>"></i>

                                            <?= htmlspecialchars($typeData['name']); ?>

                                        </span>

                                        <?php if ($isRead === 0): ?>

                                            <span class="new-badge">
                                                BARU
                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <time>

                                        <i class="bi bi-clock"></i>

                                        <?= formatNotificationDate($notification['created_at']); ?>

                                    </time>

                                </div>


                                <h3>
                                    <?= htmlspecialchars(
                                        $notification['judul'] ?? 'Notifikasi'
                                    ); ?>
                                </h3>


                                <p>
                                    <?= nl2br(
                                        htmlspecialchars(
                                            $notification['message'] ?? ''
                                        )
                                    ); ?>
                                </p>

                            </div>


                            <?php if ($isRead === 0): ?>

                                <div class="unread-dot"></div>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
