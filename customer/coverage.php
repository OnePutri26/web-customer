<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

date_default_timezone_set("Asia/Jakarta");

/* =====================================================
   HELPER
===================================================== */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/* =====================================================
   DATABASE CHECK
===================================================== */

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

/* =====================================================
   SESSION CHECK
===================================================== */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["customer_id"]) ||
    ($_SESSION["role"] ?? "") !== "customer"
) {

    header("Location: ../login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$customerId = (int) $_SESSION["customer_id"];

if ($userId <= 0 || $customerId <= 0) {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit;
}

/* =====================================================
   VARIABLE
===================================================== */

$error = "";
$success = "";

$nama = $_SESSION["nama"] ?? "";
$telephone = $_SESSION["telephone"] ?? "";
$email = $_SESSION["email"] ?? "";

$alamatPemasangan = "";

$latitude = "";
$longitude = "";

$odpId = 0;
$namaOdp = "";

$jarakOdp = 0;
$radiusOdp = 0;

$coverageStatus = "";

$step = 1;


/* =====================================================
   CHECK TABLE
===================================================== */

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);

    $result = $conn->query("
        SHOW TABLES LIKE '{$table}'
    ");

    return $result && $result->num_rows > 0;
}


/* =====================================================
   GET CUSTOMER
===================================================== */

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            nama,
            telephone,
            email,
            alamat
        FROM customers
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $customerId,
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $customer = $result->fetch_assoc();

    $stmt->close();


    if (!$customer) {
        die("Data customer tidak ditemukan.");
    }


    $nama = $customer["nama"] ?? $nama;
    $telephone = $customer["telephone"] ?? $telephone;
    $email = $customer["email"] ?? $email;

    $alamatPemasangan = $customer["alamat"] ?? "";


} catch (Throwable $e) {

    die("Data customer tidak dapat dimuat.");
}


/* =====================================================
   LOAD INSTALLATION REQUEST
===================================================== */

$hasInstallationRequest = tableExists(
    $conn,
    "installation_requests"
);

if ($hasInstallationRequest) {

    try {

        $stmt = $conn->prepare("
            SELECT
                alamat_pemasangan,
                latitude,
                longitude,
                odp_id,
                jarak_odp,
                coverage_status
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

            if (!empty($request["alamat_pemasangan"])) {
                $alamatPemasangan =
                    $request["alamat_pemasangan"];
            }

            if (
                $request["latitude"] !== null &&
                $request["latitude"] !== ""
            ) {
                $latitude = $request["latitude"];
            }

            if (
                $request["longitude"] !== null &&
                $request["longitude"] !== ""
            ) {
                $longitude = $request["longitude"];
            }

            $odpId = (int) ($request["odp_id"] ?? 0);

            $jarakOdp =
                (float) ($request["jarak_odp"] ?? 0);

            $coverageStatus =
                $request["coverage_status"] ?? "";

            if (
                $latitude !== "" &&
                $longitude !== ""
            ) {
                $step = 2;
            }
        }

    } catch (Throwable $e) {
        // Tidak menghentikan halaman apabila request belum ada.
    }
}


/* =====================================================
   CHECK COVERAGE
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "check_coverage"
) {

    $alamatPemasangan =
        trim($_POST["alamat_pemasangan"] ?? "");

    $latitude =
        trim($_POST["latitude"] ?? "");

    $longitude =
        trim($_POST["longitude"] ?? "");


    /* =================================================
       VALIDATION
    ================================================= */

    if ($alamatPemasangan === "") {

        $error = "Alamat pemasangan wajib diisi.";

    }

    elseif (
        $latitude === "" ||
        $longitude === ""
    ) {

        $error =
            "Silakan ambil lokasi terlebih dahulu.";

    }

    elseif (
        !is_numeric($latitude) ||
        !is_numeric($longitude)
    ) {

        $error =
            "Koordinat lokasi tidak valid.";

    }

    else {

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;


        if (
            $latitude < -90 ||
            $latitude > 90
        ) {

            $error =
                "Latitude tidak valid.";

        }

        elseif (
            $longitude < -180 ||
            $longitude > 180
        ) {

            $error =
                "Longitude tidak valid.";
        }
    }


    /* =================================================
       SEARCH ODP
    ================================================= */

    if ($error === "") {

        try {

            if (!tableExists($conn, "odp")) {

                throw new Exception(
                    "Tabel ODP belum tersedia."
                );
            }


            /*
             * Haversine
             *
             * Hasil jarak dalam kilometer.
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

                                    COS(
                                        RADIANS(?)
                                    )
                                    *
                                    COS(
                                        RADIANS(latitude)
                                    )
                                    *
                                    COS(
                                        RADIANS(longitude)
                                        -
                                        RADIANS(?)
                                    )
                                    +
                                    SIN(
                                        RADIANS(?)
                                    )
                                    *
                                    SIN(
                                        RADIANS(latitude)
                                    )
                                )
                            )
                        )
                    ) AS jarak

                FROM odp

                WHERE
                    latitude IS NOT NULL
                    AND longitude IS NOT NULL

                ORDER BY jarak ASC

                LIMIT 1
            ";


            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "ddd",
                $latitude,
                $longitude,
                $latitude
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $odp = $result->fetch_assoc();

            $stmt->close();


            if (!$odp) {

                throw new Exception(
                    "Tidak ditemukan ODP di sekitar lokasi."
                );
            }


            /* =================================================
               ODP RESULT
            ================================================= */

            $odpId = (int) $odp["id"];

            $namaOdp =
                $odp["nama_odp"] ?? "";

            $jarakOdp =
                (float) $odp["jarak"];

            /*
             * Radius ODP dalam kilometer.
             *
             * Contoh:
             * 0.5 = 500 meter
             * 1.0 = 1 kilometer
             */

            $radiusOdp =
                (float) $odp["radius"];


            /* =================================================
               COVERAGE STATUS
            ================================================= */

            if ($jarakOdp <= $radiusOdp) {

                $coverageStatus = "tersedia";

                $success =
                    "Lokasi Anda berada dalam jangkauan jaringan.";

            } else {

                $coverageStatus = "tidak_tersedia";

                $success =
                    "Lokasi Anda belum berada dalam jangkauan jaringan.";
            }


            /* =================================================
               SAVE INSTALLATION REQUEST
            ================================================= */

            if ($hasInstallationRequest) {

                /*
                 * Cek request existing
                 */

                $stmtCheck = $conn->prepare("
                    SELECT id
                    FROM installation_requests
                    WHERE customer_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");

                $stmtCheck->bind_param(
                    "i",
                    $customerId
                );

                $stmtCheck->execute();

                $existingResult =
                    $stmtCheck->get_result();

                $existing =
                    $existingResult->fetch_assoc();

                $stmtCheck->close();


                $status =
                    "waiting_confirmation";


                if ($existing) {

                    $requestId =
                        (int) $existing["id"];


                    $stmtUpdate = $conn->prepare("
                        UPDATE installation_requests
                        SET
                            alamat_pemasangan = ?,
                            latitude = ?,
                            longitude = ?,
                            odp_id = ?,
                            jarak_odp = ?,
                            coverage_status = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");


                    $stmtUpdate->bind_param(
                        "sssidssi",
                        $alamatPemasangan,
                        $latitude,
                        $longitude,
                        $odpId,
                        $jarakOdp,
                        $coverageStatus,
                        $status,
                        $requestId
                    );


                    $stmtUpdate->execute();

                    $stmtUpdate->close();

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
                            created_at,
                            updated_at
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
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");


                    $stmtInsert->bind_param(
                        "isssids s",
                        $customerId,
                        $alamatPemasangan,
                        $latitude,
                        $longitude,
                        $odpId,
                        $jarakOdp,
                        $coverageStatus,
                        $status
                    );


                    $stmtInsert->execute();

                    $stmtInsert->close();
                }
            }


            /* =================================================
               UPDATE CUSTOMER ADDRESS
            ================================================= */

            $stmtCustomer = $conn->prepare("
                UPDATE customers
                SET alamat = ?
                WHERE id = ?
                  AND user_id = ?
            ");

            $stmtCustomer->bind_param(
                "sii",
                $alamatPemasangan,
                $customerId,
                $userId
            );

            $stmtCustomer->execute();

            $stmtCustomer->close();


            $step = 2;


        } catch (Throwable $e) {

            $error =
                "Coverage gagal diproses. Pastikan data ODP dan database sudah tersedia.";

            $coverageStatus = "";
        }
    }
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
        Cek Coverage - WiFi Management
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/coverage.css"
    >

</head>

<body>

<div class="coverage-wrapper">

    <div class="coverage-card">

        <!-- HEADER -->

        <div class="coverage-header">

            <img
                src="../logo-yesnet.png"
                alt="YESNET"
            >

            <div>

                <h1>
                    Cek Coverage
                </h1>

                <p>
                    Halo, <?= e($nama) ?>.
                    Cek apakah lokasi Anda sudah terjangkau jaringan YESNET.
                </p>

            </div>

        </div>


        <!-- PROGRESS -->

        <div class="progress-wrapper">

            <div class="progress-step active">

                <div class="step-number">
                    1
                </div>

                <span>
                    Register
                </span>

            </div>


            <div class="progress-line active"></div>


            <div class="progress-step active">

                <div class="step-number">
                    2
                </div>

                <span>
                    Coverage
                </span>

            </div>


            <div class="progress-line"></div>


            <div class="progress-step">

                <div class="step-number">
                    3
                </div>

                <span>
                    Konfirmasi
                </span>

            </div>


            <div class="progress-line"></div>


            <div class="progress-step">

                <div class="step-number">
                    4
                </div>

                <span>
                    Paket
                </span>

            </div>


            <div class="progress-line"></div>


            <div class="progress-step">

                <div class="step-number">
                    5
                </div>

                <span>
                    Pemasangan
                </span>

            </div>

        </div>


        <!-- ALERT ERROR -->

        <?php if ($error !== ""): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-triangle-fill"></i>

                <?= e($error) ?>

            </div>

        <?php endif; ?>


        <!-- ALERT SUCCESS -->

        <?php if ($success !== ""): ?>

            <div class="alert alert-info">

                <i class="bi bi-info-circle-fill"></i>

                <?= e($success) ?>

            </div>

        <?php endif; ?>


        <!-- CUSTOMER INFORMATION -->

        <div class="customer-info">

            <div class="info-title">

                <i class="bi bi-person-circle"></i>

                Data Pelanggan

            </div>


            <div class="row">

                <div class="col-md-4">

                    <small>
                        Nama
                    </small>

                    <strong>
                        <?= e($nama) ?>
                    </strong>

                </div>


                <div class="col-md-4">

                    <small>
                        Telepon
                    </small>

                    <strong>
                        <?= e($telephone) ?>
                    </strong>

                </div>


                <div class="col-md-4">

                    <small>
                        Email
                    </small>

                    <strong>
                        <?= e($email) ?>
                    </strong>

                </div>

            </div>

        </div>


        <!-- COVERAGE FORM -->

        <form
            method="POST"
            action=""
            id="coverageForm"
        >

            <input
                type="hidden"
                name="action"
                value="check_coverage"
            >


            <div class="form-section">

                <div class="section-title">

                    <i class="bi bi-geo-alt-fill"></i>

                    Lokasi Pemasangan

                </div>


                <p class="section-description">

                    Masukkan alamat pemasangan dan ambil lokasi
                    menggunakan GPS untuk mengetahui jaringan YESNET
                    yang tersedia di sekitar Anda.

                </p>


                <!-- ALAMAT -->

                <div class="mb-3">

                    <label class="form-label">
                        Alamat Pemasangan
                    </label>

                    <textarea
                        name="alamat_pemasangan"
                        id="alamatPemasangan"
                        class="form-control"
                        rows="4"
                        placeholder="Masukkan alamat lengkap lokasi pemasangan..."
                        required
                    ><?= e($alamatPemasangan) ?></textarea>

                </div>


                <!-- LOCATION -->

                <div class="location-box">

                    <div class="location-icon">

                        <i class="bi bi-crosshair"></i>

                    </div>


                    <div class="location-content">

                        <h5>
                            Lokasi GPS
                        </h5>

                        <p id="locationStatus">
                            Belum mengambil lokasi.
                        </p>


                        <div
                            class="coordinates"
                            id="coordinates"
                        >
                            Latitude:
                            <span id="latitudeText">
                                -
                            </span>

                            <br>

                            Longitude:
                            <span id="longitudeText">
                                -
                            </span>
                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn-location"
                        id="getLocation"
                    >

                        <i class="bi bi-geo-alt-fill"></i>

                        Ambil Lokasi

                    </button>

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


                <!-- BUTTON -->

                <button
                    type="submit"
                    class="btn-check-coverage"
                    id="checkButton"
                >

                    <i class="bi bi-search"></i>

                    Cek Coverage

                </button>

            </div>

        </form>


        <!-- RESULT -->

        <?php if ($coverageStatus !== ""): ?>

            <div
                class="coverage-result
                <?= $coverageStatus === "tersedia"
                    ? "available"
                    : "unavailable" ?>"
            >

                <?php if ($coverageStatus === "tersedia"): ?>

                    <div class="result-icon">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div class="result-content">

                        <h3>
                            Coverage Tersedia
                        </h3>

                        <p>
                            Lokasi Anda berada dalam jangkauan jaringan YESNET.
                        </p>


                        <div class="odp-info">

                            <div>
                                <small>
                                    ODP Terdekat
                                </small>

                                <strong>
                                    <?= e($namaOdp) ?>
                                </strong>
                            </div>


                            <div>
                                <small>
                                    Jarak
                                </small>

                                <strong>
                                    <?= number_format(
                                        $jarakOdp,
                                        3,
                                        ",",
                                        "."
                                    ) ?>
                                    km
                                </strong>
                            </div>


                            <div>
                                <small>
                                    Radius
                                </small>

                                <strong>
                                    <?= number_format(
                                        $radiusOdp,
                                        3,
                                        ",",
                                        "."
                                    ) ?>
                                    km
                                </strong>
                            </div>

                        </div>

                    </div>


                    <a
                        href="confirm-pemasangan.php"
                        class="btn-next"
                    >

                        Lanjut Konfirmasi

                        <i class="bi bi-arrow-right"></i>

                    </a>

                <?php else: ?>

                    <div class="result-icon">

                        <i class="bi bi-x-circle-fill"></i>

                    </div>

                    <div class="result-content">

                        <h3>
                            Coverage Belum Tersedia
                        </h3>

                        <p>
                            Lokasi Anda berada di luar jangkauan
                            ODP terdekat.
                        </p>


                        <?php if ($namaOdp !== ""): ?>

                            <div class="odp-info">

                                <div>

                                    <small>
                                        ODP Terdekat
                                    </small>

                                    <strong>
                                        <?= e($namaOdp) ?>
                                    </strong>

                                </div>


                                <div>

                                    <small>
                                        Jarak
                                    </small>

                                    <strong>
                                        <?= number_format(
                                            $jarakOdp,
                                            3,
                                            ",",
                                            "."
                                        ) ?>
                                        km
                                    </strong>

                                </div>


                                <div>

                                    <small>
                                        Radius
                                    </small>

                                    <strong>
                                        <?= number_format(
                                            $radiusOdp,
                                            3,
                                            ",",
                                            "."
                                        ) ?>
                                        km
                                    </strong>

                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <!-- FOOTER -->

        <div class="coverage-footer">

            <span>
                <i class="bi bi-shield-check"></i>
                Data Anda aman dan terlindungi.
            </span>

            <a href="../login.php">
                Keluar
            </a>

        </div>

    </div>

</div>


<script>

const getLocationButton =
    document.getElementById("getLocation");

const latitudeInput =
    document.getElementById("latitude");

const longitudeInput =
    document.getElementById("longitude");

const latitudeText =
    document.getElementById("latitudeText");

const longitudeText =
    document.getElementById("longitudeText");

const locationStatus =
    document.getElementById("locationStatus");


getLocationButton.addEventListener(
    "click",
    function ()
    {

        if (!navigator.geolocation)
        {
            locationStatus.textContent =
                "Browser Anda tidak mendukung GPS.";

            return;
        }


        getLocationButton.disabled = true;

        getLocationButton.innerHTML =
            '<i class="bi bi-hourglass-split"></i> Mengambil lokasi...';


        locationStatus.textContent =
            "Sedang mengambil lokasi Anda...";


        navigator.geolocation.getCurrentPosition(

            function (position)
            {

                const latitude =
                    position.coords.latitude;

                const longitude =
                    position.coords.longitude;


                latitudeInput.value =
                    latitude;

                longitudeInput.value =
                    longitude;


                latitudeText.textContent =
                    latitude.toFixed(6);

                longitudeText.textContent =
                    longitude.toFixed(6);


                locationStatus.textContent =
                    "Lokasi berhasil ditemukan.";


                getLocationButton.disabled =
                    false;

                getLocationButton.innerHTML =
                    '<i class="bi bi-check-circle"></i> Lokasi Didapatkan';

            },

            function (error)
            {

                let message =
                    "Lokasi tidak dapat diambil.";


                if (error.code === 1)
                {
                    message =
                        "Izin lokasi ditolak. Silakan izinkan akses lokasi pada browser.";
                }

                else if (error.code === 2)
                {
                    message =
                        "Lokasi tidak tersedia.";
                }

                else if (error.code === 3)
                {
                    message =
                        "Waktu pengambilan lokasi habis.";
                }


                locationStatus.textContent =
                    message;


                getLocationButton.disabled =
                    false;

                getLocationButton.innerHTML =
                    '<i class="bi bi-geo-alt-fill"></i> Ambil Lokasi';

            },

            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }

        );

    }
);


/* =====================================================
   FORM VALIDATION
===================================================== */

document.getElementById("coverageForm")
    .addEventListener(
        "submit",
        function (event)
        {

            const latitude =
                latitudeInput.value;

            const longitude =
                longitudeInput.value;


            if (
                latitude === "" ||
                longitude === ""
            ) {

                event.preventDefault();

                alert(
                    "Silakan ambil lokasi GPS terlebih dahulu."
                );

                return false;
            }

        }
    );


/* =====================================================
   RESTORE COORDINATES
===================================================== */

if (
    latitudeInput.value !== "" &&
    longitudeInput.value !== ""
) {

    latitudeText.textContent =
        Number(latitudeInput.value)
            .toFixed(6);

    longitudeText.textContent =
        Number(longitudeInput.value)
            .toFixed(6);

    locationStatus.textContent =
        "Lokasi sebelumnya berhasil dimuat.";

}

</script>

</body>
</html>