<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireCustomerCanChoosePackage($conn);


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}


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
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        nama,
        telephone,
        email,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Query customer gagal: " . e($conn->error));
}

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();

$customer = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| CUSTOMER TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$customer) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$customerId = (int) ($customer['id'] ?? 0);

$nama = trim(
    (string) ($customer['nama'] ?? '')
);

if ($nama === '') {
    $nama = trim(
        (string) ($_SESSION['nama'] ?? '')
    );
}

if ($nama === '') {
    $nama = trim(
        (string) ($_SESSION['username'] ?? '')
    );
}

if ($nama === '') {
    $nama = 'Customer';
}


$telephone = trim(
    (string) ($customer['telephone'] ?? '')
);

$email = trim(
    (string) ($customer['email'] ?? '')
);


$statusLangganan = strtolower(
    trim(
        (string) (
            $customer['status_langganan']
            ?? 'belum_berlangganan'
        )
    )
);


/*
|--------------------------------------------------------------------------
| JIKA SUDAH AKTIF
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $statusLangganan,
        [
            'aktif',
            'active',
            '1'
        ],
        true
    )
) {
    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| JIKA SUDAH MENUNGGU PEMBAYARAN
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $statusLangganan,
        [
            'menunggu_pembayaran',
            'pending_pembayaran'
        ],
        true
    )
) {
    header("Location: payment.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| INITIAL
|--------------------------------------------------------------------------
*/

$initial = strtoupper(
    substr(
        $nama,
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

    <meta
        name="description"
        content="Mulai berlangganan layanan WiFi"
    >

    <title>
        Mulai Berlangganan | WiFi Management
    </title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- CSS -->

    <link
        rel="stylesheet"
        href="assets/css/langganan.css?v=200"
    >

</head>


<body>


<!-- =========================================================
     BACKGROUND
========================================================= -->

<div class="page-background">

    <div class="background-orb orb-one"></div>

    <div class="background-orb orb-two"></div>

    <div class="background-grid"></div>

</div>


<!-- =========================================================
     PAGE
========================================================= -->

<div class="subscription-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="topbar">

        <div class="topbar-inner">


            <!-- BRAND -->

            <a
                href="langganan.php"
                class="brand"
            >

                <div class="brand-icon">

                    <i class="bi bi-wifi"></i>

                </div>


                <div class="brand-copy">

                    <strong>
                        WiFi Management
                    </strong>

                    <span>
                        Customer Portal
                    </span>

                </div>

            </a>


            <!-- USER -->

            <div class="topbar-right">


                <div class="user-mini">


                    <div class="user-avatar">

                        <?= e($initial) ?>

                    </div>


                    <div class="user-info">

                        <strong>
                            <?= e($nama) ?>
                        </strong>

                        <span>
                            Customer
                        </span>

                    </div>

                </div>


                <div class="topbar-divider"></div>


                <a
                    href="../logout.php"
                    class="logout-btn"
                >

                    <i class="bi bi-box-arrow-right"></i>

                    <span>
                        Keluar
                    </span>

                </a>

            </div>

        </div>

    </header>



    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="main-container">


        <!-- =================================================
             BREADCRUMB
        ================================================== -->

        <div class="breadcrumb">

            <a href="dashboard.php">

                <i class="bi bi-house"></i>

                Dashboard

            </a>

            <i class="bi bi-chevron-right"></i>

            <span>
                Berlangganan
            </span>

        </div>



        <!-- =================================================
             HERO
        ================================================== -->

        <section class="hero-section">


            <!-- LEFT -->

            <div class="hero-content">


                <div class="hero-badge">

                    <span class="badge-dot"></span>

                    LAYANAN INTERNET HOME

                </div>


                <h1>

                    Internet Cepat.

                    <span>
                        Hidup Lebih Lancar.
                    </span>

                </h1>


                <p class="hero-description">

                    Pilih paket WiFi yang sesuai dengan
                    kebutuhanmu. Proses berlangganan
                    mudah, cepat, dan semuanya bisa
                    dilakukan langsung dari Customer Portal.

                </p>


                <!-- CTA -->

                <div class="hero-actions">

                    <a
                        href="packages.php"
                        class="primary-button"
                    >

                        <span>

                            <i class="bi bi-box-seam"></i>

                            Pilih Paket WiFi

                        </span>

                        <i class="bi bi-arrow-right"></i>

                    </a>


                    <a
                        href="#cara-berlangganan"
                        class="secondary-button"
                    >

                        <i class="bi bi-play-circle"></i>

                        Lihat Cara Berlangganan

                    </a>

                </div>


                <!-- TRUST -->

                <div class="hero-trust">


                    <div class="trust-item">

                        <div class="trust-icon">

                            <i class="bi bi-lightning-charge-fill"></i>

                        </div>

                        <div>

                            <strong>
                                Cepat
                            </strong>

                            <span>
                                Aktivasi mudah
                            </span>

                        </div>

                    </div>


                    <div class="trust-line"></div>


                    <div class="trust-item">

                        <div class="trust-icon">

                            <i class="bi bi-shield-check"></i>

                        </div>

                        <div>

                            <strong>
                                Aman
                            </strong>

                            <span>
                                Data terlindungi
                            </span>

                        </div>

                    </div>


                    <div class="trust-line"></div>


                    <div class="trust-item">

                        <div class="trust-icon">

                            <i class="bi bi-headset"></i>

                        </div>

                        <div>

                            <strong>
                                Support
                            </strong>

                            <span>
                                Bantuan customer
                            </span>

                        </div>

                    </div>

                </div>

            </div>



            <!-- RIGHT HERO CARD -->

            <div class="hero-visual">


                <div class="visual-glow"></div>


                <div class="router-card">


                    <div class="router-top">

                        <span class="live-status">

                            <span></span>

                            SYSTEM ONLINE

                        </span>


                        <i class="bi bi-three-dots"></i>

                    </div>


                    <div class="router-center">


                        <div class="wifi-ring ring-one"></div>

                        <div class="wifi-ring ring-two"></div>

                        <div class="wifi-ring ring-three"></div>


                        <div class="router-icon">

                            <i class="bi bi-wifi"></i>

                        </div>


                    </div>


                    <div class="router-title">

                        <strong>
                            WiFi Connection
                        </strong>

                        <span>
                            Siap untuk digunakan
                        </span>

                    </div>


                    <div class="router-stats">


                        <div>

                            <span>
                                STATUS
                            </span>

                            <strong>
                                Ready
                            </strong>

                        </div>


                        <div>

                            <span>
                                SUPPORT
                            </span>

                            <strong>
                                24/7
                            </strong>

                        </div>


                        <div>

                            <span>
                                SERVICE
                            </span>

                            <strong>
                                Home
                            </strong>

                        </div>

                    </div>


                </div>


                <!-- FLOATING CARD -->

                <div class="floating-card floating-speed">

                    <div class="floating-icon">

                        <i class="bi bi-speedometer2"></i>

                    </div>

                    <div>

                        <span>
                            Kecepatan
                        </span>

                        <strong>
                            Sesuai Paket
                        </strong>

                    </div>

                </div>


                <div class="floating-card floating-secure">

                    <div class="floating-icon">

                        <i class="bi bi-shield-fill-check"></i>

                    </div>

                    <div>

                        <span>
                            Keamanan
                        </span>

                        <strong>
                            Terjamin
                        </strong>

                    </div>

                </div>

            </div>

        </section>



        <!-- =================================================
             SECTION HEADING
        ================================================== -->

        <section
            class="section-heading"
            id="cara-berlangganan"
        >

            <div class="section-label">

                <i class="bi bi-signpost-2"></i>

                CARA BERLANGGANAN

            </div>


            <h2>

                Hanya beberapa langkah
                <span>untuk terhubung</span>

            </h2>


            <p>

                Kami buat proses berlangganan sesederhana
                mungkin. Tidak perlu ribet berpindah halaman
                tanpa arah seperti website tahun 2009.

            </p>

        </section>



        <!-- =================================================
             PROCESS
        ================================================== -->

        <section class="process-wrapper">


            <div class="process-line"></div>


            <!-- STEP 1 -->

            <div class="process-card active">

                <div class="process-number">
                    01
                </div>


                <div class="process-icon">

                    <i class="bi bi-box-seam"></i>

                </div>


                <div class="process-content">

                    <span>
                        LANGKAH PERTAMA
                    </span>

                    <h3>
                        Pilih Paket
                    </h3>

                    <p>
                        Tentukan paket WiFi yang paling
                        sesuai dengan kebutuhanmu.
                    </p>

                </div>

            </div>



            <!-- STEP 2 -->

            <div class="process-card">

                <div class="process-number">
                    02
                </div>


                <div class="process-icon">

                    <i class="bi bi-file-earmark-check"></i>

                </div>


                <div class="process-content">

                    <span>
                        LANGKAH KEDUA
                    </span>

                    <h3>
                        Pengajuan Pemasangan
                    </h3>

                    <p>
                        Konfirmasi data customer dan
                        informasi pemasangan.
                    </p>

                </div>

            </div>



            <!-- STEP 3 -->

            <div class="process-card">

                <div class="process-number">
                    03
                </div>


                <div class="process-icon">

                    <i class="bi bi-credit-card-2-front"></i>

                </div>


                <div class="process-content">

                    <span>
                        LANGKAH KETIGA
                    </span>

                    <h3>
                        Pembayaran
                    </h3>

                    <p>
                        Lakukan pembayaran sesuai paket
                        yang telah dipilih.
                    </p>

                </div>

            </div>



            <!-- STEP 4 -->

            <div class="process-card">

                <div class="process-number">
                    04
                </div>


                <div class="process-icon">

                    <i class="bi bi-grid-1x2-fill"></i>

                </div>


                <div class="process-content">

                    <span>
                        LANGKAH TERAKHIR
                    </span>

                    <h3>
                        Masuk Dashboard
                    </h3>

                    <p>
                        Setelah pembayaran berhasil,
                        layanan aktif dan dashboard siap digunakan.
                    </p>

                </div>

            </div>

        </section>



        <!-- =================================================
             CUSTOMER INFO
        ================================================== -->

        <section class="customer-section">


            <div class="customer-heading">


                <div>

                    <div class="section-label">

                        <i class="bi bi-person-check"></i>

                        AKUN ANDA

                    </div>

                    <h2>
                        Siap mulai, <?= e($nama) ?>?
                    </h2>

                </div>


                <div class="account-status">

                    <span></span>

                    Akun Terdaftar

                </div>

            </div>



            <div class="customer-grid">


                <!-- PROFILE -->

                <div class="profile-card">


                    <div class="profile-avatar-large">

                        <?= e($initial) ?>

                    </div>


                    <div class="profile-details">

                        <span>
                            CUSTOMER
                        </span>

                        <h3>
                            <?= e($nama) ?>
                        </h3>

                        <p>

                            ID Customer:

                            <strong>
                                CUS<?= str_pad(
                                    $customerId,
                                    8,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>
                            </strong>

                        </p>

                    </div>


                    <a
                        href="profile.php"
                        class="profile-link"
                    >

                        <i class="bi bi-pencil-square"></i>

                        Edit Profil

                    </a>

                </div>



                <!-- DATA -->

                <div class="data-card">


                    <div class="data-item">

                        <div class="data-icon">

                            <i class="bi bi-envelope"></i>

                        </div>

                        <div>

                            <span>
                                Email
                            </span>

                            <strong>
                                <?= e(
                                    $email !== ''
                                        ? $email
                                        : '-'
                                ) ?>
                            </strong>

                        </div>

                    </div>


                    <div class="data-item">

                        <div class="data-icon">

                            <i class="bi bi-telephone"></i>

                        </div>

                        <div>

                            <span>
                                Nomor Telepon
                            </span>

                            <strong>
                                <?= e(
                                    $telephone !== ''
                                        ? $telephone
                                        : '-'
                                ) ?>
                            </strong>

                        </div>

                    </div>


                    <div class="data-notice">

                        <div>

                            <i class="bi bi-shield-check"></i>

                        </div>

                        <p>

                            Data akun kamu akan digunakan
                            untuk proses pengajuan pemasangan
                            dan pembayaran.

                        </p>

                    </div>

                </div>

            </div>

        </section>



        <!-- =================================================
             FINAL CTA
        ================================================== -->

        <section class="final-cta">


            <div class="cta-decoration decoration-one"></div>

            <div class="cta-decoration decoration-two"></div>


            <div class="cta-content">


                <div class="cta-icon">

                    <i class="bi bi-wifi"></i>

                </div>


                <div>

                    <span>
                        SIAP TERHUBUNG?
                    </span>

                    <h2>
                        Pilih paket WiFi kamu sekarang.
                    </h2>

                    <p>
                        Lanjutkan ke halaman pilihan paket
                        untuk melihat semua paket yang tersedia.
                    </p>

                </div>


                <a
                    href="packages.php"
                    class="cta-button"
                >

                    Pilih Paket

                    <i class="bi bi-arrow-right"></i>

                </a>

            </div>

        </section>



        <!-- =================================================
             FOOTER
        ================================================== -->

        <footer class="footer">

            <div class="footer-brand">

                <div class="footer-logo">

                    <i class="bi bi-wifi"></i>

                </div>


                <div>

                    <strong>
                        WiFi Management
                    </strong>

                    <span>
                        Customer Portal
                    </span>

                </div>

            </div>


            <div class="footer-copy">

                © <?= date('Y') ?>

                WiFi Management.

                All rights reserved.

            </div>


            <a
                href="../logout.php"
                class="footer-logout"
            >

                <i class="bi bi-box-arrow-right"></i>

                Keluar

            </a>

        </footer>


    </main>

</div>


</body>

</html>
