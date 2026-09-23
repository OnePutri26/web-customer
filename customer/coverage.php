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
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role   = strtolower(trim((string) ($_SESSION["role"] ?? "")));

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

$paketId = null;
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

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();

    $stmt->close();

    if (!$customer) {
        $error = "Data customer tidak ditemukan.";
    } else {

        $customerId = (int) $customer["id"];

        $paketId = !empty($customer["paket_id"])
            ? (int) $customer["paket_id"]
            : null;

        $nama = (string) ($customer["nama"] ?? "");
        $telephone = (string) ($customer["telephone"] ?? "");
        $email = (string) ($customer["email"] ?? "");
        $alamat = (string) ($customer["alamat"] ?? "");

        $namaPaket = (string) ($customer["nama_paket"] ?? "");
        $hargaPaket = $customer["harga"] !== null
            ? (float) $customer["harga"]
            : null;
    }

} catch (Throwable $e) {

    $error = "Terjadi kesalahan saat mengambil data customer.";
}

/*
|--------------------------------------------------------------------------
| AMBIL INSTALLATION REQUEST TERAKHIR
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

        $stmt->bind_param("i", $customerId);
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

            $coverageStatus = (string) ($request["coverage_status"] ?? "");
            $requestStatus = (string) ($request["status"] ?? "");
        }

    } catch (Throwable $e) {

        // Tidak menghentikan halaman apabila data request belum tersedia.
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

        $stmt->bind_param("i", $odpId);
        $stmt->execute();

        $result = $stmt->get_result();
        $odp = $result->fetch_assoc();

        $stmt->close();

        if ($odp) {

            $namaOdp = (string) ($odp["nama_odp"] ?? "");

            if ($odp["radius"] !== null) {
                $radiusOdp = (float) $odp["radius"];
            }
        }

    } catch (Throwable $e) {

        // Abaikan jika ODP belum tersedia.
    }
}

/*
|--------------------------------------------------------------------------
| POST CEK COVERAGE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = trim((string) ($_POST["action"] ?? ""));

    if ($action === "check_coverage") {

        $alamatPost = trim((string) ($_POST["alamat"] ?? ""));
        $latPost = trim((string) ($_POST["latitude"] ?? ""));
        $lngPost = trim((string) ($_POST["longitude"] ?? ""));

        $alamat = $alamatPost;
        $latitude = $latPost;
        $longitude = $lngPost;

        /*
        |--------------------------------------------------------------------------
        | VALIDASI ALAMAT
        |--------------------------------------------------------------------------
        */

        if ($alamatPost === "") {

            $error = "Alamat pemasangan wajib diisi.";

        } elseif (mb_strlen($alamatPost) < 10) {

            $error = "Alamat pemasangan terlalu pendek. Masukkan alamat yang lebih lengkap.";

        /*
        |--------------------------------------------------------------------------
        | VALIDASI LATITUDE
        |--------------------------------------------------------------------------
        */

        } elseif (
            $latPost === "" ||
            !is_numeric($latPost)
        ) {

            $error = "Lokasi belum dipilih. Silakan gunakan GPS atau klik lokasi pada peta.";

        } elseif (
            (float) $latPost < -90 ||
            (float) $latPost > 90
        ) {

            $error = "Latitude tidak valid.";

        /*
        |--------------------------------------------------------------------------
        | VALIDASI LONGITUDE
        |--------------------------------------------------------------------------
        */

        } elseif (
            $lngPost === "" ||
            !is_numeric($lngPost)
        ) {

            $error = "Longitude tidak valid.";

        } elseif (
            (float) $lngPost < -180 ||
            (float) $lngPost > 180
        ) {

            $error = "Longitude tidak valid.";
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

                $conn->begin_transaction();

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
                | JIKA ODP TIDAK DITEMUKAN
                |--------------------------------------------------------------------------
                */

                if (!$nearestOdp) {

                    $coverageStatus = "tidak_tersedia";

                    $error = "Belum ada ODP aktif yang tersedia di database untuk lokasi tersebut.";

                    $conn->rollback();

                } else {

                    $odpId = (int) $nearestOdp["id"];

                    $namaOdp = (string) $nearestOdp["nama_odp"];

                    $jarakOdp = round(
                        (float) $nearestOdp["jarak"],
                        3
                    );

                    $radiusOdp = (float) $nearestOdp["radius"];

                    /*
                    |--------------------------------------------------------------------------
                    | CEK RADIUS
                    |--------------------------------------------------------------------------
                    */

                    if ($jarakOdp <= $radiusOdp) {

                        $coverageStatus = "tersedia";

                        $success =
                            "Coverage tersedia. "
                            . "Lokasi Anda berjarak sekitar "
                            . number_format($jarakOdp, 3, ",", ".")
                            . " km dari "
                            . $namaOdp
                            . ".";

                    } else {

                        $coverageStatus = "tidak_tersedia";

                        $error =
                            "Coverage belum tersedia. "
                            . "Lokasi Anda berjarak sekitar "
                            . number_format($jarakOdp, 3, ",", ".")
                            . " km dari "
                            . $namaOdp
                            . ", sedangkan radius coverage hanya "
                            . number_format($radiusOdp, 3, ",", ".")
                            . " km.";
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE CUSTOMER
                    |--------------------------------------------------------------------------
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
                    |--------------------------------------------------------------------------
                    | CEK REQUEST TERAKHIR
                    |--------------------------------------------------------------------------
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

                    $requestResult = $stmtRequest->get_result();
                    $existingRequest = $requestResult->fetch_assoc();

                    $stmtRequest->close();

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE / INSERT INSTALLATION REQUEST
                    |--------------------------------------------------------------------------
                    */

                    if ($existingRequest) {

                        $requestId = (int) $existingRequest["id"];

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
                            WHERE id = ?
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

                        $requestStatus = "menunggu";

                    } else {

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
                            "isddidss",
                            $customerId,
                            $alamatPost,
                            $lat,
                            $lng,
                            $odpId,
                            $jarakOdp,
                            $coverageStatus
                        );

                        $stmtInsert->execute();

                        $requestId = $stmtInsert->insert_id;

                        $stmtInsert->close();

                        $requestStatus = "menunggu";
                    }

                    $conn->commit();
                }

            } catch (Throwable $e) {

                if ($conn->errno === 0) {
                    // Tidak melakukan apa-apa.
                }

                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                    // Abaikan rollback error.
                }

                $error =
                    "Terjadi kesalahan saat memproses pengecekan coverage.";

                $success = "";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| GOOGLE MAPS URL
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

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Cek Coverage | WiFi Management</title>

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
        integrity="sha256-p4NxAoJBhIINfQ3Hym7f3Xk9f5Ff8j5F5p5F5F5F5F5="
        crossorigin=""
    >

    <!-- Custom CSS -->
    <link
        rel="stylesheet"
        href="../assets/css/coverage.css?v=<?= file_exists(__DIR__ . '/../assets/css/coverage.css') ? filemtime(__DIR__ . '/../assets/css/coverage.css') : time() ?>"
    >

</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg coverage-navbar">

    <div class="container">

        <a
            href="dashboard.php"
            class="navbar-brand"
        >
            <div class="brand-icon">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="brand-text">
                <strong>WiFi Management</strong>
                <span>YESNET</span>
            </div>
        </a>

        <div class="navbar-user">

            <div class="user-avatar">
                <?= e(strtoupper(substr($nama, 0, 1))) ?>
            </div>

            <div class="user-info">

                <span class="user-label">
                    Customer
                </span>

                <strong>
                    <?= e($nama) ?>
                </strong>

            </div>

            <a
                href="../logout.php"
                class="logout-button"
                title="Logout"
            >
                <i class="bi bi-box-arrow-right"></i>
            </a>

        </div>

    </div>

</nav>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="coverage-page">

    <div class="container">

        <!-- HEADER -->

        <section class="page-header">

            <div class="header-icon">
                <i class="bi bi-geo-alt-fill"></i>
            </div>

            <div>

                <span class="eyebrow">
                    PENDAFTARAN INTERNET
                </span>

                <h1>
                    Cek Coverage Area
                </h1>

                <p>
                    Tentukan lokasi pemasangan dan cek apakah jaringan
                    YESNET tersedia di area Anda.
                </p>

            </div>

        </section>


        <!-- ALERT ERROR -->

        <?php if ($error !== ""): ?>

            <div class="alert-custom alert-error">

                <div class="alert-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <div>
                    <strong>
                        Coverage belum tersedia
                    </strong>

                    <p>
                        <?= e($error) ?>
                    </p>
                </div>

            </div>

        <?php endif; ?>


        <!-- ALERT SUCCESS -->

        <?php if ($success !== ""): ?>

            <div class="alert-custom alert-success-custom">

                <div class="alert-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                    <strong>
                        Coverage tersedia
                    </strong>

                    <p>
                        <?= e($success) ?>
                    </p>
                </div>

            </div>

        <?php endif; ?>


        <div class="coverage-grid">

            <!-- =================================================
                 LEFT
            ================================================== -->

            <div class="coverage-main-card">

                <div class="card-heading">

                    <div>

                        <span class="step-number">
                            01
                        </span>

                        <div>
                            <h2>
                                Lokasi Pemasangan
                            </h2>

                            <p>
                                Masukkan alamat dan tentukan titik
                                lokasi pemasangan Anda.
                            </p>
                        </div>

                    </div>

                </div>


                <!-- PACKAGE -->

                <?php if ($namaPaket !== ""): ?>

                    <div class="package-summary">

                        <div class="package-icon">
                            <i class="bi bi-router-fill"></i>
                        </div>

                        <div class="package-info">

                            <span>
                                Paket yang dipilih
                            </span>

                            <strong>
                                <?= e($namaPaket) ?>
                            </strong>

                        </div>

                        <?php if ($hargaPaket !== null): ?>

                            <div class="package-price">

                                Rp
                                <?= number_format(
                                    $hargaPaket,
                                    0,
                                    ",",
                                    "."
                                ) ?>

                                <small>
                                    / bulan
                                </small>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <!-- FORM -->

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

                    <div class="form-group">

                        <label
                            for="alamat"
                            class="form-label-custom"
                        >
                            <i class="bi bi-house-door"></i>
                            Alamat Pemasangan
                        </label>

                        <textarea
                            name="alamat"
                            id="alamat"
                            class="form-control-custom"
                            rows="4"
                            placeholder="Contoh: Jl. Ahmad Yani No. 123, RT 02/RW 04, Kecamatan..."
                            required
                        ><?= e($alamat) ?></textarea>

                        <div class="form-help">
                            Masukkan alamat lengkap agar teknisi lebih mudah
                            menemukan lokasi pemasangan.
                        </div>

                    </div>


                    <!-- GPS -->

                    <div class="location-action">

                        <div class="location-action-text">

                            <div class="location-action-icon">
                                <i class="bi bi-crosshair"></i>
                            </div>

                            <div>

                                <strong>
                                    Gunakan lokasi saya
                                </strong>

                                <span>
                                    Ambil koordinat GPS perangkat Anda
                                </span>

                            </div>

                        </div>

                        <button
                            type="button"
                            id="getLocationBtn"
                            class="gps-button"
                        >

                            <i class="bi bi-geo-alt-fill"></i>

                            <span>
                                Gunakan GPS
                            </span>

                        </button>

                    </div>


                    <!-- MAP -->

                    <div class="map-section">

                        <div class="map-header">

                            <div>

                                <span class="map-label">
                                    TITIK LOKASI
                                </span>

                                <h3>
                                    Pilih lokasi di peta
                                </h3>

                            </div>

                            <a
                                href="<?= e($googleMapsUrl) ?>"
                                id="openMapsBtn"
                                class="maps-button"
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


                        <div class="map-help">

                            <i class="bi bi-info-circle"></i>

                            <span>
                                Klik pada peta atau geser marker untuk
                                menentukan lokasi pemasangan.
                            </span>

                        </div>

                    </div>


                    <!-- COORDINATES -->

                    <div class="coordinates-grid">

                        <div class="coordinate-box">

                            <span>
                                Latitude
                            </span>

                            <strong id="latitudeDisplay">
                                <?= $latitude !== ""
                                    ? e($latitude)
                                    : "-"
                                ?>
                            </strong>

                        </div>

                        <div class="coordinate-box">

                            <span>
                                Longitude
                            </span>

                            <strong id="longitudeDisplay">
                                <?= $longitude !== ""
                                    ? e($longitude)
                                    : "-"
                                ?>
                            </strong>

                        </div>

                    </div>


                    <!-- HIDDEN COORDINATES -->

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
                        class="check-button"
                        id="checkCoverageBtn"
                    >

                        <span>
                            <i class="bi bi-search"></i>
                            Cek Coverage Sekarang
                        </span>

                        <i class="bi bi-arrow-right"></i>

                    </button>

                </form>

            </div>


            <!-- =================================================
                 RIGHT
            ================================================== -->

            <aside class="coverage-sidebar">

                <!-- STATUS -->

                <div class="status-card">

                    <div class="status-card-header">

                        <span>
                            STATUS COVERAGE
                        </span>

                        <i class="bi bi-broadcast-pin"></i>

                    </div>


                    <?php if ($coverageStatus === "tersedia"): ?>

                        <div class="status-icon available">

                            <i class="bi bi-check-lg"></i>

                        </div>

                        <h3>
                            Coverage Tersedia
                        </h3>

                        <p>
                            Jaringan YESNET dapat digunakan
                            di lokasi Anda.
                        </p>

                    <?php elseif ($coverageStatus === "tidak_tersedia"): ?>

                        <div class="status-icon unavailable">

                            <i class="bi bi-x-lg"></i>

                        </div>

                        <h3>
                            Coverage Belum Tersedia
                        </h3>

                        <p>
                            Lokasi Anda berada di luar radius
                            coverage ODP terdekat.
                        </p>

                    <?php else: ?>

                        <div class="status-icon waiting">

                            <i class="bi bi-geo-alt"></i>

                        </div>

                        <h3>
                            Belum Dicek
                        </h3>

                        <p>
                            Tentukan lokasi Anda lalu lakukan
                            pengecekan coverage.
                        </p>

                    <?php endif; ?>


                    <?php if ($coverageStatus === "tersedia"): ?>

                        <a
                            href="confirm_pemasangan.php"
                            class="continue-button"
                        >

                            Lanjutkan Pemasangan

                            <i class="bi bi-arrow-right"></i>

                        </a>

                    <?php endif; ?>

                </div>


                <!-- NETWORK INFO -->

                <?php if ($odpId !== null): ?>

                    <div class="network-card">

                        <div class="network-card-title">

                            <i class="bi bi-router"></i>

                            <span>
                                Informasi Jaringan
                            </span>

                        </div>


                        <div class="network-item">

                            <span>
                                ODP Terdekat
                            </span>

                            <strong>
                                <?= e($namaOdp ?: "-") ?>
                            </strong>

                        </div>


                        <div class="network-item">

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


                        <div class="network-item">

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

                    <div class="tips-title">

                        <i class="bi bi-lightbulb-fill"></i>

                        Tips

                    </div>

                    <ul>

                        <li>
                            Pastikan GPS perangkat aktif.
                        </li>

                        <li>
                            Gunakan titik lokasi rumah yang sebenarnya.
                        </li>

                        <li>
                            Masukkan alamat secara lengkap.
                        </li>

                        <li>
                            Jika GPS kurang akurat, geser marker
                            secara manual.
                        </li>

                    </ul>

                </div>

            </aside>

        </div>

    </div>

</main>


<!-- =========================================================
     LEAFLET JS
========================================================= -->

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>


<!-- =========================================================
     CUSTOM JS
========================================================= -->

<script
    src="../assets/js/coverage.js?v=<?= file_exists(__DIR__ . '/../assets/js/coverage.js') ? filemtime(__DIR__ . '/../assets/js/coverage.js') : time() ?>"
></script>


</body>
</html>