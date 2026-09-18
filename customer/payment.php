<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";
require_once "../config/midtrans.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);


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
| LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) (
    $_SESSION['user_id'] ?? 0
);


if ($userId <= 0) {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| VARIABEL
|--------------------------------------------------------------------------
*/

$error = '';

$customer = null;
$package = null;

$customerId = 0;
$packageId = 0;

$snapToken = null;
$orderId = null;


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


if (!$customer) {

    die(
        "<div style='font-family:Arial;padding:30px'>" .
        "<h2>Data customer tidak ditemukan</h2>" .
        "</div>"
    );
}


$customerId = (int) $customer['id'];


if ($customerId <= 0) {

    die(
        "<div style='font-family:Arial;padding:30px'>" .
        "<h2>ID customer tidak valid</h2>" .
        "</div>"
    );
}


/*
|--------------------------------------------------------------------------
| CEK STATUS CUSTOMER
|--------------------------------------------------------------------------
|
| Jangan biarkan customer yang sudah aktif membuat transaksi instalasi
| baru dari halaman ini.
|
*/

$statusLangganan = strtolower(
    trim(
        (string) (
            $customer['status_langganan'] ?? ''
        )
    )
);


if (
    $statusLangganan === 'active' ||
    $statusLangganan === 'aktif' ||
    $statusLangganan === '3'
) {

    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| TENTUKAN PAKET
|--------------------------------------------------------------------------
*/

if (
    isset($_POST['paket_id']) &&
    (int) $_POST['paket_id'] > 0
) {

    $packageId = (int) $_POST['paket_id'];

} elseif (
    isset($_GET['paket_id']) &&
    (int) $_GET['paket_id'] > 0
) {

    $packageId = (int) $_GET['paket_id'];

} elseif (
    isset($_SESSION['pengajuan_paket_id']) &&
    (int) $_SESSION['pengajuan_paket_id'] > 0
) {

    $packageId =
        (int) $_SESSION['pengajuan_paket_id'];

} elseif (
    isset($customer['paket_id']) &&
    (int) $customer['paket_id'] > 0
) {

    $packageId =
        (int) $customer['paket_id'];
}


if ($packageId <= 0) {

    header("Location: packages.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL PAKET
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

$stmt->bind_param(
    "i",
    $packageId
);

$stmt->execute();

$result = $stmt->get_result();

$package = $result->fetch_assoc();

$stmt->close();


if (!$package) {

    die(
        "<div style='font-family:Arial;padding:30px'>" .
        "<h2>Paket WiFi tidak ditemukan</h2>" .
        "</div>"
    );
}


/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

$packageName = trim(
    (string) (
        $package['nama_paket'] ?? 'Paket WiFi'
    )
);

$speed = (int) (
    $package['speed_mbps'] ?? 0
);

$price = (float) (
    $package['harga'] ?? 0
);

$description = trim(
    (string) (
        $package['deskripsi'] ?? ''
    )
);

$packageStatus = strtolower(
    trim(
        (string) (
            $package['status'] ?? ''
        )
    )
);


if (
    $packageStatus !== 'active' &&
    $packageStatus !== 'aktif'
) {

    die(
        "<div style='font-family:Arial;padding:30px'>" .
        "<h2>Paket tidak tersedia</h2>" .
        "</div>"
    );
}


if ($price <= 0) {

    die(
        "<div style='font-family:Arial;padding:30px'>" .
        "<h2>Harga paket tidak valid</h2>" .
        "</div>"
    );
}


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
);


/*
|--------------------------------------------------------------------------
| PROSES PEMBAYARAN
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_payment'])
) {

    $method = trim(
        (string) (
            $_POST['method'] ?? ''
        )
    );

    $postedPackageId = (int) (
        $_POST['paket_id'] ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    $allowedMethods = [
        'transfer_bank',
        'e_wallet',
        'virtual_account'
    ];


    if ($postedPackageId <= 0) {

        $error =
            "Paket belum dipilih.";

    } elseif (
        $postedPackageId !== $packageId
    ) {

        $error =
            "Paket pembayaran tidak valid.";

    } elseif (
        $method === ''
    ) {

        $error =
            "Silakan pilih metode pembayaran.";

    } elseif (
        !in_array(
            $method,
            $allowedMethods,
            true
        )
    ) {

        $error =
            "Metode pembayaran tidak valid.";
    }


    /*
    |--------------------------------------------------------------------------
    | BUAT TRANSAKSI MIDTRANS
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            /*
            |--------------------------------------------------------------------------
            | ORDER ID UNIK
            |--------------------------------------------------------------------------
            */

            $orderId =
                'WIFI-' .
                $customerId .
                '-' .
                date('YmdHis') .
                '-' .
                random_int(1000, 9999);


            /*
            |--------------------------------------------------------------------------
            | DATA CUSTOMER
            |--------------------------------------------------------------------------
            */

            $customerEmail = trim(
                (string) (
                    $customer['email'] ?? ''
                )
            );

            $customerPhone = trim(
                (string) (
                    $customer['telephone'] ?? ''
                )
            );


            /*
            |--------------------------------------------------------------------------
            | MIDTRANS PARAMETER
            |--------------------------------------------------------------------------
            */

            $params = [

                'transaction_details' => [

                    'order_id' =>
                        $orderId,

                    'gross_amount' =>
                        (int) $price
                ],


                'customer_details' => [

                    'first_name' =>
                        $customerName,

                    'email' =>
                        $customerEmail,

                    'phone' =>
                        $customerPhone
                ],


                'item_details' => [

                    [

                        'id' =>
                            (string) $packageId,

                        'price' =>
                            (int) $price,

                        'quantity' =>
                            1,

                        'name' =>
                            $packageName
                    ]

                ]

            ];


            /*
            |--------------------------------------------------------------------------
            | REQUEST SNAP TOKEN
            |--------------------------------------------------------------------------
            */

            $snapToken =
                \Midtrans\Snap::getSnapToken(
                    $params
                );


            if (
                empty($snapToken)
            ) {

                throw new Exception(
                    "Snap Token tidak berhasil dibuat."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SIMPAN KE SESSION
            |--------------------------------------------------------------------------
            |
            | Session hanya digunakan untuk membantu halaman frontend.
            |
            | STATUS CUSTOMER TIDAK DIUBAH DI SINI.
            |
            */

            $_SESSION['midtrans_order_id'] =
                $orderId;

            $_SESSION['midtrans_snap_token'] =
                $snapToken;

            $_SESSION['payment_method'] =
                $method;

            $_SESSION['payment_package_id'] =
                $packageId;


        } catch (Throwable $e) {

            $error =
                "Gagal membuat transaksi pembayaran: " .
                $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| AMBIL TOKEN DARI SESSION
|--------------------------------------------------------------------------
*/

if (
    $snapToken === null &&
    !empty($_SESSION['midtrans_snap_token'])
) {

    $snapToken =
        $_SESSION['midtrans_snap_token'];
}


if (
    $orderId === null &&
    !empty($_SESSION['midtrans_order_id'])
) {

    $orderId =
        $_SESSION['midtrans_order_id'];
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
        Pembayaran | WiFi Management
    </title>


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <link
        rel="stylesheet"
        href="assets/css/payment.css?v=<?= time() ?>"
    >


    <!-- MIDTRANS SNAP -->

    <script
        src="https://app.sandbox.midtrans.com/snap/snap.js"
        data-client-key="<?= e(\Midtrans\Config::$clientKey) ?>"
    ></script>

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

                <i class="bi bi-shield-check"></i>

                PEMBAYARAN AMAN

            </div>


            <h1>
                Selesaikan Pembayaran
            </h1>


            <p>

                Periksa kembali paket pilihan kamu,
                lalu lanjutkan pembayaran melalui
                payment gateway.

            </p>

        </section>


        <!-- STEPPER -->

        <div class="stepper">

            <div class="step completed">

                <div class="step-circle">

                    <i class="bi bi-check-lg"></i>

                </div>

                <span>
                    Pilih Paket
                </span>

            </div>


            <div class="step-line completed-line"></div>


            <div class="step completed">

                <div class="step-circle">

                    <i class="bi bi-check-lg"></i>

                </div>

                <span>
                    Pengajuan
                </span>

            </div>


            <div class="step-line active-line"></div>


            <div class="step active">

                <div class="step-circle">
                    03
                </div>

                <span>
                    Pembayaran
                </span>

            </div>


            <div class="step-line"></div>


            <div class="step">

                <div class="step-circle">
                    04
                </div>

                <span>
                    Dashboard
                </span>

            </div>

        </div>


        <!-- ERROR -->

        <?php if ($error !== ''): ?>

            <div class="alert-error">

                <div class="alert-icon">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                </div>


                <div class="alert-content">

                    <strong>
                        Pembayaran tidak dapat diproses
                    </strong>

                    <span>
                        <?= e($error) ?>
                    </span>

                </div>

            </div>

        <?php endif; ?>


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
                            <?= e($speed) ?> Mbps
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
                            Pembayaran
                        </h2>

                    </div>


                    <div class="secure-icon">

                        <i class="bi bi-lock-fill"></i>

                    </div>

                </div>


                <div class="price-row">

                    <span>
                        <?= e($packageName) ?>
                    </span>


                    <strong>
                        <?= rupiah($price) ?>
                    </strong>

                </div>


                <div class="price-row muted">

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
                            Total Pembayaran
                        </span>

                        <small>
                            Paket internet
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
                    id="paymentForm"
                >

                    <input
                        type="hidden"
                        name="paket_id"
                        value="<?= (int) $packageId ?>"
                    >


                    <div class="method-title">

                        <div class="method-title-icon">

                            <i class="bi bi-wallet2"></i>

                        </div>


                        <div>

                            <strong>
                                Metode Pembayaran
                            </strong>

                            <small>
                                Pilih salah satu metode
                            </small>

                        </div>

                    </div>


                    <div class="method-list">


                        <label class="method-option">

                            <input
                                type="radio"
                                name="method"
                                value="transfer_bank"
                                checked
                            >


                            <span class="method-icon">

                                <i class="bi bi-bank"></i>

                            </span>


                            <span class="method-info">

                                <strong>
                                    Transfer Bank
                                </strong>

                                <small>
                                    BCA, BRI, BNI, Mandiri
                                </small>

                            </span>


                            <span class="radio-check">

                                <i class="bi bi-check"></i>

                            </span>

                        </label>


                        <label class="method-option">

                            <input
                                type="radio"
                                name="method"
                                value="e_wallet"
                            >


                            <span class="method-icon">

                                <i class="bi bi-wallet2"></i>

                            </span>


                            <span class="method-info">

                                <strong>
                                    E-Wallet
                                </strong>

                                <small>
                                    OVO, DANA, GoPay
                                </small>

                            </span>


                            <span class="radio-check">

                                <i class="bi bi-check"></i>

                            </span>

                        </label>


                        <label class="method-option">

                            <input
                                type="radio"
                                name="method"
                                value="virtual_account"
                            >


                            <span class="method-icon">

                                <i class="bi bi-credit-card"></i>

                            </span>


                            <span class="method-info">

                                <strong>
                                    Virtual Account
                                </strong>

                                <small>
                                    Pembayaran otomatis
                                </small>

                            </span>


                            <span class="radio-check">

                                <i class="bi bi-check"></i>

                            </span>

                        </label>

                    </div>


                    <div class="secure-note">

                        <div class="secure-note-icon">

                            <i class="bi bi-shield-check"></i>

                        </div>


                        <span>

                            Status layanan akan menjadi
                            <strong>aktif</strong>
                            setelah pembayaran berhasil
                            dikonfirmasi oleh server.

                        </span>

                    </div>


                    <button
                        type="submit"
                        name="confirm_payment"
                        value="1"
                        class="pay-button"
                        id="payButton"
                    >

                        <span class="button-normal">

                            <i class="bi bi-credit-card"></i>

                            Bayar Sekarang

                        </span>


                        <span class="button-loading">

                            <i class="bi bi-arrow-repeat"></i>

                            Membuka pembayaran...

                        </span>

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


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const form =
            document.getElementById(
                'paymentForm'
            );

        const button =
            document.getElementById(
                'payButton'
            );


        if (!form || !button) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | METODE PEMBAYARAN
        |--------------------------------------------------------------------------
        */

        const methodOptions =
            document.querySelectorAll(
                '.method-option'
            );


        methodOptions.forEach(
            function (option) {

                const radio =
                    option.querySelector(
                        'input[type="radio"]'
                    );


                if (!radio) {
                    return;
                }


                if (radio.checked) {

                    option.classList.add(
                        'selected'
                    );
                }


                radio.addEventListener(
                    'change',
                    function () {

                        methodOptions.forEach(
                            function (item) {

                                item.classList.remove(
                                    'selected'
                                );

                            }
                        );


                        if (radio.checked) {

                            option.classList.add(
                                'selected'
                            );

                        }

                    }
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | SNAP TOKEN
        |--------------------------------------------------------------------------
        */

        const snapToken =
            <?= json_encode(
                $snapToken,
                JSON_UNESCAPED_SLASHES
            ) ?>;


        const currentOrderId =
            <?= json_encode(
                $orderId,
                JSON_UNESCAPED_SLASHES
            ) ?>;


        /*
        |--------------------------------------------------------------------------
        | FORM SUBMIT
        |--------------------------------------------------------------------------
        */

        form.addEventListener(
            'submit',
            function (event) {

                /*
                |--------------------------------------------------------------------------
                | TOKEN SUDAH ADA
                |--------------------------------------------------------------------------
                |
                | Jangan submit POST kedua kali.
                | Langsung buka Midtrans Snap.
                |
                */

                if (snapToken) {

                    event.preventDefault();

                    button.disabled = true;

                    button.classList.add(
                        'loading'
                    );


                    snap.pay(
                        snapToken,
                        {

                            onSuccess:
                                function (result) {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | JANGAN AKTIFKAN CUSTOMER DI SINI
                                    |--------------------------------------------------------------------------
                                    |
                                    | Status pembayaran tetap diverifikasi
                                    | oleh webhook Midtrans.
                                    |
                                    */

                                    window.location.href =
                                        'payment_success.php?order_id=' +
                                        encodeURIComponent(
                                            result.order_id
                                        );
                                },


                            onPending:
                                function (result) {

                                    window.location.href =
                                        'payment_pending.php?order_id=' +
                                        encodeURIComponent(
                                            result.order_id
                                        );
                                },


                            onError:
                                function () {

                                    button.disabled =
                                        false;

                                    button.classList.remove(
                                        'loading'
                                    );

                                    alert(
                                        'Pembayaran gagal diproses.'
                                    );
                                },


                            onClose:
                                function () {

                                    button.disabled =
                                        false;

                                    button.classList.remove(
                                        'loading'
                                    );

                                }

                        }
                    );

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | TOKEN BELUM ADA
                |--------------------------------------------------------------------------
                |
                | Biarkan form POST ke PHP.
                | PHP akan membuat transaksi Midtrans.
                |
                */

                button.disabled = true;

                button.classList.add(
                    'loading'
                );

            }
        );

    }
);

</script>


</body>

</html>