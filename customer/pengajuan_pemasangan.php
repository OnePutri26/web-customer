<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

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


/*
|--------------------------------------------------------------------------
| HANYA POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ACTION
|--------------------------------------------------------------------------
*/

$action = trim(
    (string) ($_POST['action'] ?? '')
);

if ($action !== 'select_package') {

    $_SESSION['flash_error'] =
        'Permintaan tidak valid.';

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| PAKET ID
|--------------------------------------------------------------------------
*/

$paketId = (int) (
    $_POST['paket_id'] ?? 0
);

if ($paketId <= 0) {

    $_SESSION['flash_error'] =
        'Paket WiFi tidak valid.';

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER
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

    $_SESSION['flash_error'] =
        'Data customer tidak ditemukan.';

    header("Location: packages.php");
    exit;
}


$customerId = (int) $customer['id'];


/*
|--------------------------------------------------------------------------
| CEK STATUS LANGGANAN
|--------------------------------------------------------------------------
*/

$statusLangganan = strtolower(
    trim(
        (string) (
            $customer['status_langganan']
            ?? ''
        )
    )
);


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
| CEK PAKET
|--------------------------------------------------------------------------
*/

$stmtPackage = $conn->prepare("
    SELECT
        id,
        nama_paket,
        speed_mbps,
        harga,
        deskripsi,
        status
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


if (!$package) {

    $_SESSION['flash_error'] =
        'Paket WiFi tidak ditemukan.';

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CEK STATUS PAKET
|--------------------------------------------------------------------------
*/

$packageStatus = strtolower(
    trim(
        (string) (
            $package['status'] ?? ''
        )
    )
);


$packageAvailable =
    $packageStatus === ''
    ||
    in_array(
        $packageStatus,
        [
            'aktif',
            'active',
            '1',
            'tersedia',
            'available'
        ],
        true
    );


if (!$packageAvailable) {

    $_SESSION['flash_error'] =
        'Paket yang dipilih sudah tidak tersedia.';

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CEK PENGAJUAN TERAKHIR
|--------------------------------------------------------------------------
*/

$stmtRequest = $conn->prepare("
    SELECT
        id,
        status,
        coverage_status,
        paket_id
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

$resultRequest =
    $stmtRequest->get_result();

$latestRequest =
    $resultRequest->fetch_assoc();

$stmtRequest->close();


/*
|--------------------------------------------------------------------------
| CEK APAKAH SUDAH MENUNGGU VALIDASI CS
|--------------------------------------------------------------------------
*/

if ($latestRequest) {

    $latestStatus = strtolower(
        trim(
            (string) (
                $latestRequest['status']
                ?? ''
            )
        )
    );


    if (
        in_array(
            $latestStatus,
            [
                'menunggu_validasi_cs',
                'pending_cs',
                'validasi_cs',
                'menunggu_cs'
            ],
            true
        )
        &&
        (int) $latestRequest['paket_id'] === $paketId
    ) {

        $_SESSION['installation_request_id'] =
            (int) $latestRequest['id'];

        header("Location: validasi_cs.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | SIMPAN PAKET CUSTOMER
    |--------------------------------------------------------------------------
    */

    $stmtUpdateCustomer = $conn->prepare("
        UPDATE customers
        SET paket_id = ?
        WHERE id = ?
        LIMIT 1
    ");

    $stmtUpdateCustomer->bind_param(
        "ii",
        $paketId,
        $customerId
    );

    $stmtUpdateCustomer->execute();

    $stmtUpdateCustomer->close();


    /*
    |--------------------------------------------------------------------------
    | UPDATE / INSERT INSTALLATION REQUEST
    |--------------------------------------------------------------------------
    */

    if ($latestRequest) {

        $requestId =
            (int) $latestRequest['id'];

        $stmtUpdateRequest = $conn->prepare("
            UPDATE installation_requests
            SET
                paket_id = ?,
                status = 'menunggu_validasi_cs',
                updated_at = CURRENT_TIMESTAMP
            WHERE
                id = ?
                AND customer_id = ?
            LIMIT 1
        ");

        /*
        NOTE:
        Query ini membutuhkan kolom paket_id
        pada installation_requests.
        */

        $stmtUpdateRequest->bind_param(
            "iii",
            $paketId,
            $requestId,
            $customerId
        );

        $stmtUpdateRequest->execute();

        $stmtUpdateRequest->close();


    } else {

        /*
        Jika belum ada request sama sekali,
        buat request baru.

        Karena alamat + koordinat seharusnya
        sudah diperoleh pada proses coverage,
        kita ambil data request coverage terakhir.
        */

        throw new Exception(
            "Data pengajuan pemasangan belum ditemukan. Silakan lakukan pengecekan coverage terlebih dahulu."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['installation_request_id'] =
        $requestId;

    $_SESSION['installation_status'] =
        'menunggu_validasi_cs';

    $_SESSION['selected_package_id'] =
        $paketId;

    $_SESSION['selected_package_name'] =
        $package['nama_paket'];


    /*
    |--------------------------------------------------------------------------
    | REDIRECT
    |--------------------------------------------------------------------------
    */

    header("Location: validasi_cs.php");
    exit;


} catch (Throwable $e) {

    $conn->rollback();

    $_SESSION['flash_error'] =
        $e->getMessage();

    header("Location: packages.php");
    exit;
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
        Pengajuan Pemasangan | WiFi Management
    </title>


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link rel="stylesheet" href="assets/css/theme.css">


    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;

            --bg: #f5f7fb;

            --white: #ffffff;

            --text: #111827;
            --muted: #6b7280;

            --border: #e5e7eb;

            --success: #16a34a;
            --danger: #dc2626;
        }


        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            min-height: 100vh;

            font-family:
                Inter,
                "Segoe UI",
                Arial,
                sans-serif;

            background:
                radial-gradient(
                    circle at 10% 0%,
                    rgba(37,99,235,.08),
                    transparent 30%
                ),
                var(--bg);

            color: var(--text);
        }


        .page {

            width:
                min(
                    1100px,
                    calc(100% - 30px)
                );

            margin:
                0 auto;

            padding:
                30px 0 50px;
        }


        .topbar {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            margin-bottom: 25px;
        }


        .back {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            padding:
                10px 15px;

            border:
                1px solid var(--border);

            border-radius:
                12px;

            background:
                var(--white);

            color:
                var(--text);

            text-decoration:
                none;

            font-size:
                13px;

            font-weight:
                700;
        }


        .back:hover {
            color: var(--primary);
        }


        .brand {

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .brand-icon {

            width: 42px;
            height: 42px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;

            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    #60a5fa
                );

            color: #fff;

            font-size: 19px;
        }


        .brand strong {

            display: block;

            font-size: 15px;
        }


        .brand span {

            display: block;

            color: var(--muted);

            font-size: 11px;
        }


        .layout {

            display: grid;

            grid-template-columns:
                minmax(0, .85fr)
                minmax(0, 1.15fr);

            gap: 22px;
        }


        .card {

            background:
                rgba(255,255,255,.96);

            border:
                1px solid var(--border);

            border-radius:
                22px;

            box-shadow:
                0 15px 40px
                rgba(15,23,42,.07);
        }


        .package-summary {

            padding:
                28px;
        }


        .label {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            padding:
                6px 10px;

            border-radius:
                999px;

            background:
                #eff6ff;

            color:
                var(--primary);

            font-size:
                10px;

            font-weight:
                800;
        }


        .package-summary h1 {

            margin:
                16px 0 5px;

            font-size:
                27px;
        }


        .package-summary > p {

            margin:
                0 0 25px;

            color:
                var(--muted);

            font-size:
                13px;

            line-height:
                1.7;
        }


        .speed {

            display: flex;

            align-items: center;

            gap: 14px;

            padding:
                17px;

            margin-bottom:
                15px;

            border-radius:
                15px;

            background:
                #f8fafc;

            border:
                1px solid #eef0f4;
        }


        .speed-icon {

            width: 45px;
            height: 45px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius:
                13px;

            background:
                #dbeafe;

            color:
                var(--primary);

            font-size:
                20px;
        }


        .speed span {

            display: block;

            color:
                var(--muted);

            font-size:
                11px;
        }


        .speed strong {

            display: block;

            margin-top:
                2px;

            color:
                var(--primary);

            font-size:
                20px;
        }


        .price {

            margin:
                20px 0;
        }


        .price small {

            color:
                var(--muted);

            font-size:
                11px;
        }


        .price strong {

            display:
                inline-block;

            font-size:
                30px;

            font-weight:
                900;
        }


        .price span {

            color:
                var(--muted);

            font-size:
                12px;
        }


        .benefits {

            margin-top:
                25px;

            padding-top:
                20px;

            border-top:
                1px solid var(--border);
        }


        .benefit {

            display:
                flex;

            gap:
                9px;

            margin-bottom:
                11px;

            color:
                #374151;

            font-size:
                12px;
        }


        .benefit i {
            color:
                var(--success);
        }


        .form-card {

            padding:
                28px;
        }


        .form-card h2 {

            margin:
                0 0 5px;

            font-size:
                21px;
        }


        .form-card > p {

            margin:
                0 0 22px;

            color:
                var(--muted);

            font-size:
                12px;
        }


        .alert {

            display:
                flex;

            gap:
                10px;

            margin-bottom:
                18px;

            padding:
                13px 15px;

            border-radius:
                12px;

            background:
                #fef2f2;

            border:
                1px solid #fecaca;

            color:
                #991b1b;

            font-size:
                12px;
        }


        .form-group {

            margin-bottom:
                16px;
        }


        .form-group label {

            display:
                block;

            margin-bottom:
                7px;

            font-size:
                12px;

            font-weight:
                800;
        }


        .form-control {

            width:
                100%;

            min-height:
                46px;

            padding:
                10px 13px;

            border:
                1px solid var(--border);

            border-radius:
                11px;

            outline:
                none;

            color:
                var(--text);

            background:
                #fff;

            font-size:
                13px;
        }


        textarea.form-control {

            min-height:
                105px;

            resize:
                vertical;
        }


        .form-control:focus {

            border-color:
                #93c5fd;

            box-shadow:
                0 0 0 4px
                rgba(37,99,235,.08);
        }


        .form-grid {

            display:
                grid;

            grid-template-columns:
                1fr 1fr;

            gap:
                14px;
        }


        .actions {

            display:
                flex;

            gap:
                10px;

            margin-top:
                22px;
        }


        .btn {

            min-height:
                48px;

            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            gap:
                8px;

            padding:
                0 18px;

            border:
                0;

            border-radius:
                12px;

            text-decoration:
                none;

            font-size:
                12px;

            font-weight:
                800;

            cursor:
                pointer;
        }


        .btn-back {

            flex:
                0 0 auto;

            background:
                #f3f4f6;

            color:
                #374151;
        }


        .btn-submit {

            flex:
                1;

            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    var(--primary-dark)
                );

            color:
                #fff;

            box-shadow:
                0 8px 18px
                rgba(37,99,235,.20);
        }


        .btn-submit:hover {

            transform:
                translateY(-1px);
        }


        .required {

            color:
                var(--danger);
        }


        @media (max-width: 800px) {

            .layout {

                grid-template-columns:
                    1fr;
            }
        }


        @media (max-width: 520px) {

            .page {

                width:
                    calc(100% - 18px);

                padding-top:
                    18px;
            }


            .topbar {

                align-items:
                    flex-start;

                flex-direction:
                    column-reverse;
            }


            .package-summary,
            .form-card {

                padding:
                    21px;
            }


            .form-grid {

                grid-template-columns:
                    1fr;
            }


            .actions {

                flex-direction:
                    column;
            }


            .btn-back,
            .btn-submit {

                width:
                    100%;
            }
        }

    </style>

</head>


<body>


<div class="page">


    <div class="topbar">


        <a
            href="packages.php"
            class="back"
        >

            <i class="bi bi-arrow-left"></i>

            Kembali ke Pilih Paket

        </a>


        <div class="brand">


            <div class="brand-icon">

                <i class="bi bi-wifi"></i>

            </div>


            <div>

                <strong>
                    WiFi Management
                </strong>

                <span>
                    Pengajuan Pemasangan
                </span>

            </div>


        </div>


    </div>


    <div class="layout">


        <!-- PAKET -->

        <section class="card package-summary">


            <span class="label">

                <i class="bi bi-check-circle-fill"></i>

                PAKET DIPILIH

            </span>


            <h1>

                <?= e($packageName) ?>

            </h1>


            <p>

                <?= e(
                    $deskripsi !== ''
                        ? $deskripsi
                        : 'Paket internet untuk kebutuhan rumah.'
                ) ?>

            </p>


            <div class="speed">


                <div class="speed-icon">

                    <i class="bi bi-lightning-charge-fill"></i>

                </div>


                <div>

                    <span>
                        Kecepatan
                    </span>

                    <strong>

                        <?= number_format(
                            $speed,
                            0,
                            ',',
                            '.'
                        ) ?>

                        Mbps

                    </strong>

                </div>


            </div>


            <div class="price">

                <small>
                    Harga paket
                </small>


                <div>

                    <strong>
                        <?= rupiah($harga) ?>
                    </strong>

                    <span>
                        / bulan
                    </span>

                </div>

            </div>


            <div class="benefits">


                <div class="benefit">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        Internet cepat dan stabil
                    </span>

                </div>


                <div class="benefit">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        Akses customer portal
                    </span>

                </div>


                <div class="benefit">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        Customer service
                    </span>

                </div>


                <div class="benefit">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        Pembayaran setelah pengajuan
                    </span>

                </div>


            </div>


        </section>


        <!-- FORM -->

        <section class="card form-card">


            <h2>
                Data Pengajuan
            </h2>


            <p>
                Pastikan data pemasangan sudah benar
                sebelum melanjutkan ke pembayaran.
            </p>


            <?php if ($error !== ''): ?>

                <div class="alert">

                    <i class="bi bi-exclamation-circle-fill"></i>

                    <span>
                        <?= e($error) ?>
                    </span>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                action="pengajuan_pemasangan.php"
            >


                <input
                    type="hidden"
                    name="paket_id"
                    value="<?= $packageId ?>"
                >


                <input
                    type="hidden"
                    name="action"
                    value="confirm"
                >


                <div class="form-group">

                    <label>

                        Nama Lengkap

                        <span class="required">
                            *
                        </span>

                    </label>


                    <input
                        type="text"
                        name="nama"
                        class="form-control"
                        value="<?= e($nama) ?>"
                        required
                    >

                </div>


                <div class="form-grid">


                    <div class="form-group">

                        <label>

                            Nomor Telepon

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            name="telephone"
                            class="form-control"
                            value="<?= e($telephone) ?>"
                            placeholder="08xxxxxxxxxx"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Email
                        </label>


                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            value="<?= e($email === '-' ? '' : $email) ?>"
                            placeholder="email@example.com"
                        >

                    </div>


                </div>


                <div class="form-group">

                    <label>
                        NIK
                    </label>


                    <input
                        type="text"
                        name="nik"
                        class="form-control"
                        value="<?= e($nik) ?>"
                        placeholder="Masukkan NIK"
                    >

                </div>


                <div class="form-group">

                    <label>

                        Alamat Pemasangan

                        <span class="required">
                            *
                        </span>

                    </label>


                    <textarea
                        name="alamat"
                        class="form-control"
                        placeholder="Masukkan alamat lengkap pemasangan WiFi"
                        required
                    ><?= e($alamat === '-' ? '' : $alamat) ?></textarea>

                </div>


                <div class="actions">


                    <a
                        href="packages.php"
                        class="btn btn-back"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Kembali

                    </a>


                    <button
                        type="submit"
                        class="btn btn-submit"
                    >

                        Lanjut ke Pembayaran

                        <i class="bi bi-credit-card"></i>

                    </button>


                </div>


            </form>


        </section>


    </div>


</div>


</body>

</html>