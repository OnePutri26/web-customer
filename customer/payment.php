<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

date_default_timezone_set('Asia/Jakarta');

requireRole('customer');


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
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$customer = null;

$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        paket_id,
        nama,
        telephone,
        email,
        nik,
        alamat,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
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

    die('
        <div style="
            font-family: Arial, sans-serif;
            padding: 40px;
            text-align: center;
        ">
            <h2>Data customer tidak ditemukan</h2>
            <p>User ID: ' . e($userId) . '</p>
        </div>
    ');
}


$customerId = (int) $customer['id'];


/*
|--------------------------------------------------------------------------
| AMBIL PAKET ID
|--------------------------------------------------------------------------
|
| Prioritas:
|
| POST paket_id
| GET paket_id
| paket_id customer
|
|--------------------------------------------------------------------------
*/

$packageId = (int) (
    $_POST['paket_id']
    ?? $_GET['paket_id']
    ?? $customer['paket_id']
    ?? 0
);


/*
|--------------------------------------------------------------------------
| PAKET BELUM DIPILIH
|--------------------------------------------------------------------------
*/

if ($packageId <= 0) {
    header('Location: packages.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA PAKET
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
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

$stmt->bind_param('i', $packageId);
$stmt->execute();

$result = $stmt->get_result();

$package = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| PAKET TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$package) {

    die('
        <div style="
            font-family: Arial, sans-serif;
            padding: 40px;
            text-align: center;
        ">
            <h2>Paket WiFi tidak ditemukan</h2>
            <p>ID Paket: ' . e($packageId) . '</p>
            <a href="packages.php">
                Kembali ke daftar paket
            </a>
        </div>
    ');
}


/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

$packageName = trim(
    (string) ($package['nama_paket'] ?? 'Paket WiFi')
);

$speed = (int) (
    $package['speed_mbps'] ?? 0
);

$price = (float) (
    $package['harga'] ?? 0
);

$description = trim(
    (string) ($package['deskripsi'] ?? '')
);

$packageStatus = strtolower(
    trim(
        (string) ($package['status'] ?? '')
    )
);


/*
|--------------------------------------------------------------------------
| CEK STATUS PAKET
|--------------------------------------------------------------------------
*/

if (
    $packageStatus !== ''
    &&
    !in_array(
        $packageStatus,
        ['aktif', 'active', '1'],
        true
    )
) {

    die('
        <div style="
            font-family: Arial, sans-serif;
            padding: 40px;
            text-align: center;
        ">
            <h2>Paket tidak tersedia</h2>

            <p>
                Paket yang kamu pilih sedang tidak aktif.
            </p>

            <a href="packages.php">
                Kembali ke paket
            </a>
        </div>
    ');
}


/*
|--------------------------------------------------------------------------
| PROSES PEMBAYARAN DUMMY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | MULAI TRANSACTION
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();


        /*
        |--------------------------------------------------------------------------
        | UPDATE CUSTOMER
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE customers
            SET
                paket_id = ?,
                status_langganan = 'active'
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'ii',
            $packageId,
            $customerId
        );

        $stmt->execute();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | UPDATE STATUS USER
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE users
            SET status = 1
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $userId
        );

        $stmt->execute();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        $conn->commit();


        /*
        |--------------------------------------------------------------------------
        | UPDATE SESSION
        |--------------------------------------------------------------------------
        */

        $_SESSION['user_id'] = $userId;

        $_SESSION['role'] = 'customer';

        $_SESSION['user_status'] = 1;

        $_SESSION['paket_id'] = $packageId;

        $_SESSION['status_langganan'] = 'active';

        $_SESSION['payment_customer_id'] = $customerId;

        $_SESSION['payment_package_id'] = $packageId;

        $_SESSION['dummy_payment'] = true;

        $_SESSION['dummy_mode'] = true;

        $_SESSION['dummy_order_id'] =
            'DUMMY-' .
            $customerId .
            '-' .
            date('YmdHis');


        /*
        |--------------------------------------------------------------------------
        | REDIRECT
        |--------------------------------------------------------------------------
        */

        header('Location: dashboard.php');
        exit;

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | ROLLBACK
        |--------------------------------------------------------------------------
        */

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Abaikan error rollback.
        }

        die('
            <div style="
                font-family: Arial, sans-serif;
                padding: 40px;
            ">
                <h2>Gagal memproses pembayaran</h2>

                <p>
                    ' . e($e->getMessage()) . '
                </p>

                <a href="packages.php">
                    Kembali
                </a>
            </div>
        ');
    }
}


/*
|--------------------------------------------------------------------------
| SESSION PAYMENT
|--------------------------------------------------------------------------
*/

$_SESSION['payment_package_id'] = $packageId;

$_SESSION['payment_customer_id'] = $customerId;


/*
|--------------------------------------------------------------------------
| HALAMAN KONFIRMASI PEMBAYARAN
|--------------------------------------------------------------------------
*/

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
        Pembayaran - <?= e($packageName) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #222;
        }

        .container {
            width: 100%;
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .title {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }

        .package {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .package-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .speed {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .price {
            font-size: 26px;
            font-weight: bold;
            margin-top: 15px;
        }

        .description {
            color: #666;
            margin-top: 15px;
            line-height: 1.6;
        }

        .customer {
            margin-bottom: 25px;
        }

        .customer p {
            margin: 7px 0;
        }

        .btn {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 14px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            background: #0d6efd;
            color: #fff;
        }

        .btn:hover {
            background: #0b5ed7;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #555;
            text-decoration: none;
        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1 class="title">
            Konfirmasi Pembayaran
        </h1>

        <p class="subtitle">
            Silakan periksa kembali paket yang kamu pilih.
        </p>


        <div class="customer">

            <strong>Data Customer</strong>

            <p>
                Nama:
                <?= e($customer['nama']) ?>
            </p>

            <p>
                Email:
                <?= e($customer['email']) ?>
            </p>

            <p>
                Telephone:
                <?= e($customer['telephone']) ?>
            </p>

        </div>


        <div class="package">

            <div class="package-name">
                <?= e($packageName) ?>
            </div>

            <div class="speed">
                Kecepatan:
                <strong>
                    <?= e($speed) ?> Mbps
                </strong>
            </div>

            <div class="price">
                <?= rupiah($price) ?>
                <small>/bulan</small>
            </div>

            <?php if ($description !== ''): ?>

                <div class="description">
                    <?= nl2br(e($description)) ?>
                </div>

            <?php endif; ?>

        </div>


        <form
            method="POST"
            action="payment.php"
        >

            <input
                type="hidden"
                name="paket_id"
                value="<?= e($packageId) ?>"
            >

            <button
                type="submit"
                class="btn"
            >
                Bayar Sekarang
            </button>

        </form>


        <a
            href="packages.php"
            class="back"
        >
            Kembali ke pilihan paket
        </a>

    </div>

</div>

</body>

</html>