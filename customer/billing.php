<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}

$customerId = $customer['id'];

$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);

$paketNama =
    $customer['paket'] ??
    $customer['nama_paket'] ??
    'Internet Home';

$paketSpeed =
    $customer['speed'] ??
    $customer['kecepatan'] ??
    '100 Mbps';

$tagihan = null;

try {

    $stmt = $conn->prepare("
        SELECT *
        FROM tagihan
        WHERE id_customer = ?
        AND status IN ('unpaid', 'pending')
        ORDER BY jatuh_tempo ASC
        LIMIT 1
    ");

    $stmt->bind_param("i", $customerId);
    $stmt->execute();

    $tagihan = $stmt
        ->get_result()
        ->fetch_assoc();

} catch (Exception $e) {

    $tagihan = null;

}

$payments = [];

try {

    $stmt = $conn->prepare("
        SELECT *
        FROM pembayaran
        WHERE id_customer = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");

    $stmt->bind_param("i", $customerId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $payments[] = $row;

    }

} catch (Exception $e) {

    $payments = [];

}

$jumlahTagihan =
    $tagihan['jumlah'] ??
    $tagihan['amount'] ??
    0;

$tanggalJatuhTempo =
    $tagihan['jatuh_tempo'] ??
    '-';

$invoice =
    $tagihan['invoice'] ??
    $tagihan['invoice_number'] ??
    '-';

$statusTagihan =
    strtolower(
        $tagihan['status'] ?? 'unpaid'
    );

function rupiah($angka)
{
    return 'Rp ' . number_format(
        (float)$angka,
        0,
        ',',
        '.'
    );
}

$statusLabel = match ($statusTagihan) {

    'paid' => 'Lunas',

    'pending' => 'Menunggu Pembayaran',

    'unpaid' => 'Belum Dibayar',

    default => ucfirst($statusTagihan)

};

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
        Tagihan - Customer Portal
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


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="assets/css/customer-dashboard.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/billing.css"
    >


    <style>

        .billing-page-card {
            background: #fff;
            border-radius: 18px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,.06);
            border: 1px solid #eee;
            height: 100%;
        }


        .billing-main-card {
            background: linear-gradient(
                135deg,
                #0d6efd,
                #084298
            );

            color: white;

            border-radius: 20px;

            padding: 30px;

            position: relative;

            overflow: hidden;

            box-shadow: 0 15px 35px rgba(13,110,253,.25);
        }


        .billing-main-card::after {

            content: "";

            position: absolute;

            width: 180px;

            height: 180px;

            border-radius: 50%;

            background: rgba(255,255,255,.08);

            right: -50px;

            top: -50px;

        }


        .billing-label {

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 1px;

            opacity: .8;

        }


        .billing-amount {

            font-size: 38px;

            font-weight: 800;

            margin: 12px 0;

        }


        .billing-info {

            display: flex;

            justify-content: space-between;

            gap: 20px;

            margin-top: 25px;

            padding-top: 20px;

            border-top: 1px solid rgba(255,255,255,.2);

        }


        .billing-info-item span {

            display: block;

            font-size: 12px;

            opacity: .7;

            margin-bottom: 5px;

        }


        .billing-info-item strong {

            font-size: 14px;

        }


        .btn-payment {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            background: white;

            color: #0d6efd;

            border-radius: 10px;

            padding: 12px 20px;

            text-decoration: none;

            font-weight: 700;

            margin-top: 22px;

            transition: .2s;

        }


        .btn-payment:hover {

            transform: translateY(-2px);

            color: #084298;

        }


        .billing-detail-row {

            display: flex;

            justify-content: space-between;

            padding: 13px 0;

            border-bottom: 1px solid #eee;

        }


        .billing-detail-row:last-child {

            border-bottom: 0;

        }


        .billing-detail-row span {

            color: #777;

            font-size: 14px;

        }


        .billing-detail-row strong {

            font-size: 14px;

        }


        .status-badge {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            padding: 6px 12px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: 700;

        }


        .status-unpaid {

            background: #fff3cd;

            color: #856404;

        }


        .status-pending {

            background: #cff4fc;

            color: #055160;

        }


        .status-paid {

            background: #d1e7dd;

            color: #0f5132;

        }


        .package-box {

            display: flex;

            align-items: center;

            gap: 15px;

            padding: 15px;

            border-radius: 14px;

            background: #f8f9fa;

        }


        .package-icon {

            width: 48px;

            height: 48px;

            border-radius: 12px;

            background: #e7f1ff;

            color: #0d6efd;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 21px;

        }


        .payment-table {

            width: 100%;

            border-collapse: collapse;

        }


        .payment-table th {

            text-align: left;

            padding: 14px;

            font-size: 12px;

            color: #777;

            border-bottom: 1px solid #eee;

        }


        .payment-table td {

            padding: 15px 14px;

            font-size: 13px;

            border-bottom: 1px solid #f1f1f1;

        }


        .payment-table tr:last-child td {

            border-bottom: 0;

        }


        .invoice-number {

            font-weight: 700;

            color: #333;

        }


        .empty-payment {

            text-align: center;

            padding: 30px !important;

            color: #888;

        }


        .billing-icon {

            width: 50px;

            height: 50px;

            border-radius: 14px;

            background: rgba(255,255,255,.15);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 24px;

            margin-bottom: 20px;

        }


        @media(max-width:768px) {

            .billing-info {

                flex-direction: column;

                gap: 12px;

            }

            .billing-amount {

                font-size: 30px;

            }

        }

    </style>

</head>


<body>

<aside class="sidebar">

    <div class="sidebar-logo">

        <div class="logo-icon">

            <i class="bi bi-wifi"></i>

        </div>

        <div class="logo-text">

            <h5>
                WiFi Management
            </h5>

            <span>
                Customer Portal
            </span>

        </div>

    </div>


    <div class="sidebar-menu">
        <p class="menu-title">
            MENU
        </p>

        <a
            href="dashboard.php"
            class="menu-item"
        >

            <i class="bi bi-grid-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <a
            href="billing.php"
            class="menu-item active"
        >

            <i class="bi bi-credit-card-fill"></i>

            <span>
                Tagihan
            </span>

        </a>


        <a
            href="usage.php"
            class="menu-item"
        >

            <i class="bi bi-speedometer2"></i>

            <span>
                Pemakaian
            </span>

        </a>


        <a
            href="speedtest.php"
            class="menu-item"
        >

            <i class="bi bi-lightning-charge-fill"></i>

            <span>
                Speed Test
            </span>

        </a>


        <a
            href="complaint.php"
            class="menu-item"
        >

            <i class="bi bi-tools"></i>

            <span>
                Gangguan
            </span>

        </a>


        <a
            href="network_status.php"
            class="menu-item"
        >

            <i class="bi bi-globe2"></i>

            <span>
                Status Jaringan
            </span>

        </a>


        <a
            href="chat.php"
            class="menu-item"
        >

            <i class="bi bi-chat-dots-fill"></i>

            <span>
                Chat CS
            </span>

        </a>


        <p class="menu-title menu-account">
            AKUN
        </p>


        <a
            href="upgrade.php"
            class="menu-item"
        >

            <i class="bi bi-arrow-up-circle-fill"></i>

            <span>
                Upgrade Paket
            </span>

        </a>


        <a
            href="service_request.php"
            class="menu-item"
        >

            <i class="bi bi-plus-circle-fill"></i>

            <span>
                Layanan Tambahan
            </span>

        </a>


        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile Saya
            </span>

        </a>


        <a
            href="../logout.php"
            class="menu-item logout"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


    <!-- USER -->

    <div class="sidebar-user">

        <div class="user-avatar">

            <?= htmlspecialchars($initial) ?>

        </div>

        <div class="user-detail">

            <strong>
                <?= htmlspecialchars($nama) ?>
            </strong>

            <span>
                Customer
            </span>

        </div>

    </div>

</aside>

<main class="main-content">

<header class="topbar">

    <div>

        <h4>
            Tagihan
        </h4>

        <span>
            Kelola pembayaran layanan internet kamu
        </span>

    </div>


    <div class="topbar-right">

        <a
            href="profile.php"
            class="top-profile"
        >

            <div class="top-avatar">

                <?= htmlspecialchars($initial) ?>

            </div>

            <div class="top-user">

                <strong>
                    <?= htmlspecialchars($nama) ?>
                </strong>

                <span>
                    Customer
                </span>

            </div>

        </a>

    </div>

</header>

<div class="content">

<div class="mb-4">

    <span
        style="
            color:#0d6efd;
            font-size:12px;
            font-weight:700;
            letter-spacing:1px;
        "
    >
        BILLING
    </span>

    <h2
        style="
            margin-top:5px;
            font-weight:800;
        "
    >
        Tagihan Saya
    </h2>

    <p class="text-muted">
        Lihat tagihan aktif dan riwayat pembayaran layanan internet kamu.
    </p>

</div>

<div class="row g-4 mb-4">


    <!-- TAGIHAN -->

    <div class="col-lg-7">

        <div class="billing-main-card">

            <div class="billing-icon">

                <i class="bi bi-receipt"></i>

            </div>


            <span class="billing-label">
                TAGIHAN SAAT INI
            </span>


            <?php if ($tagihan): ?>

                <div class="billing-amount">

                    <?= rupiah($jumlahTagihan) ?>

                </div>


                <div>

                    <?php

                    if ($statusTagihan === 'paid') {

                        $statusClass = 'status-paid';

                    } elseif ($statusTagihan === 'pending') {

                        $statusClass = 'status-pending';

                    } else {

                        $statusClass = 'status-unpaid';

                    }

                    ?>


                    <span class="status-badge <?= $statusClass ?>">

                        <i class="bi bi-circle-fill"></i>

                        <?= htmlspecialchars($statusLabel) ?>

                    </span>

                </div>


                <div class="billing-info">


                    <div class="billing-info-item">

                        <span>
                            Invoice
                        </span>

                        <strong>
                            <?= htmlspecialchars($invoice) ?>
                        </strong>

                    </div>


                    <div class="billing-info-item">

                        <span>
                            Jatuh Tempo
                        </span>

                        <strong>
                            <?= htmlspecialchars($tanggalJatuhTempo) ?>
                        </strong>

                    </div>


                    <div class="billing-info-item">

                        <span>
                            Paket
                        </span>

                        <strong>
                            <?= htmlspecialchars($paketNama) ?>
                        </strong>

                    </div>

                </div>


                <?php if (
                    $statusTagihan === 'unpaid'
                    || $statusTagihan === 'pending'
                ): ?>

                    <a
                        href="payment.php?id=<?= urlencode($tagihan['id']) ?>"
                        class="btn-payment"
                    >

                        <i class="bi bi-credit-card"></i>

                        Bayar Sekarang

                    </a>

                <?php endif; ?>


            <?php else: ?>


                <div class="billing-amount">

                    Tidak Ada Tagihan

                </div>


                <p style="opacity:.8;">

                    Saat ini tidak ada tagihan yang harus dibayar.

                </p>


            <?php endif; ?>

        </div>

    </div>



    <!-- DETAIL -->

    <div class="col-lg-5">

        <div class="billing-page-card">

            <h5
                style="
                    font-weight:700;
                    margin-bottom:20px;
                "
            >
                Detail Layanan
            </h5>


            <div class="package-box mb-3">

                <div class="package-icon">

                    <i class="bi bi-router-fill"></i>

                </div>


                <div>

                    <strong>
                        <?= htmlspecialchars($paketNama) ?>
                    </strong>

                    <div
                        style="
                            color:#0d6efd;
                            font-weight:700;
                            font-size:14px;
                        "
                    >
                        <?= htmlspecialchars($paketSpeed) ?>
                    </div>

                    <small class="text-muted">
                        Paket internet aktif
                    </small>

                </div>

            </div>


            <div class="billing-detail-row">

                <span>
                    Customer ID
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $customer['kode_pelanggan'] ?? '-'
                    ) ?>
                </strong>

            </div>


            <div class="billing-detail-row">

                <span>
                    Nama
                </span>

                <strong>
                    <?= htmlspecialchars($nama) ?>
                </strong>

            </div>


            <div class="billing-detail-row">

                <span>
                    Email
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $customer['email'] ?? '-'
                    ) ?>
                </strong>

            </div>


            <div class="billing-detail-row">

                <span>
                    Nomor HP
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $customer['no_hp'] ?? '-'
                    ) ?>
                </strong>

            </div>

        </div>

    </div>

</div>

<div class="billing-page-card">

    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h5
                style="
                    font-weight:700;
                    margin-bottom:4px;
                "
            >
                Riwayat Pembayaran
            </h5>

            <span
                style="
                    color:#888;
                    font-size:13px;
                "
            >
                Daftar pembayaran terakhir kamu
            </span>

        </div>


        <div
            style="
                width:42px;
                height:42px;
                border-radius:12px;
                background:#e7f1ff;
                color:#0d6efd;
                display:flex;
                align-items:center;
                justify-content:center;
            "
        >

            <i class="bi bi-clock-history"></i>

        </div>

    </div>



    <div class="table-responsive">

        <table class="payment-table">

            <thead>

                <tr>

                    <th>
                        Tanggal
                    </th>

                    <th>
                        Invoice
                    </th>

                    <th>
                        Jumlah
                    </th>

                    <th>
                        Metode
                    </th>

                    <th>
                        Status
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if (!empty($payments)): ?>


                <?php foreach ($payments as $payment): ?>


                    <?php

                    $paymentStatus =
                        strtolower(
                            $payment['status'] ?? 'paid'
                        );


                    if ($paymentStatus === 'paid') {

                        $statusClass = 'status-paid';

                        $paymentLabel = 'Lunas';

                    } elseif ($paymentStatus === 'pending') {

                        $statusClass = 'status-pending';

                        $paymentLabel = 'Pending';

                    } else {

                        $statusClass = 'status-unpaid';

                        $paymentLabel = ucfirst(
                            $paymentStatus
                        );

                    }


                    $paymentInvoice =
                        $payment['invoice'] ??
                        $payment['invoice_number'] ??
                        '-';


                    $paymentAmount =
                        $payment['jumlah'] ??
                        $payment['amount'] ??
                        0;


                    $paymentMethod =
                        $payment['metode'] ??
                        $payment['method'] ??
                        '-';


                    $paymentDate =
                        $payment['created_at'] ??
                        '-';

                    ?>


                    <tr>


                        <td>

                            <?= htmlspecialchars(
                                $paymentDate
                            ) ?>

                        </td>


                        <td>

                            <span class="invoice-number">

                                <?= htmlspecialchars(
                                    $paymentInvoice
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <strong>

                                <?= rupiah(
                                    $paymentAmount
                                ) ?>

                            </strong>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $paymentMethod
                            ) ?>

                        </td>


                        <td>

                            <span
                                class="status-badge <?= $statusClass ?>"
                            >

                                <i class="bi bi-circle-fill"></i>

                                <?= htmlspecialchars(
                                    $paymentLabel
                                ) ?>

                            </span>

                        </td>


                    </tr>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td
                        colspan="5"
                        class="empty-payment"
                    >

                        <i
                            class="bi bi-receipt"
                            style="
                                font-size:28px;
                                display:block;
                                margin-bottom:10px;
                            "
                        ></i>

                        Belum ada riwayat pembayaran.

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>

<div class="row g-4 mt-1">


    <div class="col-md-4">

        <div class="billing-page-card">

            <i
                class="bi bi-shield-check"
                style="
                    font-size:28px;
                    color:#198754;
                "
            ></i>

            <h6
                style="
                    margin-top:15px;
                    font-weight:700;
                "
            >
                Pembayaran Aman
            </h6>

            <p
                style="
                    font-size:13px;
                    color:#777;
                    margin:0;
                "
            >
                Semua pembayaran diproses melalui sistem yang aman.
            </p>

        </div>

    </div>



    <div class="col-md-4">

        <div class="billing-page-card">

            <i
                class="bi bi-clock-history"
                style="
                    font-size:28px;
                    color:#0d6efd;
                "
            ></i>

            <h6
                style="
                    margin-top:15px;
                    font-weight:700;
                "
            >
                Pembayaran Tepat Waktu
            </h6>

            <p
                style="
                    font-size:13px;
                    color:#777;
                    margin:0;
                "
            >
                Bayar sebelum jatuh tempo agar layanan tetap aktif.
            </p>

        </div>

    </div>



    <div class="col-md-4">

        <div class="billing-page-card">

            <i
                class="bi bi-headset"
                style="
                    font-size:28px;
                    color:#6f42c1;
                "
            ></i>

            <h6
                style="
                    margin-top:15px;
                    font-weight:700;
                "
            >
                Butuh Bantuan?
            </h6>

            <p
                style="
                    font-size:13px;
                    color:#777;
                    margin:0 0 10px;
                "
            >
                Hubungi customer service jika ada masalah pembayaran.
            </p>

            <a
                href="chat.php"
                style="
                    font-size:13px;
                    font-weight:700;
                    text-decoration:none;
                "
            >
                Chat dengan CS
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>

    </div>

</div>


</div>

</main>


</body>

</html>