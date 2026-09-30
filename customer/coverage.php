<?php

declare(strict_types=1);

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

date_default_timezone_set("Asia/Jakarta");

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

$conn->set_charset("utf8mb4");

/*
|--------------------------------------------------------------------------
| LOGIN CUSTOMER
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION["user_id"] ?? 0);

$role = strtolower(
    trim((string) ($_SESSION["role"] ?? ""))
);

if ($userId <= 0 || $role !== "customer") {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| VARIABLE
|--------------------------------------------------------------------------
*/

$error = "";
$success = "";

$nama = "";
$telephone = "";
$email = "";
$alamat = "";

$latitude = "";
$longitude = "";

$customerId = 0;

$odpId = null;
$namaOdp = "";
$jarakOdp = null;
$radiusOdp = null;

$coverageStatus = "";

$requestId = null;
$requestStatus = "";

$namaPaket = "";
$hargaPaket = null;


/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.user_id,
            c.paket_id,
            c.nama,
            c.telephone,
            c.email,
            c.alamat,
            c.status_langganan,
            p.nama_paket,
            p.harga
        FROM customers c
        LEFT JOIN paket p
            ON p.id = c.paket_id
        WHERE c.user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $customer = $result->fetch_assoc();

    $stmt->close();

    if (!$customer) {

        $error = "Data customer tidak ditemukan.";

    } else {

        $customerId = (int) $customer["id"];

        $nama = (string) ($customer["nama"] ?? "");

        $telephone = (string) (
            $customer["telephone"] ?? ""
        );

        $email = (string) (
            $customer["email"] ?? ""
        );

        $alamat = (string) (
            $customer["alamat"] ?? ""
        );

        $namaPaket = (string) (
            $customer["nama_paket"] ?? ""
        );

        $hargaPaket = $customer["harga"] !== null
            ? (float) $customer["harga"]
            : null;
    }

} catch (Throwable $exception) {

    $error =
        "Terjadi kesalahan saat mengambil data customer.";
}


/*
|--------------------------------------------------------------------------
| AMBIL REQUEST TERAKHIR
|--------------------------------------------------------------------------
*/

if ($customerId > 0) {

    try {

        $stmt = $conn->prepare("
            SELECT
                id,
                alamat_pemasangan,
                latitude,
                longitude,
                odp_id,
                jarak_odp,
                coverage_status,
                status
            FROM installation_requests
            WHERE customer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $customerId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $request = $result->fetch_assoc();

        $stmt->close();

        if ($request) {

            $requestId = (int) $request["id"];

            if (!empty($request["alamat_pemasangan"])) {
                $alamat = (string) $request["alamat_pemasangan"];
            }

            if (
                $request["latitude"] !== null &&
                $request["latitude"] !== ""
            ) {
                $latitude = (string) $request["latitude"];
            }

            if (
                $request["longitude"] !== null &&
                $request["longitude"] !== ""
            ) {
                $longitude = (string) $request["longitude"];
            }

            if (!empty($request["odp_id"])) {
                $odpId = (int) $request["odp_id"];
            }

            if ($request["jarak_odp"] !== null) {
                $jarakOdp = (float) $request["jarak_odp"];
            }

            $coverageStatus = (string) (
                $request["coverage_status"] ?? ""
            );

            $requestStatus = (string) (
                $request["status"] ?? ""
            );
        }

    } catch (Throwable $exception) {
        // Tidak menghentikan halaman.
    }
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA ODP
|--------------------------------------------------------------------------
*/

if ($odpId !== null) {

    try {

        $stmt = $conn->prepare("
            SELECT
                id,
                nama_odp,
                latitude,
                longitude,
                radius
            FROM odp
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $odpId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $odp = $result->fetch_assoc();

        $stmt->close();

        if ($odp) {

            $namaOdp = (string) (
                $odp["nama_odp"] ?? ""
            );

            if ($odp["radius"] !== null) {
                $radiusOdp = (float) $odp["radius"];
            }
        }

    } catch (Throwable $exception) {
        // Abaikan jika ODP belum tersedia.
    }
}


/*
|--------------------------------------------------------------------------
| POST CEK COVERAGE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = trim(
        (string) ($_POST["action"] ?? "")
    );

    if ($action === "check_coverage") {

        /*
        |--------------------------------------------------------------------------
        | RESET
        |--------------------------------------------------------------------------
        */

        $error = "";
        $success = "";

        $coverageStatus = "";

        $requestId = null;
        $requestStatus = "";

        $odpId = null;
        $namaOdp = "";

        $jarakOdp = null;
        $radiusOdp = null;


        /*
        |--------------------------------------------------------------------------
        | INPUT
        |--------------------------------------------------------------------------
        */

        $alamatPost = trim(
            (string) ($_POST["alamat"] ?? "")
        );

        $latPost = trim(
            (string) ($_POST["latitude"] ?? "")
        );

        $lngPost = trim(
            (string) ($_POST["longitude"] ?? "")
        );

        $alamat = $alamatPost;
        $latitude = $latPost;
        $longitude = $lngPost;


        /*
        |--------------------------------------------------------------------------
        | VALIDASI ALAMAT
        |--------------------------------------------------------------------------
        */

        if ($alamatPost === "") {

            $error =
                "Alamat pemasangan wajib diisi.";

        } elseif (mb_strlen($alamatPost) < 10) {

            $error =
                "Alamat pemasangan terlalu pendek. "
                . "Masukkan alamat yang lebih lengkap.";
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDASI LATITUDE
        |--------------------------------------------------------------------------
        */

        elseif (
            $latPost === "" ||
            !is_numeric($latPost)
        ) {

            $error =
                "Lokasi belum dipilih. "
                . "Silakan gunakan GPS atau klik lokasi pada peta.";

        } elseif (
            (float) $latPost < -90 ||
            (float) $latPost > 90
        ) {

            $error =
                "Latitude tidak valid.";
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDASI LONGITUDE
        |--------------------------------------------------------------------------
        */

        elseif (
            $lngPost === "" ||
            !is_numeric($lngPost)
        ) {

            $error =
                "Longitude tidak valid.";

        } elseif (
            (float) $lngPost < -180 ||
            (float) $lngPost > 180
        ) {

            $error =
                "Longitude tidak valid.";
        }


        /*
        |--------------------------------------------------------------------------
        | PROSES COVERAGE
        |--------------------------------------------------------------------------
        */

        if ($error === "") {

            $lat = (float) $latPost;
            $lng = (float) $lngPost;

            try {

                /*
                |--------------------------------------------------------------------------
                | CARI ODP TERDEKAT
                |--------------------------------------------------------------------------
                */

                $sql = "
                    SELECT
                        id,
                        nama_odp,
                        latitude,
                        longitude,
                        radius,

                        (
                            6371 * ACOS(
                                LEAST(
                                    1,
                                    GREATEST(
                                        -1,

                                        COS(RADIANS(?))

                                        *

                                        COS(RADIANS(latitude))

                                        *

                                        COS(
                                            RADIANS(longitude)
                                            -
                                            RADIANS(?)
                                        )

                                        +

                                        SIN(RADIANS(?))

                                        *

                                        SIN(RADIANS(latitude))
                                    )
                                )
                            )
                        ) AS jarak

                    FROM odp

                    WHERE
                        latitude IS NOT NULL
                        AND longitude IS NOT NULL
                        AND status = 'aktif'

                    ORDER BY jarak ASC

                    LIMIT 1
                ";

                $stmt = $conn->prepare($sql);

                $stmt->bind_param(
                    "ddd",
                    $lat,
                    $lng,
                    $lat
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $nearestOdp = $result->fetch_assoc();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | TIDAK ADA ODP
                |--------------------------------------------------------------------------
                */

                if (!$nearestOdp) {

                    $coverageStatus = "tidak_tersedia";

                    $error =
                        "Coverage belum tersedia. "
                        . "Belum ada ODP aktif yang dapat "
                        . "melayani lokasi Anda.";

                } else {

                    $odpId = (int) $nearestOdp["id"];

                    $namaOdp = (string) (
                        $nearestOdp["nama_odp"] ?? ""
                    );

                    $jarakOdp = round(
                        (float) $nearestOdp["jarak"],
                        3
                    );

                    $radiusOdp = (float) (
                        $nearestOdp["radius"] ?? 0
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | TERSEDIA
                    |--------------------------------------------------------------------------
                    */

                    if ($jarakOdp <= $radiusOdp) {

                        $coverageStatus = "tersedia";

                        $success =
                            "Coverage tersedia. "
                            . "Lokasi Anda berjarak sekitar "
                            . number_format(
                                $jarakOdp,
                                3,
                                ",",
                                "."
                            )
                            . " km dari "
                            . $namaOdp
                            . ".";


                        /*
                        |--------------------------------------------------------------------------
                        | SIMPAN DATABASE
                        |--------------------------------------------------------------------------
                        */

                        $conn->begin_transaction();

                        try {

                            /*
                            | UPDATE CUSTOMER
                            */

                            $stmtCustomer = $conn->prepare("
                                UPDATE customers
                                SET alamat = ?
                                WHERE id = ?
                                AND user_id = ?
                            ");

                            $stmtCustomer->bind_param(
                                "sii",
                                $alamatPost,
                                $customerId,
                                $userId
                            );

                            $stmtCustomer->execute();

                            $stmtCustomer->close();


                            /*
                            | CARI REQUEST TERAKHIR
                            */

                            $stmtRequest = $conn->prepare("
                                SELECT id
                                FROM installation_requests
                                WHERE customer_id = ?
                                ORDER BY id DESC
                                LIMIT 1
                            ");

                            $stmtRequest->bind_param(
                                "i",
                                $customerId
                            );

                            $stmtRequest->execute();

                            $requestResult =
                                $stmtRequest->get_result();

                            $existingRequest =
                                $requestResult->fetch_assoc();

                            $stmtRequest->close();


                            /*
                            | UPDATE REQUEST
                            */

                            if ($existingRequest) {

                                $requestId =
                                    (int) $existingRequest["id"];

                                $stmtUpdate = $conn->prepare("
                                    UPDATE installation_requests
                                    SET
                                        alamat_pemasangan = ?,
                                        latitude = ?,
                                        longitude = ?,
                                        odp_id = ?,
                                        jarak_odp = ?,
                                        coverage_status = ?,
                                        status = 'menunggu'
                                    WHERE
                                        id = ?
                                        AND customer_id = ?
                                ");

                                $stmtUpdate->bind_param(
                                    "sddidsii",
                                    $alamatPost,
                                    $lat,
                                    $lng,
                                    $odpId,
                                    $jarakOdp,
                                    $coverageStatus,
                                    $requestId,
                                    $customerId
                                );

                                $stmtUpdate->execute();

                                $stmtUpdate->close();

                            } else {

                                /*
                                | INSERT REQUEST
                                */

                                $stmtInsert = $conn->prepare("
                                    INSERT INTO installation_requests
                                    (
                                        customer_id,
                                        alamat_pemasangan,
                                        latitude,
                                        longitude,
                                        odp_id,
                                        jarak_odp,
                                        coverage_status,
                                        status,
                                        created_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        'menunggu',
                                        NOW()
                                    )
                                ");

                                $stmtInsert->bind_param(
                                    "isddids",
                                    $customerId,
                                    $alamatPost,
                                    $lat,
                                    $lng,
                                    $odpId,
                                    $jarakOdp,
                                    $coverageStatus
                                );

                                $stmtInsert->execute();

                                $requestId =
                                    $stmtInsert->insert_id;

                                $stmtInsert->close();
                            }

                            $requestStatus = "menunggu";

                            $conn->commit();

                        } catch (Throwable $dbError) {

                            try {
                                $conn->rollback();
                            } catch (Throwable $rollbackError) {
                            }

                            $error =
                                "Coverage tersedia, tetapi data "
                                . "lokasi gagal disimpan. "
                                . "Silakan coba lagi.";

                            $success = "";

                            $coverageStatus = "";

                            $requestId = null;

                            $requestStatus = "";
                        }


                    /*
                    |--------------------------------------------------------------------------
                    | TIDAK TERSEDIA
                    |--------------------------------------------------------------------------
                    */

                    } else {

                        $coverageStatus = "tidak_tersedia";

                        $error =
                            "Coverage belum tersedia. "
                            . "Lokasi Anda berjarak sekitar "
                            . number_format(
                                $jarakOdp,
                                3,
                                ",",
                                "."
                            )
                            . " km dari "
                            . $namaOdp
                            . ", sedangkan radius coverage hanya "
                            . number_format(
                                $radiusOdp,
                                3,
                                ",",
                                "."
                            )
                            . " km.";
                    }
                }

            } catch (Throwable $exception) {

                $error =
                    "Terjadi kesalahan saat memproses "
                    . "pengecekan coverage.";

                $success = "";

                $coverageStatus = "";

                $requestId = null;

                $requestStatus = "";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| GOOGLE MAPS
|--------------------------------------------------------------------------
*/

$googleMapsUrl = "#";

if (
    $latitude !== "" &&
    $longitude !== "" &&
    is_numeric($latitude) &&
    is_numeric($longitude)
) {

    $googleMapsUrl =
        "https://www.google.com/maps/search/?api=1&query="
        . rawurlencode(
            $latitude . "," . $longitude
        );
}

$hasGoogleMapsLocation =
    $googleMapsUrl !== "#";


/*
|--------------------------------------------------------------------------
| ASSET VERSION
|--------------------------------------------------------------------------
*/

$cssFile = __DIR__ . "/assets/css/coverage.css";
$jsFile = __DIR__ . "/assets/js/coverage.js";

$cssVersion = file_exists($cssFile)
    ? filemtime($cssFile)
    : time();

$jsVersion = file_exists($jsFile)
    ? filemtime($jsFile)
    : time();

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
        Cek Coverage | YESNET
    </title>

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

    <!-- Leaflet -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        crossorigin=""
    >

    <!-- Coverage CSS -->
    <link
        rel="stylesheet"
        href="assets/css/coverage.css?v=<?= file_exists(__DIR__ . '/assets/css/coverage.css') ? filemtime(__DIR__ . '/assets/css/coverage.css') : time() ?>"
    >

</head>


<body>


<!-- =========================================================
     TOP NAVBAR
========================================================= -->

<header class="top-navbar">

    <div class="top-navbar-inner">

        <a href="dashboard.php" class="brand">

            <div class="brand-logo">
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


        <div class="navbar-right">

            <div class="navbar-user">

                <div class="navbar-avatar">

                    <?= e(
                        strtoupper(
                            substr(
                                $nama !== "" ? $nama : "C",
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>


                <div class="navbar-user-info">

                    <strong>
                        <?= e($nama ?: "Customer") ?>
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



<!-- =========================================================
     PAGE
========================================================= -->

<main class="coverage-page">

    <div class="page-container">


        <!-- =====================================================
             BREADCRUMB
        ====================================================== -->

        <div class="breadcrumb-custom">

            <a href="dashboard.php">

                <i class="bi bi-house"></i>

                Dashboard

            </a>

            <i class="bi bi-chevron-right"></i>

            <span>
                Berlangganan
            </span>

            <i class="bi bi-chevron-right"></i>

            <strong>
                Coverage
            </strong>

        </div>



        <!-- =====================================================
             HERO
        ====================================================== -->

        <section class="coverage-hero">

            <div class="hero-content">

                <span class="hero-label">

                    <span></span>

                    LAYANAN INTERNET HOME

                </span>


                <h1>

                    Cek Coverage.

                    <span>
                        Temukan koneksi terbaik.
                    </span>

                </h1>


                <p>

                    Tentukan lokasi pemasangan Anda dan cek
                    ketersediaan jaringan YESNET di sekitar
                    lokasi tersebut.

                </p>


                <div class="hero-features">

                    <div>

                        <i class="bi bi-lightning-charge-fill"></i>

                        <span>
                            Proses cepat
                        </span>

                    </div>


                    <div>

                        <i class="bi bi-shield-check"></i>

                        <span>
                            Data aman
                        </span>

                    </div>


                    <div>

                        <i class="bi bi-headset"></i>

                        <span>
                            Support customer
                        </span>

                    </div>

                </div>

            </div>


            <div class="hero-visual">

                <div class="hero-orb orb-one"></div>

                <div class="hero-orb orb-two"></div>

                <div class="hero-router-card">

                    <div class="router-status">

                        <span></span>

                        SYSTEM ONLINE

                    </div>


                    <div class="router-icon">

                        <i class="bi bi-wifi"></i>

                    </div>


                    <strong>
                        YESNET Connection
                    </strong>


                    <small>
                        Cek jaringan di lokasi Anda
                    </small>


                    <div class="router-wave"></div>

                </div>

            </div>

        </section>



        <!-- =====================================================
             STEP
        ====================================================== -->

        <section class="step-section">

            <div class="section-mini-label">

                <i class="bi bi-diagram-3"></i>

                CARA BERLANGGANAN

            </div>


            <h2>

                Beberapa langkah

                <span>
                    untuk terhubung
                </span>

            </h2>


            <p class="section-description">

                Ikuti proses sederhana berikut untuk mendapatkan
                layanan internet YESNET di rumah Anda.

            </p>


            <div class="steps">

                <div class="step-item active">

                    <div class="step-number">
                        01
                    </div>

                    <div class="step-content">

                        <span>
                            LANGKAH PERTAMA
                        </span>

                        <strong>
                            Pilih Lokasi
                        </strong>

                        <p>
                            Tentukan alamat dan lokasi pemasangan.
                        </p>

                    </div>

                </div>


                <div class="step-line"></div>


                <div class="step-item active">

                    <div class="step-number">
                        02
                    </div>

                    <div class="step-content">

                        <span>
                            LANGKAH KEDUA
                        </span>

                        <strong>
                            Cek Coverage
                        </strong>

                        <p>
                            Sistem mencari ODP terdekat.
                        </p>

                    </div>

                </div>


                <div class="step-line"></div>


                <div class="step-item">

                    <div class="step-number">
                        03
                    </div>

                    <div class="step-content">

                        <span>
                            LANGKAH KETIGA
                        </span>

                        <strong>
                            Konfirmasi
                        </strong>

                        <p>
                            Konfirmasi lokasi pemasangan.
                        </p>

                    </div>

                </div>


                <div class="step-line"></div>


                <div class="step-item">

                    <div class="step-number">
                        04
                    </div>

                    <div class="step-content">

                        <span>
                            LANGKAH KEEMPAT
                        </span>

                        <strong>
                            Pilih Paket
                        </strong>

                        <p>
                            Pilih paket internet Anda.
                        </p>

                    </div>

                </div>


                <div class="step-line"></div>


                <div class="step-item">

                    <div class="step-number">
                        05
                    </div>

                    <div class="step-content">

                        <span>
                            LANGKAH TERAKHIR
                        </span>

                        <strong>
                            Pemasangan
                        </strong>

                        <p>
                            Teknisi melakukan pemasangan.
                        </p>

                    </div>

                </div>

            </div>

        </section>



        <!-- =====================================================
             CUSTOMER CARD
        ====================================================== -->

        <section class="customer-card">

            <div class="customer-card-header">

                <div>

                    <span>
                        <i class="bi bi-person-circle"></i>
                        AKUN ANDA
                    </span>

                    <h2>
                        Siap mulai, <?= e($nama ?: "Customer") ?>?
                    </h2>

                </div>


                <div class="account-status">

                    <span></span>

                    Akun Terdaftar

                </div>

            </div>


            <div class="customer-card-body">


                <div class="customer-profile">

                    <div class="profile-avatar">

                        <?= e(
                            strtoupper(
                                substr(
                                    $nama !== "" ? $nama : "C",
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>


                    <div>

                        <small>
                            CUSTOMER
                        </small>

                        <strong>
                            <?= e($nama ?: "-") ?>
                        </strong>

                        <span>
                            ID Customer:
                            <?= e(
                                $customerId > 0
                                    ? "CUS" . str_pad(
                                        (string) $customerId,
                                        6,
                                        "0",
                                        STR_PAD_LEFT
                                    )
                                    : "-"
                            ) ?>
                        </span>

                    </div>

                </div>


                <div class="customer-info-list">

                    <div>

                        <i class="bi bi-envelope"></i>

                        <span>

                            <small>
                                Email
                            </small>

                            <strong>
                                <?= e($email ?: "-") ?>
                            </strong>

                        </span>

                    </div>


                    <div>

                        <i class="bi bi-telephone"></i>

                        <span>

                            <small>
                                Nomor Telepon
                            </small>

                            <strong>
                                <?= e($telephone ?: "-") ?>
                            </strong>

                        </span>

                    </div>


                    <?php if ($namaPaket !== ""): ?>

                        <div>

                            <i class="bi bi-router"></i>

                            <span>

                                <small>
                                    Paket Saat Ini
                                </small>

                                <strong>
                                    <?= e($namaPaket) ?>
                                </strong>

                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </section>



        <!-- =====================================================
             ALERT
        ====================================================== -->

        <?php if ($error !== ""): ?>

            <div class="result-alert result-error">

                <div class="result-alert-icon">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                </div>


                <div>

                    <strong>

                        <?= $coverageStatus === "tidak_tersedia"
                            ? "Coverage Belum Tersedia"
                            : "Pengecekan Coverage"
                        ?>

                    </strong>

                    <p>
                        <?= e($error) ?>
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <?php if ($success !== ""): ?>

            <div class="result-alert result-success">

                <div class="result-alert-icon">

                    <i class="bi bi-check-circle-fill"></i>

                </div>


                <div>

                    <strong>
                        Coverage Tersedia
                    </strong>

                    <p>
                        <?= e($success) ?>
                    </p>

                </div>

            </div>

        <?php endif; ?>



        <!-- =====================================================
             MAIN CONTENT
        ====================================================== -->

        <div class="coverage-layout">


            <!-- =================================================
                 FORM
            ================================================== -->

            <section class="location-card">

                <div class="location-card-header">

                    <div class="location-icon">

                        <i class="bi bi-geo-alt-fill"></i>

                    </div>


                    <div>

                        <span>
                            LANGKAH 02
                        </span>

                        <h2>
                            Tentukan Lokasi Pemasangan
                        </h2>

                        <p>
                            Masukkan alamat kemudian tentukan
                            titik lokasi pemasangan pada peta.
                        </p>

                    </div>

                </div>



                <?php if ($namaPaket !== ""): ?>

                    <div class="selected-package">

                        <div class="package-icon">

                            <i class="bi bi-box-seam"></i>

                        </div>


                        <div>

                            <small>
                                PAKET YANG DIPILIH
                            </small>

                            <strong>
                                <?= e($namaPaket) ?>
                            </strong>

                        </div>


                        <?php if ($hargaPaket !== null): ?>

                            <div class="package-price">

                                Rp <?= number_format(
                                    $hargaPaket,
                                    0,
                                    ",",
                                    "."
                                ) ?>

                                <small>
                                    /bulan
                                </small>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>



                <form
                    method="POST"
                    id="coverageForm"
                    autocomplete="off"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="check_coverage"
                    >


                    <!-- ALAMAT -->

                    <div class="field-group">

                        <label for="alamat">

                            <i class="bi bi-house-door"></i>

                            Alamat Pemasangan

                        </label>


                        <textarea
                            name="alamat"
                            id="alamat"
                            rows="4"
                            placeholder="Contoh: Jl. Ahmad Yani No. 123, RT 02/RW 04..."
                            required
                        ><?= e($alamat) ?></textarea>


                        <small class="field-help">

                            Masukkan alamat lengkap agar teknisi
                            mudah menemukan lokasi Anda.

                        </small>

                    </div>



                    <!-- GPS -->

                    <div class="gps-panel">

                        <div class="gps-content">

                            <div class="gps-icon">

                                <i class="bi bi-crosshair"></i>

                            </div>


                            <div>

                                <strong>
                                    Lokasi GPS
                                </strong>

                                <span id="gpsStatus">

                                    Izinkan browser mengakses
                                    lokasi perangkat Anda.

                                </span>

                            </div>

                        </div>


                        <button
                            type="button"
                            id="getLocationBtn"
                            class="gps-button"
                        >

                            <i class="bi bi-geo-alt-fill"></i>

                            Ambil Lokasi

                        </button>

                    </div>



                    <!-- MAP -->

                    <div class="map-wrapper">

                        <div class="map-top">

                            <div>

                                <small>
                                    TITIK PEMASANGAN
                                </small>

                                <h3>
                                    Pilih lokasi pada peta
                                </h3>

                            </div>


                            <a
                                id="openMapsBtn"
                                class="google-map-btn <?= $hasGoogleMapsLocation ? "" : "disabled" ?>"
                                <?php if ($hasGoogleMapsLocation): ?>
                                    href="<?= e($googleMapsUrl) ?>"
                                <?php endif; ?>
                                target="_blank"
                                rel="noopener noreferrer"
                            >

                                <i class="bi bi-google"></i>

                                Google Maps

                            </a>

                        </div>


                        <div
                            id="coverageMap"
                            class="coverage-map"
                        ></div>


                        <div class="map-note">

                            <i class="bi bi-info-circle"></i>

                            Klik peta atau geser marker untuk
                            menentukan titik pemasangan.

                        </div>

                    </div>



                    <!-- COORDINATE -->

                    <div class="coordinate-grid">

                        <div class="coordinate-card">

                            <span>
                                LATITUDE
                            </span>

                            <strong id="latitudeDisplay">

                                <?= $latitude !== ""
                                    ? e($latitude)
                                    : "-"
                                ?>

                            </strong>

                        </div>


                        <div class="coordinate-card">

                            <span>
                                LONGITUDE
                            </span>

                            <strong id="longitudeDisplay">

                                <?= $longitude !== ""
                                    ? e($longitude)
                                    : "-"
                                ?>

                            </strong>

                        </div>

                    </div>


                    <input
                        type="hidden"
                        name="latitude"
                        id="latitude"
                        value="<?= e($latitude) ?>"
                    >


                    <input
                        type="hidden"
                        name="longitude"
                        id="longitude"
                        value="<?= e($longitude) ?>"
                    >



                    <!-- SUBMIT -->

                    <button
                        type="submit"
                        id="checkCoverageBtn"
                        class="submit-coverage-btn"
                    >

                        <span>

                            <i class="bi bi-search"></i>

                            Cek Coverage Sekarang

                        </span>


                        <i class="bi bi-arrow-right"></i>

                    </button>

                </form>

            </section>



            <!-- =================================================
                 SIDEBAR
            ================================================== -->

            <aside class="coverage-sidebar">


                <!-- STATUS -->

                <div class="coverage-status-card">

                    <div class="sidebar-label">

                        <span>
                            STATUS COVERAGE
                        </span>

                        <i class="bi bi-broadcast-pin"></i>

                    </div>


                    <?php if ($coverageStatus === "tersedia"): ?>

                        <div class="big-status-icon available">

                            <i class="bi bi-check-lg"></i>

                        </div>


                        <h3>
                            Coverage Tersedia
                        </h3>


                        <p>
                            Jaringan YESNET dapat digunakan
                            di lokasi pemasangan Anda.
                        </p>


                        <a
                            href="langganan.php"
                            class="sidebar-action success-action"
                        >

                            Pilih Paket Internet

                            <i class="bi bi-arrow-right"></i>

                        </a>


                    <?php elseif ($coverageStatus === "tidak_tersedia"): ?>

                        <div class="big-status-icon unavailable">

                            <i class="bi bi-x-lg"></i>

                        </div>


                        <h3>
                            Coverage Belum Tersedia
                        </h3>


                        <p>
                            Lokasi Anda berada di luar
                            radius coverage ODP terdekat.
                        </p>


                    <?php else: ?>

                        <div class="big-status-icon waiting">

                            <i class="bi bi-geo-alt"></i>

                        </div>


                        <h3>
                            Belum Dicek
                        </h3>


                        <p>
                            Tentukan lokasi pemasangan lalu
                            lakukan pengecekan coverage.
                        </p>

                    <?php endif; ?>

                </div>



                <!-- NETWORK -->

                <?php if ($odpId !== null): ?>

                    <div class="network-info-card">

                        <div class="sidebar-label">

                            <span>
                                INFORMASI JARINGAN
                            </span>

                            <i class="bi bi-router"></i>

                        </div>


                        <div class="network-row">

                            <span>
                                ODP Terdekat
                            </span>

                            <strong>
                                <?= e($namaOdp ?: "-") ?>
                            </strong>

                        </div>


                        <div class="network-row">

                            <span>
                                Jarak ODP
                            </span>

                            <strong>

                                <?= $jarakOdp !== null
                                    ? number_format(
                                        $jarakOdp,
                                        3,
                                        ",",
                                        "."
                                    ) . " km"
                                    : "-"
                                ?>

                            </strong>

                        </div>


                        <div class="network-row">

                            <span>
                                Radius Coverage
                            </span>

                            <strong>

                                <?= $radiusOdp !== null
                                    ? number_format(
                                        $radiusOdp,
                                        3,
                                        ",",
                                        "."
                                    ) . " km"
                                    : "-"
                                ?>

                            </strong>

                        </div>

                    </div>

                <?php endif; ?>



                <!-- TIPS -->

                <div class="tips-card">

                    <div class="tips-header">

                        <i class="bi bi-lightbulb-fill"></i>

                        <strong>
                            Tips
                        </strong>

                    </div>


                    <ul>

                        <li>
                            Pastikan GPS perangkat aktif.
                        </li>

                        <li>
                            Gunakan titik rumah yang sebenarnya.
                        </li>

                        <li>
                            Masukkan alamat dengan lengkap.
                        </li>

                        <li>
                            Geser marker jika GPS kurang akurat.
                        </li>

                    </ul>

                </div>

            </aside>

        </div>

    </div>

</main>



<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    crossorigin=""
></script>


<script
    src="assets/js/coverage.js?v=<?= file_exists(__DIR__ . '/assets/js/coverage.js') ? filemtime(__DIR__ . '/assets/js/coverage.js') : time() ?>"
></script>


</body>
</html>

