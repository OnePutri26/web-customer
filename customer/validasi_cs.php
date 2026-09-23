<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

requireRole('customer');


/*
|--------------------------------------------------------------------------
| LOGIN
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
| CUSTOMER
|--------------------------------------------------------------------------
*/

$stmtCustomer = $conn->prepare("
    SELECT
        id,
        nama,
        telephone,
        email,
        paket_id,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmtCustomer->bind_param(
    "i",
    $userId
);

$stmtCustomer->execute();

$resultCustomer =
    $stmtCustomer->get_result();

$customer =
    $resultCustomer->fetch_assoc();

$stmtCustomer->close();


if (!$customer) {

    die("Data customer tidak ditemukan.");
}


$customerId =
    (int) $customer['id'];


/*
|--------------------------------------------------------------------------
| REQUEST PEMASANGAN
|--------------------------------------------------------------------------
*/

$stmtRequest = $conn->prepare("
    SELECT
        ir.id,
        ir.customer_id,
        ir.alamat_pemasangan,
        ir.latitude,
        ir.longitude,
        ir.odp_id,
        ir.jarak_odp,
        ir.coverage_status,
        ir.status,
        ir.paket_id,
        ir.created_at,
        ir.updated_at,

        o.nama_odp

    FROM installation_requests ir

    LEFT JOIN odp o
        ON o.id = ir.odp_id

    WHERE
        ir.customer_id = ?

    ORDER BY
        ir.id DESC

    LIMIT 1
");

$stmtRequest->bind_param(
    "i",
    $customerId
);

$stmtRequest->execute();

$resultRequest =
    $stmtRequest->get_result();

$request =
    $resultRequest->fetch_assoc();

$stmtRequest->close();


if (!$request) {

    header("Location: coverage.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$status = strtolower(
    trim(
        (string) (
            $request['status'] ?? ''
        )
    )
);


$coverageStatus = strtolower(
    trim(
        (string) (
            $request['coverage_status'] ?? ''
        )
    )
);


/*
|--------------------------------------------------------------------------
| JIKA SUDAH DISETUJUI CS
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $status,
        [
            'disetujui',
            'approved',
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
| PAKET
|--------------------------------------------------------------------------
*/

$package = null;

$paketId =
    (int) (
        $request['paket_id']
        ?? $customer['paket_id']
        ?? 0
    );


if ($paketId > 0) {

    $stmtPackage = $conn->prepare("
        SELECT
            id,
            nama_paket,
            speed_mbps,
            harga,
            deskripsi
        FROM paket_wifi
        WHERE id = ?
        LIMIT 1
    ");

    $stmtPackage->bind_param(
        "i",
        $paketId
    );

    $stmtPackage->execute();

    $resultPackage =
        $stmtPackage->get_result();

    $package =
        $resultPackage->fetch_assoc();

    $stmtPackage->close();
}


/*
|--------------------------------------------------------------------------
| DATA TAMPILAN
|--------------------------------------------------------------------------
*/

$nama =
    trim(
        (string) (
            $customer['nama'] ?? 'Customer'
        )
    );


$telephone =
    trim(
        (string) (
            $customer['telephone'] ?? '-'
        )
    );


$email =
    trim(
        (string) (
            $customer['email'] ?? '-'
        )
    );


$alamat =
    trim(
        (string) (
            $request['alamat_pemasangan'] ?? '-'
        )
    );


$namaOdp =
    trim(
        (string) (
            $request['nama_odp'] ?? '-'
        )
    );


$jarakOdp =
    (float) (
        $request['jarak_odp'] ?? 0
    );


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

    <title>
        Validasi Customer Service | WiFi Management
    </title>


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <link
        rel="stylesheet"
        href="../assets/css/validasi_cs.css?v=1.0"
    >

</head>


<body>


<div class="page-wrapper">


    <!-- HEADER -->

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
                >

                    <i class="bi bi-box-arrow-right"></i>

                    Keluar

                </a>

            </div>

        </div>

    </header>


    <!-- MAIN -->

    <main class="main-container">


        <!-- PROGRESS -->

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


            <div class="step completed">

                <div class="step-number">

                    <i class="bi bi-check-lg"></i>

                </div>

                <div class="step-content">

                    <strong>
                        Pilih Paket
                    </strong>

                    <span>
                        Selesai
                    </span>

                </div>

            </div>


            <div class="step-line active"></div>


            <div class="step current">

                <div class="step-number">
                    3
                </div>

                <div class="step-content">

                    <strong>
                        Validasi CS
                    </strong>

                    <span>
                        Sedang diproses
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
                        Setelah disetujui
                    </span>

                </div>

            </div>

        </section>


        <!-- CONTENT -->

        <section class="validation-card">


            <div class="validation-icon">

                <i class="bi bi-headset"></i>

            </div>


            <div class="status-badge">

                <span></span>

                MENUNGGU VALIDASI CS

            </div>


            <h1>
                Pengajuan Sedang Diverifikasi
            </h1>


            <p class="intro">

                Terima kasih. Paket WiFi kamu sudah dipilih
                dan pengajuan pemasangan telah diterima.

                Customer Service akan melakukan pengecekan
                data sebelum pengajuan dilanjutkan ke tahap
                pembayaran.

            </p>


            <!-- CUSTOMER -->

            <div class="section-box">

                <div class="section-heading">

                    <i class="bi bi-person-circle"></i>

                    <strong>
                        Data Customer
                    </strong>

                </div>


                <div class="info-grid">

                    <div class="info-item">

                        <span>
                            Nama
                        </span>

                        <strong>
                            <?= e($nama) ?>
                        </strong>

                    </div>


                    <div class="info-item">

                        <span>
                            Nomor Telepon
                        </span>

                        <strong>
                            <?= e($telephone) ?>
                        </strong>

                    </div>


                    <div class="info-item">

                        <span>
                            Email
                        </span>

                        <strong>
                            <?= e($email) ?>
                        </strong>

                    </div>


                    <div class="info-item">

                        <span>
                            Status Coverage
                        </span>

                        <strong class="success-text">

                            <i class="bi bi-check-circle-fill"></i>

                            <?= e(
                                ucfirst(
                                    $coverageStatus
                                )
                            ) ?>

                        </strong>

                    </div>

                </div>

            </div>


            <!-- PACKAGE -->

            <?php if ($package): ?>

                <div class="section-box">

                    <div class="section-heading">

                        <i class="bi bi-router-fill"></i>

                        <strong>
                            Paket yang Dipilih
                        </strong>

                    </div>


                    <div class="package-summary">


                        <div>

                            <span>
                                Paket
                            </span>

                            <strong>
                                <?= e(
                                    $package['nama_paket']
                                ) ?>
                            </strong>

                        </div>


                        <div>

                            <span>
                                Kecepatan
                            </span>

                            <strong>

                                <?= e(
                                    $package['speed_mbps']
                                ) ?>

                                Mbps

                            </strong>

                        </div>


                        <div>

                            <span>
                                Biaya
                            </span>

                            <strong>

                                <?= e(
                                    rupiah(
                                        $package['harga']
                                    )
                                ) ?>

                                /bulan

                            </strong>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- INSTALLATION -->

            <div class="section-box">

                <div class="section-heading">

                    <i class="bi bi-geo-alt-fill"></i>

                    <strong>
                        Data Pemasangan
                    </strong>

                </div>


                <div class="address-box">

                    <?= e($alamat) ?>

                </div>


                <div class="location-info">

                    <div>

                        <span>
                            ODP
                        </span>

                        <strong>
                            <?= e($namaOdp) ?>
                        </strong>

                    </div>


                    <div>

                        <span>
                            Jarak
                        </span>

                        <strong>

                            <?= number_format(
                                $jarakOdp,
                                3,
                                ',',
                                '.'
                            ) ?>

                            km

                        </strong>

                    </div>

                </div>

            </div>


            <!-- PROCESS -->

            <div class="process-box">

                <div class="process-icon">

                    <i class="bi bi-clock-history"></i>

                </div>


                <div>

                    <strong>
                        Menunggu Customer Service
                    </strong>

                    <p>

                        CS akan memeriksa data customer,
                        lokasi pemasangan, ketersediaan jaringan,
                        dan paket yang dipilih.

                    </p>

                </div>

            </div>


            <!-- ACTION -->

            <div class="actions">

                <a
                    href="langganan.php"
                    class="back-button"
                >

                    <i class="bi bi-arrow-left"></i>

                    Kembali

                </a>

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

                © <?= date('Y') ?>
                WiFi Management

            </span>

        </footer>


    </main>

</div>


</body>

</html>
