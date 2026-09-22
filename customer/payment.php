<?php

session_start();

<<<<<<< HEAD
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";
=======
require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value)
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
|
| Untuk sementara kita hanya memastikan ada user_id.
|
*/

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
<<<<<<< HEAD
| DATA DUMMY CUSTOMER
|--------------------------------------------------------------------------
|
| DATA INI HANYA UNTUK TESTING DASHBOARD
|
*/

$dummyCustomer = [

    'id' => 1,

    'user_id' => $userId,

    'paket_id' => 1,

    'nama' => $_SESSION['nama']
        ?? $_SESSION['username']
        ?? 'Customer Dummy',

    'email' => $_SESSION['email']
        ?? 'customer@example.com',

    'telephone' => $_SESSION['telephone']
        ?? $_SESSION['no_hp']
        ?? '081234567890',

    'nik' => '3500000000000001',

    'alamat' => 'Jl. Contoh No. 123, Indonesia',

    /*
    |--------------------------------------------------------------------------
    | PENTING
    |--------------------------------------------------------------------------
    |
    | pending = dashboard customer bisa dibuka
    | active  = layanan dianggap aktif
    |
    */

    'status_langganan' => 'pending'

];
=======
| VARIABEL
|--------------------------------------------------------------------------
*/

$error = '';

$customer = null;
$package  = null;

$customerId = 0;
$packageId  = 0;


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER
|--------------------------------------------------------------------------
*/

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

$stmt->bind_param(
    "i",
    $userId
);

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

    die("
        <div style='
            font-family:Arial;
            padding:40px;
            text-align:center;
        '>

            <h2>Data customer tidak ditemukan</h2>

            <p>
                User ID:
                " . e($userId) . "
            </p>

        </div>
    ");
}


$customerId = (int) $customer['id'];
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde


/*
|--------------------------------------------------------------------------
| DATA DUMMY PAKET
|--------------------------------------------------------------------------
*/

$dummyPackage = [

    'id' => 1,

    'nama_paket' => 'WiFi Home 50 Mbps',

    'speed_mbps' => 50,

    'harga' => 250000,

    'deskripsi' =>
        'Internet rumah cepat dan stabil ' .
        'untuk kebutuhan keluarga.',

    'status' => 'aktif'

<<<<<<< HEAD
];
=======
    $packageId =
        (int) $customer['paket_id'];
}


/*
|--------------------------------------------------------------------------
| PAKET BELUM DIPILIH
|--------------------------------------------------------------------------
*/

if ($packageId <= 0) {

    header("Location: packages.php");
    exit;
}
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde


/*
|--------------------------------------------------------------------------
<<<<<<< HEAD
| AMBIL PAKET DARI URL
=======
| AMBIL DATA PAKET
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde
|--------------------------------------------------------------------------
|
| Contoh:
|
| payment.php?paket_id=1
|
*/

<<<<<<< HEAD
$packageId = (int)(
    $_GET['paket_id']
    ?? $_POST['paket_id']
    ?? 1
=======
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

$stmt->bind_param(
    "i",
    $packageId
);

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

    die("
        <div style='
            font-family:Arial;
            padding:40px;
            text-align:center;
        '>

            <h2>Paket WiFi tidak ditemukan</h2>

            <p>
                ID Paket:
                " . e($packageId) . "
            </p>

        </div>
    ");
}


/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

$packageName = trim(
    (string) (
        $package['nama_paket']
        ?? 'Paket WiFi'
    )
);

$speed = (int) (
    $package['speed_mbps']
    ?? 0
);

$price = (float) (
    $package['harga']
    ?? 0
);

$description = trim(
    (string) (
        $package['deskripsi']
        ?? ''
    )
);


/*
|--------------------------------------------------------------------------
| CUSTOMER DISPLAY
|--------------------------------------------------------------------------
*/

$customerName = trim(
    (string) (
        $customer['nama']
        ??
        $_SESSION['nama']
        ??
        $_SESSION['username']
        ??
        'Customer'
    )
);


if ($customerName === '') {

    $customerName = 'Customer';
}


$initial = strtoupper(
    substr(
        $customerName,
        0,
        1
    )
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde
);


/*
|--------------------------------------------------------------------------
<<<<<<< HEAD
| UNTUK TESTING
|--------------------------------------------------------------------------
|
| Apapun paket_id yang dikirim, sementara kita
| tetap menggunakan paket dummy.
=======
| PROSES PEMBAYARAN DUMMY
|--------------------------------------------------------------------------
|
| TIDAK ADA MIDTRANS
|
| Untuk testing:
|
| 1. Paket dianggap sudah dibayar.
| 2. Customer menjadi ACTIVE.
| 3. Paket disimpan ke customers.
| 4. Session diperbarui.
| 5. Redirect dashboard.
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde
|
*/

$dummyPackage['id'] = $packageId;

<<<<<<< HEAD

/*
|--------------------------------------------------------------------------
| SIMPAN DATA DUMMY KE SESSION
|--------------------------------------------------------------------------
|
| Dashboard dapat menggunakan session ini jika
| membutuhkan data customer.
|
*/

$_SESSION['dummy_customer'] = $dummyCustomer;

$_SESSION['dummy_package'] = $dummyPackage;

$_SESSION['pengajuan_paket_id'] =
    $dummyPackage['id'];

$_SESSION['payment_package_id'] =
    $dummyPackage['id'];

$_SESSION['payment_customer_id'] =
    $dummyCustomer['id'];


/*
|--------------------------------------------------------------------------
| SIMPAN KE DATABASE
|--------------------------------------------------------------------------
|
| Bagian ini mencoba menyimpan paket dan status
| ke customer yang sedang login.
|
| Kalau database/schema belum cocok, dashboard
| tetap bisa dicoba menggunakan session dummy.
|
*/

try {

    /*
    |--------------------------------------------------------------------------
    | CEK APAKAH CUSTOMER ADA
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $realCustomer = $result->fetch_assoc();

    $stmt->close();
=======
    $postedPackageId = (int) (
        $_POST['paket_id']
        ?? 0
    );
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde


    /*
    |--------------------------------------------------------------------------
<<<<<<< HEAD
    | JIKA CUSTOMER ADA
    |--------------------------------------------------------------------------
    */

    if ($realCustomer) {

        $realCustomerId =
            (int)$realCustomer['id'];


        /*
        |--------------------------------------------------------------------------
        | SIMPAN PAKET + STATUS
        |--------------------------------------------------------------------------
        */
=======
    | VALIDASI PAKET
    |--------------------------------------------------------------------------
    */

    if ($postedPackageId <= 0) {
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde

        $stmtUpdate = $conn->prepare("
            UPDATE customers
            SET
                paket_id = ?,
                status_langganan = 'pending'
            WHERE id = ?
            LIMIT 1
        ");

        $stmtUpdate->bind_param(
            "ii",
            $dummyPackage['id'],
            $realCustomerId
        );

        $stmtUpdate->execute();

<<<<<<< HEAD
        $stmtUpdate->close();


        /*
        |--------------------------------------------------------------------------
        | UPDATE SESSION CUSTOMER ID
        |--------------------------------------------------------------------------
        */

        $_SESSION['payment_customer_id'] =
            $realCustomerId;

    }


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | JANGAN HENTIKAN TESTING
    |--------------------------------------------------------------------------
    |
    | Kalau database belum cocok, kita tetap
    | lanjut ke dashboard menggunakan dummy session.
    |
    */

}


/*
|--------------------------------------------------------------------------
| STATUS DUMMY
|--------------------------------------------------------------------------
*/

$_SESSION['subscription_status'] = 'pending';


/*
|--------------------------------------------------------------------------
| TANDA TESTING
|--------------------------------------------------------------------------
*/

$_SESSION['dummy_mode'] = true;


/*
|--------------------------------------------------------------------------
| LANGSUNG KE DASHBOARD
|--------------------------------------------------------------------------
*/

header("Location: dashboard.php");

exit;

?>
=======
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | MULAI TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();


            /*
            |--------------------------------------------------------------------------
            | AKTIFKAN CUSTOMER
            |--------------------------------------------------------------------------
            |
            | DUMMY PAYMENT
            |
            */

            $updateCustomer = $conn->prepare("
                UPDATE customers
                SET
                    paket_id = ?,
                    status_langganan = 'active'
                WHERE id = ?
                LIMIT 1
            ");


            $updateCustomer->bind_param(
                "ii",
                $packageId,
                $customerId
            );


            if (!$updateCustomer->execute()) {

                throw new Exception(
                    "Gagal mengaktifkan customer: " .
                    $updateCustomer->error
                );
            }


            $updateCustomer->close();


            /*
            |--------------------------------------------------------------------------
            | UPDATE USER STATUS
            |--------------------------------------------------------------------------
            |
            | Kalau kolom users.status digunakan untuk status aktif,
            | kita pastikan tetap aktif.
            |
            */

            $updateUser = $conn->prepare("
                UPDATE users
                SET status = 1
                WHERE id = ?
                LIMIT 1
            ");


            $updateUser->bind_param(
                "i",
                $userId
            );


            $updateUser->execute();

            $updateUser->close();


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

            $_SESSION['user_id'] =
                $userId;

            $_SESSION['role'] =
                'customer';

            $_SESSION['user_status'] =
                1;

            $_SESSION['paket_id'] =
                $packageId;

            $_SESSION['status_langganan'] =
                'active';

            $_SESSION['dummy_payment'] =
                true;

            $_SESSION['dummy_order_id'] =
                'DUMMY-' .
                $customerId .
                '-' .
                date('YmdHis');


            /*
            |--------------------------------------------------------------------------
            | REDIRECT DASHBOARD
            |--------------------------------------------------------------------------
            */

            header(
                "Location: dashboard.php"
            );

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

                // Tidak perlu melakukan apa-apa.
            }


            $error =
                "Gagal memproses pembayaran dummy: " .
                $e->getMessage();
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
        Pembayaran Dummy | WiFi Management
    </title>


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <link
        rel="stylesheet"
        href="assets/css/payment.css?v=<?= time() ?>"
    >


    <style>

        /*
        |--------------------------------------------------------------------------
        | DUMMY PAYMENT NOTICE
        |--------------------------------------------------------------------------
        */

        .dummy-notice {

            display: flex;

            align-items: flex-start;

            gap: 14px;

            padding: 16px 18px;

            margin-bottom: 24px;

            border-radius: 14px;

            background: #fff7ed;

            border: 1px solid #fed7aa;

            color: #9a3412;

        }


        .dummy-notice-icon {

            width: 40px;

            height: 40px;

            display: flex;

            align-items: center;

            justify-content: center;

            flex-shrink: 0;

            border-radius: 10px;

            background: #ffedd5;

            font-size: 20px;

        }


        .dummy-notice strong {

            display: block;

            margin-bottom: 4px;

        }


        .dummy-notice span {

            display: block;

            font-size: 14px;

            line-height: 1.5;

        }


        .dummy-button {

            width: 100%;

            border: 0;

            cursor: pointer;

            padding: 15px 20px;

            border-radius: 12px;

            font-size: 15px;

            font-weight: 700;

            background: #0f4cdb;

            color: white;

        }


        .dummy-button:hover {

            opacity: .92;

        }

    </style>

</head>


<body>


<div class="payment-page">


    <!-- NAVBAR -->

    <header class="payment-navbar">

        <a
            href="packages.php"
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


        <div class="navbar-right">

            <a
                href="packages.php"
                class="back-link"
            >

                <i class="bi bi-arrow-left"></i>

                Ganti Paket

            </a>


            <div class="user-mini">

                <div class="avatar">

                    <?= e($initial) ?>

                </div>


                <div class="user-info">

                    <strong>
                        <?= e($customerName) ?>
                    </strong>

                    <span>
                        Customer
                    </span>

                </div>

            </div>

        </div>

    </header>


    <!-- MAIN -->

    <main class="payment-container">


        <div class="breadcrumb">

            <span>

                <i class="bi bi-house"></i>

                Langganan

            </span>


            <i class="bi bi-chevron-right"></i>


            <span>
                Pilih Paket
            </span>


            <i class="bi bi-chevron-right"></i>


            <strong>
                Pembayaran
            </strong>

        </div>


        <section class="page-heading">

            <div class="heading-badge">

                <i class="bi bi-tools"></i>

                MODE TESTING

            </div>


            <h1>
                Konfirmasi Paket
            </h1>


            <p>

                Halaman ini menggunakan pembayaran dummy
                untuk pengujian sistem.

            </p>

        </section>


        <!-- DUMMY NOTICE -->

        <div class="dummy-notice">

            <div class="dummy-notice-icon">

                <i class="bi bi-info-circle"></i>

            </div>


            <div>

                <strong>
                    Mode Pembayaran Dummy
                </strong>


                <span>

                    Tidak ada transaksi Midtrans yang dibuat.
                    Ketika tombol konfirmasi ditekan,
                    customer akan langsung dianggap aktif
                    dan diarahkan ke Dashboard.

                </span>

            </div>

        </div>


        <!-- PAYMENT GRID -->

        <div class="payment-grid">


            <!-- PACKAGE -->

            <section class="payment-card package-card">

                <div class="card-header">

                    <div>

                        <span class="card-label">
                            PAKET YANG DIPILIH
                        </span>


                        <h2>
                            <?= e($packageName) ?>
                        </h2>

                    </div>


                    <div class="package-icon">

                        <i class="bi bi-wifi"></i>

                    </div>

                </div>


                <div class="speed-box">

                    <div class="speed-icon">

                        <i class="bi bi-lightning-charge-fill"></i>

                    </div>


                    <div class="speed-info">

                        <span>
                            KECEPATAN INTERNET
                        </span>


                        <strong>

                            <?= e($speed) ?>

                            Mbps

                        </strong>

                    </div>


                    <i
                        class="bi bi-check-circle-fill verified"
                    ></i>

                </div>


                <?php if ($description !== ''): ?>

                    <div class="package-description">

                        <i class="bi bi-info-circle-fill"></i>

                        <span>

                            <?= e($description) ?>

                        </span>

                    </div>

                <?php endif; ?>


                <div class="included-title">

                    <span>
                        Fasilitas Paket
                    </span>

                    <small>
                        Termasuk dalam layanan
                    </small>

                </div>


                <div class="feature-list">

                    <div class="feature-item">

                        <div class="feature-icon">

                            <i class="bi bi-check-lg"></i>

                        </div>

                        <span>
                            Internet berkecepatan tinggi
                        </span>

                    </div>


                    <div class="feature-item">

                        <div class="feature-icon">

                            <i class="bi bi-check-lg"></i>

                        </div>

                        <span>
                            Customer support
                        </span>

                    </div>


                    <div class="feature-item">

                        <div class="feature-icon">

                            <i class="bi bi-check-lg"></i>

                        </div>

                        <span>
                            Monitoring layanan
                        </span>

                    </div>


                    <div class="feature-item">

                        <div class="feature-icon">

                            <i class="bi bi-check-lg"></i>

                        </div>

                        <span>
                            Masa aktif 30 hari
                        </span>

                    </div>

                </div>


                <div class="package-price">

                    <span>
                        Harga Paket
                    </span>


                    <strong>
                        <?= rupiah($price) ?>
                    </strong>


                    <small>
                        / 30 hari
                    </small>

                </div>

            </section>


            <!-- CHECKOUT -->

            <section class="payment-card checkout-card">


                <div class="checkout-title">

                    <div>

                        <span class="card-label">
                            RINGKASAN
                        </span>


                        <h2>
                            Konfirmasi
                        </h2>

                    </div>


                    <div class="secure-icon">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                </div>


                <div class="price-row">

                    <span>
                        Paket
                    </span>


                    <strong>
                        <?= e($packageName) ?>
                    </strong>

                </div>


                <div class="price-row">

                    <span>
                        Kecepatan
                    </span>


                    <strong>

                        <?= e($speed) ?>

                        Mbps

                    </strong>

                </div>


                <div class="price-row">

                    <span>
                        Masa aktif
                    </span>


                    <span>
                        30 Hari
                    </span>

                </div>


                <div class="divider"></div>


                <div class="total-row">

                    <div>

                        <span>
                            Total Paket
                        </span>

                        <small>
                            Mode testing
                        </small>

                    </div>


                    <strong>
                        <?= rupiah($price) ?>
                    </strong>

                </div>


                <!-- FORM -->

                <form
                    method="POST"
                    action="payment.php?paket_id=<?= (int) $packageId ?>"
                >

                    <input
                        type="hidden"
                        name="paket_id"
                        value="<?= (int) $packageId ?>"
                    >


                    <div class="secure-note">

                        <div class="secure-note-icon">

                            <i class="bi bi-database-check"></i>

                        </div>


                        <span>

                            Setelah konfirmasi, data customer
                            akan diubah menjadi
                            <strong>active</strong>
                            dan paket akan disimpan ke database.

                        </span>

                    </div>


                    <?php if ($error !== ''): ?>

                        <div class="alert-error">

                            <div class="alert-icon">

                                <i class="bi bi-exclamation-triangle-fill"></i>

                            </div>


                            <div class="alert-content">

                                <strong>
                                    Gagal
                                </strong>

                                <span>
                                    <?= e($error) ?>
                                </span>

                            </div>

                        </div>

                    <?php endif; ?>


                    <button
                        type="submit"
                        name="confirm_payment"
                        value="1"
                        class="dummy-button"
                    >

                        <i class="bi bi-check-circle me-2"></i>

                        Konfirmasi & Masuk Dashboard

                    </button>

                </form>


                <a
                    href="packages.php"
                    class="change-package"
                >

                    <i class="bi bi-arrow-left"></i>

                    Ganti Paket

                </a>

            </section>

        </div>

    </main>


    <!-- FOOTER -->

    <footer class="payment-footer">

        <div class="footer-brand">

            <div class="footer-icon">

                <i class="bi bi-wifi"></i>

            </div>


            <strong>
                WiFi Management
            </strong>

        </div>


        <span>

            © <?= date('Y') ?>

            Customer Portal

        </span>

    </footer>


</div>


</body>

</html>
>>>>>>> 1c6c971974fd7d6bd5c1d19cfba47ce1c95b7cde
