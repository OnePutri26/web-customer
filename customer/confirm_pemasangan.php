<?php

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
| CEK LOGIN CUSTOMER
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    empty($_SESSION['user_id'])
) {
    header("Location: ../login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

try {

    $stmtCustomer = $conn->prepare("
        SELECT
            c.id,
            c.user_id,
            c.nama,
            c.telephone,
            c.email,
            c.nik,
            c.alamat,
            c.paket_id,
            c.status_langganan
        FROM customers c
        WHERE c.user_id = ?
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

} catch (Throwable $e) {

    die(
        "Gagal mengambil data customer: " .
        e($e->getMessage())
    );
}

/*
|--------------------------------------------------------------------------
| AMBIL REQUEST PEMASANGAN TERBARU
|--------------------------------------------------------------------------
*/

try {

    $stmtInstallation = $conn->prepare("
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
            ir.created_at,
            ir.updated_at,

            o.nama_odp,
            o.latitude AS odp_latitude,
            o.longitude AS odp_longitude,
            o.radius AS odp_radius

        FROM installation_requests ir

        LEFT JOIN odp o
            ON o.id = ir.odp_id

        WHERE ir.customer_id = ?

        ORDER BY ir.id DESC

        LIMIT 1
    ");

    $stmtInstallation->bind_param(
        "i",
        $customerId
    );

    $stmtInstallation->execute();

    $resultInstallation =
        $stmtInstallation->get_result();

    $installation =
        $resultInstallation->fetch_assoc();

    $stmtInstallation->close();

} catch (Throwable $e) {

    die(
        "Gagal mengambil data pemasangan: " .
        e($e->getMessage())
    );
}

/*
|--------------------------------------------------------------------------
| REQUEST TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$installation) {

    header("Location: coverage.php");
    exit;
}

$installationId =
    (int) $installation['id'];

/*
|--------------------------------------------------------------------------
| CEK COVERAGE
|--------------------------------------------------------------------------
*/

$coverageStatus = strtolower(
    trim(
        (string) $installation['coverage_status']
    )
);

if (
    $coverageStatus !== 'tercover' &&
    $coverageStatus !== 'tersedia' &&
    $coverageStatus !== 'covered'
) {

    header("Location: coverage.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| STATUS REQUEST SAAT INI
|--------------------------------------------------------------------------
*/

$currentStatus = strtolower(
    trim(
        (string) $installation['status']
    )
);

/*
|--------------------------------------------------------------------------
| PESAN ERROR
|--------------------------------------------------------------------------
*/

$error = '';

/*
|--------------------------------------------------------------------------
| PROSES KONFIRMASI
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | VALIDASI ACTION
    |--------------------------------------------------------------------------
    */

    if ($action !== 'confirm_installation') {

        $error =
            "Permintaan tidak valid.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | VALIDASI CSRF
        |--------------------------------------------------------------------------
        */

        $submittedToken =
            $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            $submittedToken === '' ||
            !hash_equals(
                $csrfToken,
                $submittedToken
            )
        ) {

            $error =
                "Token keamanan tidak valid. " .
                "Silakan muat ulang halaman dan coba lagi.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | AMBIL STATUS TERBARU DARI DATABASE
                |--------------------------------------------------------------------------
                */

                $stmtStatus = $conn->prepare("
                    SELECT
                        status,
                        coverage_status
                    FROM installation_requests
                    WHERE id = ?
                      AND customer_id = ?
                    LIMIT 1
                ");

                $stmtStatus->bind_param(
                    "ii",
                    $installationId,
                    $customerId
                );

                $stmtStatus->execute();

                $resultStatus =
                    $stmtStatus->get_result();

                $latestInstallation =
                    $resultStatus->fetch_assoc();

                $stmtStatus->close();

                if (!$latestInstallation) {

                    $error =
                        "Data pemasangan tidak ditemukan.";

                } else {

                    $latestStatus =
                        strtolower(
                            trim(
                                (string)
                                $latestInstallation['status']
                            )
                        );

                    $latestCoverage =
                        strtolower(
                            trim(
                                (string)
                                $latestInstallation['coverage_status']
                            )
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | CEK COVERAGE TERBARU
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $latestCoverage !== 'tercover' &&
                        $latestCoverage !== 'tersedia' &&
                        $latestCoverage !== 'covered'
                    ) {

                        $error =
                            "Lokasi pemasangan sudah tidak " .
                            "berstatus tercover.";

                    /*
                    |--------------------------------------------------------------------------
                    | CEGAH PENGIRIMAN ULANG
                    |--------------------------------------------------------------------------
                    */

                    } elseif (
                        $latestStatus ===
                        'menunggu_pemasangan'
                    ) {

                        $_SESSION[
                            'installation_request_id'
                        ] = $installationId;

                        $_SESSION[
                            'installation_status'
                        ] = 'menunggu_pemasangan';

                        $_SESSION[
                            'flash_success'
                        ] =
                            'Pemasangan sudah dikonfirmasi sebelumnya.';

                        header(
                            "Location: langganan.php"
                        );

                        exit;

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE STATUS
                        |--------------------------------------------------------------------------
                        */

                        $newStatus =
                            'menunggu_pemasangan';

                        $stmtUpdate = $conn->prepare("
                            UPDATE installation_requests
                            SET
                                status = ?,
                                updated_at =
                                    CURRENT_TIMESTAMP
                            WHERE id = ?
                              AND customer_id = ?
                              AND status <> ?
                            LIMIT 1
                        ");

                        $stmtUpdate->bind_param(
                            "siis",
                            $newStatus,
                            $installationId,
                            $customerId,
                            $newStatus
                        );

                        $stmtUpdate->execute();

                        $affectedRows =
                            $stmtUpdate->affected_rows;

                        $stmtUpdate->close();

                        /*
                        |--------------------------------------------------------------------------
                        | BERHASIL
                        |--------------------------------------------------------------------------
                        */

                        if ($affectedRows === 1) {

                            $_SESSION[
                                'installation_request_id'
                            ] = $installationId;

                            $_SESSION[
                                'installation_status'
                            ] = $newStatus;

                            $_SESSION[
                                'flash_success'
                            ] =
                                'Pemasangan berhasil dikonfirmasi.';

                            /*
                            |--------------------------------------------------------------------------
                            | POST REDIRECT GET
                            |--------------------------------------------------------------------------
                            */

                            header(
                                "Location: langganan.php"
                            );

                            exit;

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | Request kemungkinan sudah diproses
                            |--------------------------------------------------------------------------
                            */

                            $_SESSION[
                                'installation_request_id'
                            ] = $installationId;

                            $_SESSION[
                                'installation_status'
                            ] = $newStatus;

                            $_SESSION[
                                'flash_success'
                            ] =
                                'Pemasangan sudah dikonfirmasi.';

                            header(
                                "Location: langganan.php"
                            );

                            exit;
                        }
                    }
                }

            } catch (Throwable $e) {

                $error =
                    "Gagal mengonfirmasi pemasangan: " .
                    $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DATA TAMPILAN
|--------------------------------------------------------------------------
*/

$namaCustomer =
    $customer['nama'] ?? '';

$telephone =
    $customer['telephone'] ?? '';

$email =
    $customer['email'] ?? '';

$alamatPemasangan =
    $installation['alamat_pemasangan'] ?? '';

$latitude =
    $installation['latitude'] ?? '';

$longitude =
    $installation['longitude'] ?? '';

$namaOdp =
    $installation['nama_odp'] ??
    'ODP tidak ditemukan';

$jarakOdp =
    $installation['jarak_odp'] ??
    null;

$radiusOdp =
    $installation['odp_radius'] ??
    null;

/*
|--------------------------------------------------------------------------
| FORMAT JARAK
|--------------------------------------------------------------------------
*/

$jarakText = "-";

if (
    $jarakOdp !== null &&
    $jarakOdp !== ''
) {

    $jarakText =
        number_format(
            (float) $jarakOdp,
            3,
            ',',
            '.'
        ) . " km";
}

/*
|--------------------------------------------------------------------------
| FORMAT RADIUS
|--------------------------------------------------------------------------
*/

$radiusText = "-";

if (
    $radiusOdp !== null &&
    $radiusOdp !== ''
) {

    $radiusText =
        number_format(
            (float) $radiusOdp,
            3,
            ',',
            '.'
        ) . " km";
}

/*
|--------------------------------------------------------------------------
| CEK SUDAH DIKONFIRMASI
|--------------------------------------------------------------------------
*/

$isAlreadyConfirmed =
    $currentStatus === 'menunggu_pemasangan';

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
        Konfirmasi Pemasangan - YESNET
    </title>

    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- CSS Halaman -->

    <link
        rel="stylesheet"
        href="../assets/css/confirm_pemasangan.css"
    >

</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-light bg-white shadow-sm">

    <div class="container">

        <a
            class="navbar-brand"
            href="#"
        >

            <img
                src="../logo-yesnet.png"
                alt="YESNET"
            >

        </a>

        <span class="page-label">
            Konfirmasi Pemasangan
        </span>

    </div>

</nav>


<!-- =========================================================
     CONTENT
========================================================= -->

<div class="page-wrapper">

    <div class="main-card">

        <!-- HEADER -->

        <div class="card-header-custom">

            <h3>

                <i
                    class="bi bi-check-circle-fill me-2"
                ></i>

                Konfirmasi Pemasangan

            </h3>

            <p>
                Pastikan data alamat pemasangan kamu sudah benar.
            </p>

        </div>


        <div class="content">

            <!-- ERROR -->

            <?php if ($error !== ''): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <i
                        class="bi bi-exclamation-triangle-fill me-2"
                    ></i>

                    <?= e($error); ?>

                </div>

            <?php endif; ?>


            <!-- STATUS COVERAGE -->

            <?php if ($isAlreadyConfirmed): ?>

                <div
                    class="alert alert-success"
                    role="alert"
                >

                    <i
                        class="bi bi-check-circle-fill me-2"
                    ></i>

                    Pemasangan ini sudah dikonfirmasi.

                </div>

            <?php else: ?>

                <div class="coverage-success">

                    <div class="coverage-icon">

                        <i
                            class="bi bi-wifi"
                        ></i>

                    </div>

                    <div>

                        <h5>
                            Area kamu tercover!
                        </h5>

                        <div>
                            Lokasi pemasangan berada dalam
                            jangkauan jaringan YESNET.
                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 DATA PELANGGAN
            ================================================== -->

            <div class="section-title">

                <i
                    class="bi bi-person-circle me-2"
                ></i>

                Data Pelanggan

            </div>


            <div class="info-box">

                <div class="info-row">

                    <div class="info-label">
                        Nama
                    </div>

                    <div class="info-value">
                        <?= e($namaCustomer); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        No. Telepon
                    </div>

                    <div class="info-value">
                        <?= e($telephone); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        Email
                    </div>

                    <div class="info-value">
                        <?= e($email); ?>
                    </div>

                </div>

            </div>


            <!-- =================================================
                 DATA PEMASANGAN
            ================================================== -->

            <div class="section-title">

                <i
                    class="bi bi-geo-alt-fill me-2"
                ></i>

                Data Pemasangan

            </div>


            <div class="info-box">

                <div class="info-row">

                    <div class="info-label">
                        Alamat Pemasangan
                    </div>

                    <div class="info-value">
                        <?= nl2br(e($alamatPemasangan)); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        ODP Terdekat
                    </div>

                    <div class="info-value">
                        <?= e($namaOdp); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        Jarak ke ODP
                    </div>

                    <div class="info-value">
                        <?= e($jarakText); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        Radius Coverage
                    </div>

                    <div class="info-value">
                        <?= e($radiusText); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        Latitude
                    </div>

                    <div class="info-value">
                        <?= e($latitude); ?>
                    </div>

                </div>


                <div class="info-row">

                    <div class="info-label">
                        Longitude
                    </div>

                    <div class="info-value">
                        <?= e($longitude); ?>
                    </div>

                </div>

            </div>


            <!-- =================================================
                 KONFIRMASI
            ================================================== -->

            <?php if (!$isAlreadyConfirmed): ?>

                <div class="confirmation-box">

                    <div class="confirmation-icon">

                        <i
                            class="bi bi-info-circle-fill"
                        ></i>

                    </div>

                    <div>

                        <strong>
                            Periksa kembali alamat pemasangan
                        </strong>

                        <p class="mb-0 mt-1">

                            Pastikan alamat dan lokasi GPS
                            sudah sesuai dengan lokasi
                            pemasangan internet.

                            Setelah dikonfirmasi, kamu akan
                            diarahkan untuk memilih paket
                            internet.

                        </p>

                    </div>

                </div>


                <!-- BUTTON -->

                <div class="action-buttons">

                    <a
                        href="coverage.php"
                        class="btn btn-outline-secondary btn-back"
                    >

                        <i
                            class="bi bi-arrow-left me-1"
                        ></i>

                        Kembali

                    </a>


                    <form
                        method="POST"
                        class="confirm-form"
                        id="confirmForm"
                    >

                        <!-- CSRF TOKEN -->

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken); ?>"
                        >

                        <!-- ACTION -->

                        <input
                            type="hidden"
                            name="action"
                            value="confirm_installation"
                        >


                        <button
                            type="submit"
                            class="btn btn-primary btn-confirm"
                            id="confirmButton"
                        >

                            <i
                                class="bi bi-check-lg me-1"
                            ></i>

                            <span id="buttonText">
                                Konfirmasi Pemasangan
                            </span>

                        </button>

                    </form>

                </div>

            <?php else: ?>

                <div class="action-buttons">

                    <a
                        href="langganan.php"
                        class="btn btn-primary btn-confirm full-button"
                    >

                        <i
                            class="bi bi-box-seam me-1"
                        ></i>

                        Lanjut Pilih Paket

                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<!-- =========================================================
     ANTI DOUBLE SUBMIT
========================================================= -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const form =
            document.getElementById(
                "confirmForm"
            );

        const button =
            document.getElementById(
                "confirmButton"
            );

        const buttonText =
            document.getElementById(
                "buttonText"
            );

        if (!form || !button) {
            return;
        }

        form.addEventListener(
            "submit",
            function (event) {

                /*
                |--------------------------------------------------------------------------
                | Cegah submit kedua
                |--------------------------------------------------------------------------
                */

                if (button.disabled) {

                    event.preventDefault();

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | Disable button
                |--------------------------------------------------------------------------
                */

                button.disabled = true;

                button.classList.add(
                    "is-processing"
                );

                /*
                |--------------------------------------------------------------------------
                | Ubah teks tombol
                |--------------------------------------------------------------------------
                */

                buttonText.textContent =
                    "Memproses...";

            }
        );

    }
);

</script>

</body>

</html>
