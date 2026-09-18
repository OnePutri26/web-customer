<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');


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


function rupiah($nominal): string
{
    return 'Rp ' . number_format(
        (float) $nominal,
        0,
        ',',
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$stmtCustomer = $conn->prepare("
    SELECT
        id,
        user_id,
        nama,
        telephone,
        email,
        paket_id,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmtCustomer) {
    die("Query customer gagal: " . e($conn->error));
}

$stmtCustomer->bind_param("i", $userId);

if (!$stmtCustomer->execute()) {
    die("Gagal mengambil data customer: " . e($stmtCustomer->error));
}

$resultCustomer = $stmtCustomer->get_result();

$customer = $resultCustomer->fetch_assoc();

$stmtCustomer->close();


/*
|--------------------------------------------------------------------------
| CUSTOMER TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$customer) {

    die("
        <!DOCTYPE html>
        <html lang='id'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Customer Tidak Ditemukan</title>

            <style>
                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    font-family: Arial, sans-serif;
                    background: #f4f7fb;
                }

                .error-box {
                    width: 100%;
                    max-width: 500px;
                    background: white;
                    border-radius: 24px;
                    padding: 40px;
                    text-align: center;
                    box-shadow: 0 25px 70px rgba(15, 23, 42, .12);
                }

                .error-icon {
                    width: 70px;
                    height: 70px;
                    margin: 0 auto 20px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #fee2e2;
                    color: #dc2626;
                    font-size: 30px;
                    font-weight: 800;
                }

                h2 {
                    margin: 0 0 12px;
                    color: #111827;
                }

                p {
                    color: #64748b;
                    line-height: 1.7;
                }

                a {
                    display: inline-flex;
                    margin-top: 20px;
                    padding: 13px 22px;
                    border-radius: 12px;
                    background: #2563eb;
                    color: white;
                    text-decoration: none;
                    font-weight: 700;
                }
            </style>
        </head>

        <body>

            <div class='error-box'>

                <div class='error-icon'>
                    !
                </div>

                <h2>
                    Data Customer Tidak Ditemukan
                </h2>

                <p>
                    Akun berhasil login, tetapi data customer
                    belum ditemukan di database.
                </p>

                <a href='../logout.php'>
                    Kembali ke Login
                </a>

            </div>

        </body>
        </html>
    ");

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
        ['aktif', 'active', '1'],
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
            'pending_pembayaran',
            'pending_payment'
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
    substr($nama, 0, 1)
);


/*
|--------------------------------------------------------------------------
| AMBIL PAKET WIFI
|--------------------------------------------------------------------------
*/

$packages = [];

$packageError = '';

$sqlPackages = "
    SELECT
        id,
        nama_paket,
        speed_mbps,
        harga,
        deskripsi,
        status
    FROM paket_wifi
    WHERE
        status IS NULL
        OR TRIM(status) = ''
        OR LOWER(TRIM(status)) IN (
            'aktif',
            'active',
            '1',
            'tersedia',
            'available'
        )
    ORDER BY
        CAST(harga AS DECIMAL(15,2)) ASC,
        CAST(speed_mbps AS DECIMAL(15,2)) ASC
";

$stmtPackages = $conn->prepare($sqlPackages);

if (!$stmtPackages) {

    $packageError =
        "Query paket_wifi gagal: " .
        $conn->error;

} else {

    if (!$stmtPackages->execute()) {

        $packageError =
            "Gagal mengambil paket WiFi: " .
            $stmtPackages->error;

    } else {

        $resultPackages =
            $stmtPackages->get_result();

        while (
            $row =
            $resultPackages->fetch_assoc()
        ) {
            $packages[] = $row;
        }

        $resultPackages->free();
    }

    $stmtPackages->close();
}


$totalPackages = count($packages);

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
        content="Pilih paket WiFi terbaik untuk kebutuhan internet Anda"
    >

    <title>
        Pilih Paket WiFi | WiFi Management
    </title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- CSS -->

    <link
        rel="stylesheet"
        href="assets/css/packages.css?v=1.0"
    >

</head>


<body>


<div class="page-wrapper">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="top-header">

        <div class="header-container">


            <a
                href="dashboard.php"
                class="brand"
            >

                <div class="brand-icon">

                    <i class="bi bi-wifi"></i>

                </div>


                <div class="brand-text">

                    <strong>
                        WiFi Management
                    </strong>

                    <span>
                        Customer Portal
                    </span>

                </div>

            </a>


            <div class="header-right">


                <div class="user-box">

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


                <a
                    href="../logout.php"
                    class="logout-btn"
                    title="Keluar"
                >

                    <i class="bi bi-box-arrow-right"></i>

                    <span>
                        Keluar
                    </span>

                </a>

            </div>

        </div>

    </header>



    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main-container">


        <!-- HERO -->

        <section class="hero-section">


            <div class="hero-content">


                <div class="hero-badge">

                    <i class="bi bi-stars"></i>

                    PILIHAN INTERNET TERBAIK

                </div>


                <h1>

                    Pilih Paket WiFi
                    <span>Yang Sesuai Untukmu</span>

                </h1>


                <p>

                    Nikmati koneksi internet cepat, stabil,
                    dan nyaman untuk kebutuhan rumah,
                    belajar, bekerja, hingga hiburan.

                </p>


                <div class="hero-actions">

                    <a
                        href="langganan.php"
                        class="back-btn"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Kembali

                    </a>


                    <div class="package-count">

                        <i class="bi bi-router"></i>

                        <strong>
                            <?= $totalPackages ?>
                        </strong>

                        <span>
                            Paket tersedia
                        </span>

                    </div>

                </div>

            </div>


            <div class="hero-visual">


                <div class="glow-circle"></div>


                <div class="router-card">

                    <div class="router-top">

                        <div class="router-icon">

                            <i class="bi bi-router-fill"></i>

                        </div>

                        <span class="online-dot">
                            Online
                        </span>

                    </div>


                    <div class="router-wifi">

                        <i class="bi bi-wifi"></i>

                    </div>


                    <strong>
                        Internet Stabil
                    </strong>


                    <span>
                        Siap digunakan untuk seluruh kebutuhanmu
                    </span>


                    <div class="signal-bars">

                        <span></span>
                        <span></span>
                        <span></span>
                        <span></span>

                    </div>

                </div>

            </div>

        </section>



        <!-- =========================================================
             PROGRESS
        ========================================================== -->

        <section class="progress-wrapper">


            <div class="step completed">

                <div class="step-number">

                    <i class="bi bi-check-lg"></i>

                </div>

                <div class="step-content">

                    <strong>
                        Akun
                    </strong>

                    <span>
                        Selesai
                    </span>

                </div>

            </div>


            <div class="step-line active"></div>


            <div class="step current">

                <div class="step-number">
                    2
                </div>

                <div class="step-content">

                    <strong>
                        Pilih Paket
                    </strong>

                    <span>
                        Sekarang
                    </span>

                </div>

            </div>


            <div class="step-line"></div>


            <div class="step">

                <div class="step-number">
                    3
                </div>

                <div class="step-content">

                    <strong>
                        Pengajuan
                    </strong>

                    <span>
                        Berikutnya
                    </span>

                </div>

            </div>


            <div class="step-line"></div>


            <div class="step">

                <div class="step-number">
                    4
                </div>

                <div class="step-content">

                    <strong>
                        Pembayaran
                    </strong>

                    <span>
                        Terakhir
                    </span>

                </div>

            </div>

        </section>



        <!-- =========================================================
             ERROR
        ========================================================== -->

        <?php if ($packageError !== ''): ?>

            <div class="alert-error">

                <div class="alert-icon">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                </div>


                <div>

                    <strong>
                        Paket gagal dimuat
                    </strong>

                    <span>
                        <?= e($packageError) ?>
                    </span>

                </div>

            </div>

        <?php endif; ?>



        <!-- =========================================================
             PACKAGE TITLE
        ========================================================== -->

        <section class="section-title">


            <div>

                <span>
                    PAKET INTERNET
                </span>


                <h2>
                    Temukan Paket yang Tepat
                </h2>


                <p>
                    Pilih paket berdasarkan kecepatan
                    dan kebutuhan internet kamu.
                </p>

            </div>


            <div class="secure-badge">

                <i class="bi bi-shield-check"></i>

                <div>

                    <strong>
                        Aman & Terpercaya
                    </strong>

                    <span>
                        Pembayaran aman
                    </span>

                </div>

            </div>

        </section>



        <!-- =========================================================
             PACKAGES
        ========================================================== -->

        <?php if ($totalPackages > 0): ?>


            <section class="package-grid">


                <?php foreach ($packages as $index => $package): ?>


                    <?php

                    $packageId =
                        (int) (
                            $package['id']
                            ?? 0
                        );


                    $packageName =
                        trim(
                            (string) (
                                $package['nama_paket']
                                ?? 'Paket WiFi'
                            )
                        );


                    $speed =
                        (int) (
                            $package['speed_mbps']
                            ?? 0
                        );


                    $harga =
                        (float) (
                            $package['harga']
                            ?? 0
                        );


                    $deskripsi =
                        trim(
                            (string) (
                                $package['deskripsi']
                                ?? ''
                            )
                        );


                    if ($deskripsi === '') {

                        $deskripsi =
                            'Internet cepat dan stabil untuk kebutuhan rumah.';
                    }


                    $isPopular =
                        ($speed >= 100);


                    if ($speed >= 200) {

                        $icon =
                            'bi-lightning-charge-fill';

                    } elseif ($speed >= 100) {

                        $icon =
                            'bi-lightning-fill';

                    } else {

                        $icon =
                            'bi-wifi';
                    }

                    ?>


                    <article
                        class="package-card <?= $isPopular ? 'popular' : '' ?>"
                    >


                        <?php if ($isPopular): ?>

                            <div class="popular-badge">

                                <i class="bi bi-star-fill"></i>

                                PALING POPULER

                            </div>

                        <?php endif; ?>


                        <div class="card-header">


                            <div class="package-icon">

                                <i class="bi <?= e($icon) ?>"></i>

                            </div>


                            <div class="availability">

                                <span></span>

                                Tersedia

                            </div>

                        </div>



                        <div class="package-name">

                            <span>
                                INTERNET HOME
                            </span>

                            <h3>
                                <?= e($packageName) ?>
                            </h3>

                        </div>



                        <div class="speed-box">


                            <div class="speed-number">

                                <strong>
                                    <?= number_format(
                                        $speed,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </strong>

                                <span>
                                    Mbps
                                </span>

                            </div>


                            <div class="speed-label">

                                <i class="bi bi-speedometer2"></i>

                                Kecepatan internet

                            </div>

                        </div>



                        <p class="description">

                            <?= e($deskripsi) ?>

                        </p>



                        <div class="price-box">

                            <span>
                                Mulai dari
                            </span>

                            <div>

                                <strong>
                                    <?= e(
                                        rupiah($harga)
                                    ) ?>
                                </strong>

                                <small>
                                    /bulan
                                </small>

                            </div>

                        </div>



                        <div class="features">


                            <div>

                                <i class="bi bi-check-circle-fill"></i>

                                Internet cepat & stabil

                            </div>


                            <div>

                                <i class="bi bi-check-circle-fill"></i>

                                Customer Portal

                            </div>


                            <div>

                                <i class="bi bi-check-circle-fill"></i>

                                Customer Support

                            </div>


                            <div>

                                <i class="bi bi-check-circle-fill"></i>

                                Pembayaran mudah

                            </div>

                        </div>



                        <!-- =================================================
                             FORM
                             packages.php
                                  ↓
                             pengajuan_pemasangan.php
                        ================================================== -->

                        <form
                            action="pengajuan_pemasangan.php"
                            method="POST"
                            class="package-form"
                            onsubmit="return choosePackage(this, <?= htmlspecialchars(
                                json_encode(
                                    $packageName,
                                    JSON_UNESCAPED_UNICODE
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>);"
                        >

                            <input
                                type="hidden"
                                name="paket_id"
                                value="<?= $packageId ?>"
                            >


                            <input
                                type="hidden"
                                name="action"
                                value="select_package"
                            >


                            <button
                                type="submit"
                                class="choose-package"
                            >

                                <span>
                                    Pilih Paket Ini
                                </span>


                                <i class="bi bi-arrow-right"></i>

                            </button>

                        </form>


                    </article>


                <?php endforeach; ?>


            </section>


        <?php else: ?>


            <!-- EMPTY -->

            <section class="empty-state">


                <div class="empty-icon">

                    <i class="bi bi-wifi-off"></i>

                </div>


                <h3>
                    Belum Ada Paket Tersedia
                </h3>


                <p>
                    Belum ada paket WiFi yang dapat
                    ditampilkan saat ini.
                </p>


                <a href="langganan.php">

                    <i class="bi bi-arrow-left"></i>

                    Kembali

                </a>

            </section>

        <?php endif; ?>



        <!-- =========================================================
             INFO BOTTOM
        ========================================================== -->

        <section class="bottom-info">


            <div class="info-item">

                <div class="info-item-icon">

                    <i class="bi bi-lightning-charge"></i>

                </div>


                <div>

                    <strong>
                        Proses Cepat
                    </strong>

                    <span>
                        Pilih paket dalam beberapa klik
                    </span>

                </div>

            </div>



            <div class="info-item">

                <div class="info-item-icon">

                    <i class="bi bi-shield-check"></i>

                </div>


                <div>

                    <strong>
                        Data Aman
                    </strong>

                    <span>
                        Data customer terlindungi
                    </span>

                </div>

            </div>



            <div class="info-item">

                <div class="info-item-icon">

                    <i class="bi bi-headset"></i>

                </div>


                <div>

                    <strong>
                        Customer Support
                    </strong>

                    <span>
                        Bantuan tersedia saat dibutuhkan
                    </span>

                </div>

            </div>

        </section>



        <!-- FOOTER -->

        <footer class="footer">

            <div class="footer-brand">

                <i class="bi bi-wifi"></i>

                <strong>
                    WiFi Management
                </strong>

            </div>


            <span>
                © <?= date('Y') ?> WiFi Management
            </span>

        </footer>


    </main>

</div>



<script>

function choosePackage(form, packageName)
{
    const confirmed = confirm(
        "Pilih paket " +
        packageName +
        "?\n\n" +
        "Setelah ini kamu akan masuk ke " +
        "Pengajuan Pemasangan."
    );

    if (!confirmed) {
        return false;
    }


    const button =
        form.querySelector(
            'button[type="submit"]'
        );


    if (button) {

        button.disabled = true;

        button.classList.add('loading');

        button.innerHTML =
            '<i class="bi bi-arrow-repeat spin"></i>' +
            '<span>Membuka Pengajuan...</span>';
    }


    return true;
}

</script>


</body>
</html>
