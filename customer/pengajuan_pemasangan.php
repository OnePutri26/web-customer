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


function rupiah($value): string
{
    return 'Rp ' . number_format(
        (float) $value,
        0,
        ',',
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| HANYA BOLEH POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL PAKET ID
|--------------------------------------------------------------------------
*/

$paketId = (int) ($_POST['paket_id'] ?? 0);

if ($paketId <= 0) {

    $_SESSION['error_paket'] =
        "Paket WiFi belum dipilih.";

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$sqlCustomer = "
    SELECT
        id,
        user_id,
        nama,
        telephone,
        email,
        nik,
        alamat,
        paket_id,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
";

$stmtCustomer = $conn->prepare($sqlCustomer);

if (!$stmtCustomer) {

    die(
        "Query customer gagal: " .
        e($conn->error)
    );
}

$stmtCustomer->bind_param(
    "i",
    $userId
);

if (!$stmtCustomer->execute()) {

    die(
        "Gagal mengambil data customer: " .
        e($stmtCustomer->error)
    );
}

$resultCustomer =
    $stmtCustomer->get_result();

$customer =
    $resultCustomer->fetch_assoc();

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

            <meta
                name='viewport'
                content='width=device-width, initial-scale=1.0'
            >

            <title>
                Customer Tidak Ditemukan
            </title>

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

                    font-family:
                        Arial,
                        sans-serif;

                    background: #f5f7fb;

                    color: #111827;
                }

                .box {
                    width: 100%;
                    max-width: 520px;

                    padding: 40px;

                    text-align: center;

                    background: #ffffff;

                    border-radius: 24px;

                    box-shadow:
                        0 20px 50px
                        rgba(0,0,0,.08);
                }

                .icon {
                    width: 70px;
                    height: 70px;

                    margin:
                        0 auto 20px;

                    display: flex;
                    align-items: center;
                    justify-content: center;

                    border-radius: 20px;

                    background: #fee2e2;

                    color: #dc2626;

                    font-size: 28px;

                    font-weight: 800;
                }

                h2 {
                    margin: 0 0 10px;
                }

                p {
                    margin: 0;

                    color: #6b7280;

                    line-height: 1.7;
                }

                a {
                    display: inline-flex;

                    align-items: center;
                    justify-content: center;

                    margin-top: 25px;

                    padding: 12px 20px;

                    border-radius: 12px;

                    background: #2563eb;

                    color: #ffffff;

                    text-decoration: none;

                    font-weight: 700;
                }

            </style>

        </head>

        <body>

            <div class='box'>

                <div class='icon'>
                    !
                </div>

                <h2>
                    Data Customer Tidak Ditemukan
                </h2>

                <p>
                    Akun login ditemukan,
                    tetapi data customer belum tersedia.
                </p>

                <a href='../login.php'>
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

$customerId =
    (int) ($customer['id'] ?? 0);


$statusLangganan =
    strtolower(
        trim(
            (string) (
                $customer['status_langganan']
                ?? ''
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
|
| PENTING:
| Gunakan payment.php, bukan pembayaran.php
|
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $statusLangganan,
        [
            'menunggu_pembayaran',
            'pending_pembayaran',
            'pending',
            'unpaid'
        ],
        true
    )
) {

    header("Location: payment.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL PAKET WIFI
|--------------------------------------------------------------------------
*/

$sqlPackage = "

    SELECT
        id,
        nama_paket,
        speed_mbps,
        harga,
        deskripsi,
        status

    FROM paket_wifi

    WHERE
        id = ?

        AND
        (
            status IS NULL
            OR TRIM(status) = ''
            OR LOWER(TRIM(status)) IN (
                'aktif',
                'active',
                'tersedia',
                'available',
                '1'
            )
        )

    LIMIT 1
";


$stmtPackage =
    $conn->prepare($sqlPackage);


if (!$stmtPackage) {

    die(
        "Query paket gagal: " .
        e($conn->error)
    );
}


$stmtPackage->bind_param(
    "i",
    $paketId
);


if (!$stmtPackage->execute()) {

    die(
        "Gagal mengambil paket: " .
        e($stmtPackage->error)
    );
}


$resultPackage =
    $stmtPackage->get_result();


$package =
    $resultPackage->fetch_assoc();


$stmtPackage->close();


/*
|--------------------------------------------------------------------------
| PAKET TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$package) {

    $_SESSION['error_paket'] =
        "Paket WiFi yang dipilih tidak tersedia atau sudah tidak aktif.";

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

$packageId =
    (int) ($package['id'] ?? 0);


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


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER UNTUK FORM
|--------------------------------------------------------------------------
*/

$nama =
    trim(
        (string) (
            $customer['nama']
            ?? ''
        )
    );


if ($nama === '') {

    $nama =
        trim(
            (string) (
                $_SESSION['nama']
                ?? 'Customer'
            )
        );
}


$telephone =
    trim(
        (string) (
            $customer['telephone']
            ?? ''
        )
    );


$email =
    trim(
        (string) (
            $customer['email']
            ?? ''
        )
    );


$nik =
    trim(
        (string) (
            $customer['nik']
            ?? ''
        )
    );


$alamat =
    trim(
        (string) (
            $customer['alamat']
            ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| PROSES KONFIRMASI
|--------------------------------------------------------------------------
*/

$error = '';


$action =
    $_POST['action']
    ?? '';


if ($action === 'confirm') {


    /*
    |--------------------------------------------------------------------------
    | INPUT
    |--------------------------------------------------------------------------
    */

    $namaForm =
        trim(
            (string) (
                $_POST['nama']
                ?? ''
            )
        );


    $telephoneForm =
        trim(
            (string) (
                $_POST['telephone']
                ?? ''
            )
        );


    $emailForm =
        trim(
            (string) (
                $_POST['email']
                ?? ''
            )
        );


    $nikForm =
        trim(
            (string) (
                $_POST['nik']
                ?? ''
            )
        );


    $alamatForm =
        trim(
            (string) (
                $_POST['alamat']
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if ($namaForm === '') {

        $error =
            "Nama wajib diisi.";

    } elseif ($telephoneForm === '') {

        $error =
            "Nomor telepon wajib diisi.";

    } elseif ($alamatForm === '') {

        $error =
            "Alamat pemasangan wajib diisi.";
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE CUSTOMER
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $sqlUpdate = "

            UPDATE customers

            SET
                nama = ?,
                telephone = ?,
                email = ?,
                nik = ?,
                alamat = ?,
                paket_id = ?,
                status_langganan = 'menunggu_pembayaran'

            WHERE
                id = ?

                AND user_id = ?

            LIMIT 1
        ";


        $stmtUpdate =
            $conn->prepare($sqlUpdate);


        if (!$stmtUpdate) {

            $error =
                "Query update customer gagal: " .
                $conn->error;

        } else {


            $stmtUpdate->bind_param(
                "sssssiii",
                $namaForm,
                $telephoneForm,
                $emailForm,
                $nikForm,
                $alamatForm,
                $packageId,
                $customerId,
                $userId
            );


            if (!$stmtUpdate->execute()) {

                $error =
                    "Gagal menyimpan pengajuan: " .
                    $stmtUpdate->error;

            }


            $stmtUpdate->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BERHASIL
    |--------------------------------------------------------------------------
    */

    if ($error === '') {


        /*
        |--------------------------------------------------------------------------
        | UPDATE SESSION
        |--------------------------------------------------------------------------
        */

        $_SESSION['nama'] =
            $namaForm;


        $_SESSION['pengajuan_paket_id'] =
            $packageId;


        $_SESSION['pengajuan_paket_nama'] =
            $packageName;


        /*
        |--------------------------------------------------------------------------
        | SIMPAN HARGA PAKET KE SESSION
        |--------------------------------------------------------------------------
        |
        | Payment.php bisa mengambil data paket
        | berdasarkan ID ini.
        |
        |--------------------------------------------------------------------------
        */

        $_SESSION['pengajuan_paket_harga'] =
            $harga;


        /*
        |--------------------------------------------------------------------------
        | LANGSUNG KE PAYMENT.PHP
        |--------------------------------------------------------------------------
        */

        header("Location: payment.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TAMPILKAN INPUT TERAKHIR JIKA ERROR
    |--------------------------------------------------------------------------
    */

    $nama =
        $namaForm;

    $telephone =
        $telephoneForm;

    $email =
        $emailForm;

    $nik =
        $nikForm;

    $alamat =
        $alamatForm;
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