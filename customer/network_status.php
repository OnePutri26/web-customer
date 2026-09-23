<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];


/* =====================================================
   DATA CUSTOMER
===================================================== */

$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$customerId = $customer['id'];

$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);


/* =====================================================
   DATA NETWORK
===================================================== */

// IP customer
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// IP server
$serverIp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

// Host server
$serverHost = $_SERVER['SERVER_NAME'] ?? 'localhost';

// Protocol
$protocol = (
    !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
)
    ? 'HTTPS'
    : 'HTTP';

// Port
$serverPort = $_SERVER['SERVER_PORT'] ?? '80';


/* =====================================================
   CEK INTERNET SERVER
===================================================== */

function checkInternetConnection(): bool
{
    $connection = @fsockopen(
        "8.8.8.8",
        53,
        $errno,
        $errstr,
        2
    );

    if ($connection) {

        fclose($connection);

        return true;
    }

    return false;
}


$isOnline = checkInternetConnection();


/* =====================================================
   STATUS DATABASE
===================================================== */

$databaseStatus = false;

try {

    if ($conn && $conn->ping()) {
        $databaseStatus = true;
    }

} catch (Exception $e) {

    $databaseStatus = false;

}


/* =====================================================
   STATUS SERVICE
===================================================== */

$webStatus = true;

$networkStatus = (
    $isOnline &&
    $databaseStatus &&
    $webStatus
);


/* =====================================================
   LABEL STATUS
===================================================== */

$networkLabel =
    $networkStatus
        ? 'Jaringan Normal'
        : 'Terjadi Gangguan';


$networkDescription =
    $networkStatus
        ? 'Koneksi jaringan dan layanan berjalan normal.'
        : 'Terdapat layanan yang sedang mengalami gangguan.';


/* =====================================================
   WAKTU CHECK
===================================================== */

$checkedAt = date(
    'd M Y, H:i:s'
);


/* =====================================================
   FORMAT UPTIME CHECK
===================================================== */

$checkDuration = 'Normal';


/* =====================================================
   STATUS SERVICE CLASS
===================================================== */

function statusClass($status)
{
    return $status
        ? 'service-online'
        : 'service-offline';
}


function statusText($status)
{
    return $status
        ? 'Online'
        : 'Offline';
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

    <title>
        Status Jaringan | Customer Portal
    </title>


    <!-- =================================================
         BOOTSTRAP
    ================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =================================================
         BOOTSTRAP ICONS
    ================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- =================================================
         NETWORK STATUS CSS
    ================================================== -->

    <link
        rel="stylesheet"
        href="assets/css/customer-dashboard.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/network_status.css"
    >

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside class="sidebar">

    <div class="sidebar-logo">

        <div class="logo-icon">

            <i class="bi bi-wifi"></i>

        </div>

        <div class="logo-text">

            <h5>
                WiFi Management
            </h5>

            <span>
                Customer Portal
            </span>

        </div>

    </div>


    <div class="sidebar-menu">

        <p class="menu-title">
            MENU
        </p>


        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="menu-item"
        >

            <i class="bi bi-grid-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <!-- Tagihan -->

        <a
            href="billing.php"
            class="menu-item"
        >

            <i class="bi bi-credit-card-fill"></i>

            <span>
                Tagihan
            </span>

        </a>


        <!-- Pemakaian -->

        <a
            href="usage.php"
            class="menu-item"
        >

            <i class="bi bi-speedometer2"></i>

            <span>
                Pemakaian
            </span>

        </a>


        <!-- Speed Test -->

        <a
            href="speedtest.php"
            class="menu-item"
        >

            <i class="bi bi-lightning-charge-fill"></i>

            <span>
                Speed Test
            </span>

        </a>


        <!-- Gangguan -->

        <a
            href="complaint.php"
            class="menu-item"
        >

            <i class="bi bi-tools"></i>

            <span>
                Gangguan
            </span>

        </a>


        <!-- Status Jaringan -->

        <a
            href="network_status.php"
            class="menu-item active"
        >

            <i class="bi bi-globe2"></i>

            <span>
                Status Jaringan
            </span>

        </a>


        <!-- Chat -->

        <a
            href="chat.php"
            class="menu-item"
        >

            <i class="bi bi-chat-dots-fill"></i>

            <span>
                Chat CS
            </span>

        </a>


        <p class="menu-title menu-account">
            AKUN
        </p>


        <!-- Upgrade -->

        <a
            href="upgrade.php"
            class="menu-item"
        >

            <i class="bi bi-arrow-up-circle-fill"></i>

            <span>
                Upgrade Paket
            </span>

        </a>


        <!-- Layanan -->

        <a
            href="service_request.php"
            class="menu-item"
        >

            <i class="bi bi-plus-circle-fill"></i>

            <span>
                Layanan Tambahan
            </span>

        </a>


        <!-- Profile -->

        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile Saya
            </span>

        </a>


        <!-- Logout -->

        <a
            href="../logout.php"
            class="menu-item logout"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


    <!-- =================================================
         USER SIDEBAR
    ================================================== -->

    <div class="sidebar-user">

        <div class="user-avatar">

            <?= htmlspecialchars($initial) ?>

        </div>


        <div class="user-detail">

            <strong>
                <?= htmlspecialchars($nama) ?>
            </strong>

            <span>
                Customer
            </span>

        </div>

    </div>

</aside>



<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<main class="main-content">


    <!-- =================================================
         TOPBAR
    ================================================== -->

    <header class="topbar">

        <div>

            <h4>
                Status Jaringan
            </h4>

            <span>
                Pantau kondisi jaringan internet kamu
            </span>

        </div>


        <div class="topbar-right">


            <!-- Notification -->

            <div class="notification">

                <i class="bi bi-bell"></i>

            </div>


            <!-- Profile -->

            <a
                href="profile.php"
                class="top-profile"
            >

                <div class="top-avatar">

                    <?= htmlspecialchars($initial) ?>

                </div>


                <div class="top-user">

                    <strong>
                        <?= htmlspecialchars($nama) ?>
                    </strong>

                    <span>
                        Customer
                    </span>

                </div>

            </a>

        </div>

    </header>



    <!-- =================================================
         CONTENT
    ================================================== -->

    <div class="content network-content">


        <!-- =================================================
             PAGE HEADER
        ================================================== -->

        <div class="page-heading">

            <div>

                <span class="page-eyebrow">
                    NETWORK MONITORING
                </span>

                <h1>
                    Status Jaringan
                </h1>

                <p>
                    Pantau kondisi koneksi dan layanan jaringan
                    internet kamu secara berkala.
                </p>

            </div>


            <button
                type="button"
                class="refresh-button"
                onclick="window.location.reload()"
            >

                <i class="bi bi-arrow-clockwise"></i>

                Refresh Status

            </button>

        </div>



        <!-- =================================================
             MAIN STATUS
        ================================================== -->

        <section
            class="
                network-main-status
                <?= $networkStatus ? 'status-normal' : 'status-danger' ?>
            "
        >


            <div class="main-status-left">


                <div class="main-status-icon">

                    <?php if ($networkStatus): ?>

                        <i class="bi bi-check-lg"></i>

                    <?php else: ?>

                        <i class="bi bi-exclamation-lg"></i>

                    <?php endif; ?>

                </div>


                <div class="main-status-info">

                    <span>
                        STATUS JARINGAN
                    </span>

                    <h2>
                        <?= htmlspecialchars($networkLabel) ?>
                    </h2>

                    <p>
                        <?= htmlspecialchars($networkDescription) ?>
                    </p>

                </div>

            </div>


            <div class="status-live">

                <span class="live-dot"></span>

                <?= $networkStatus ? 'ONLINE' : 'OFFLINE' ?>

            </div>

        </section>



        <!-- =================================================
             SERVICE STATUS
        ================================================== -->

        <section class="dashboard-card service-card">


            <div class="card-title">

                <div>

                    <h5>
                        Status Layanan
                    </h5>

                    <span>
                        Kondisi layanan yang digunakan oleh customer
                    </span>

                </div>


                <span class="live-text">
                    ● MONITORING
                </span>

            </div>



            <div class="service-grid">


                <!-- INTERNET -->

                <div class="service-box">

                    <div
                        class="service-icon
                        <?= $isOnline
                            ? 'icon-online'
                            : 'icon-offline'
                        ?>"
                    >

                        <i class="bi bi-globe2"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Internet
                        </strong>

                        <span>
                            Koneksi internet server
                        </span>

                    </div>


                    <div
                        class="<?= statusClass($isOnline) ?>"
                    >

                        <span></span>

                        <?= statusText($isOnline) ?>

                    </div>

                </div>



                <!-- DATABASE -->

                <div class="service-box">

                    <div
                        class="service-icon
                        <?= $databaseStatus
                            ? 'icon-online'
                            : 'icon-offline'
                        ?>"
                    >

                        <i class="bi bi-database-check"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Database
                        </strong>

                        <span>
                            Koneksi database sistem
                        </span>

                    </div>


                    <div
                        class="<?= statusClass($databaseStatus) ?>"
                    >

                        <span></span>

                        <?= statusText($databaseStatus) ?>

                    </div>

                </div>



                <!-- WEB SERVER -->

                <div class="service-box">

                    <div class="service-icon icon-online">

                        <i class="bi bi-hdd-rack"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Web Server
                        </strong>

                        <span>
                            Server aplikasi customer
                        </span>

                    </div>


                    <div class="service-online">

                        <span></span>

                        Online

                    </div>

                </div>


            </div>

        </section>

        <!-- =================================================
             CONNECTION SUMMARY
        ================================================== -->

        <section class="dashboard-card network-summary">


            <div class="summary-icon">

                <i class="bi bi-wifi"></i>

            </div>


            <div class="summary-content">

                <span>
                    CONNECTION SUMMARY
                </span>

                <h3>
                    <?= $networkStatus
                        ? 'Koneksi internet kamu berjalan normal'
                        : 'Koneksi sedang mengalami gangguan'
                    ?>
                </h3>

                <p>
                    <?= $networkStatus
                        ? 'Semua layanan utama saat ini terpantau aktif. Kamu dapat menggunakan layanan internet seperti biasa.'
                        : 'Beberapa layanan tidak dapat diakses. Silakan coba refresh halaman atau hubungi Customer Service jika masalah berlanjut.'
                    ?>
                </p>

            </div>


            <a
                href="chat.php"
                class="btn-chat"
            >

                <i class="bi bi-headset"></i>

                Hubungi CS

            </a>

        </section>



        <!-- =================================================
             FOOTER INFO
        ================================================== -->

        <div class="network-footer">

            <i class="bi bi-info-circle"></i>

            Status jaringan diperbarui setiap kali halaman
            dimuat atau tombol refresh ditekan.

        </div>


    </div>

</main>


</body>

</html>
