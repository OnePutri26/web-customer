<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

/* =========================================================
   SESSION
========================================================= */

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    die("Session user tidak valid.");
}


/* =========================================================
   HELPER
========================================================= */

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function statusClass($status): string
{
    $status = strtolower(
        trim((string) ($status ?? ''))
    );

    return match ($status) {

        'open',
        'baru',
        'pending'
            => 'status-open',

        'process',
        'proses',
        'on progress',
        'in progress'
            => 'status-process',

        'closed',
        'selesai',
        'resolved'
            => 'status-closed',

        default
            => 'status-default'
    };
}


function priorityClass($priority): string
{
    $priority = strtolower(
        trim((string) ($priority ?? ''))
    );

    return match ($priority) {

        'high',
        'tinggi'
            => 'priority-high',

        'medium',
        'sedang',
        'normal'
            => 'priority-medium',

        'low',
        'rendah'
            => 'priority-low',

        default
            => 'priority-default'
    };
}


/* =========================================================
   DATABASE CHECK
========================================================= */

if (!isset($conn)) {
    die("Koneksi database tidak tersedia.");
}


/* =========================================================
   GET CUSTOMER
========================================================= */

$stmtCustomer = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmtCustomer) {
    die(
        "Query customer gagal: "
        . $conn->error
    );
}

$stmtCustomer->bind_param(
    "i",
    $userId
);

if (!$stmtCustomer->execute()) {
    die(
        "Execute query customer gagal: "
        . $stmtCustomer->error
    );
}

$resultCustomer = $stmtCustomer->get_result();

$customer = $resultCustomer->fetch_assoc();

$stmtCustomer->close();


/* =========================================================
   VALIDATE CUSTOMER
========================================================= */

if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$customerId = (int) (
    $customer['id'] ?? 0
);


if ($customerId <= 0) {
    die("ID customer tidak valid.");
}


/* =========================================================
   CUSTOMER DISPLAY
========================================================= */

$nama = trim(
    (string) (
        $customer['nama'] ?? ''
    )
);


if ($nama === '') {
    $nama = 'Customer';
}


$initial = strtoupper(
    mb_substr(
        $nama,
        0,
        1,
        'UTF-8'
    )
);


/* =========================================================
   GET COMPLAINT
========================================================= */

/*
    PENTING:

    Relasi complaint -> customers
    menggunakan:

        complaint.id_customer

    BUKAN:

        complaint.customer_id
*/

$stmtComplaint = $conn->prepare("
    SELECT *
    FROM complaint
    WHERE id_customer = ?
    ORDER BY id DESC
");


if (!$stmtComplaint) {
    die(
        "Query complaint gagal: "
        . $conn->error
    );
}


$stmtComplaint->bind_param(
    "i",
    $customerId
);


if (!$stmtComplaint->execute()) {
    die(
        "Execute query complaint gagal: "
        . $stmtComplaint->error
    );
}


$resultComplaint = $stmtComplaint->get_result();


/* =========================================================
   INITIAL DATA
========================================================= */

$complaints = [];

$totalComplaint   = 0;
$openComplaint    = 0;
$processComplaint = 0;
$closedComplaint  = 0;


/* =========================================================
   LOOP COMPLAINT
========================================================= */

while ($row = $resultComplaint->fetch_assoc()) {

    $complaints[] = $row;

    $totalComplaint++;


    $status = strtolower(
        trim(
            (string) (
                $row['status'] ?? ''
            )
        )
    );


    if (
        in_array(
            $status,
            [
                'open',
                'baru',
                'pending'
            ],
            true
        )
    ) {

        $openComplaint++;

    } elseif (
        in_array(
            $status,
            [
                'process',
                'proses',
                'on progress',
                'in progress'
            ],
            true
        )
    ) {

        $processComplaint++;

    } elseif (
        in_array(
            $status,
            [
                'closed',
                'selesai',
                'resolved'
            ],
            true
        )
    ) {

        $closedComplaint++;
    }
}


$stmtComplaint->close();

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
        Complaint Saya - WiFi Management
    </title>


    <!-- =====================================================
         BOOTSTRAP
    ====================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =====================================================
         BOOTSTRAP ICONS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- =====================================================
         COMPLAINT CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="/dashboard/web-customer/assets/css/complaint.css?v=10"
    >

</head>


<body>


<!-- =========================================================
     DASHBOARD WRAPPER
========================================================= -->

<div class="dashboard-wrapper">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">


        <!-- BRAND -->

        <div class="sidebar-brand">

            <div class="brand-icon">

                <i class="bi bi-wifi"></i>

            </div>


            <div class="brand-text">

                <h5>
                    WiFi Management
                </h5>

                <span>
                    Customer Portal
                </span>

            </div>

        </div>


        <!-- MENU -->

        <nav class="sidebar-menu">


            <a href="dashboard.php">

                <i class="bi bi-grid-1x2-fill"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <a href="billing.php">

                <i class="bi bi-receipt"></i>

                <span>
                    Tagihan
                </span>

            </a>


            <a href="usage.php">

                <i class="bi bi-bar-chart-fill"></i>

                <span>
                    Penggunaan
                </span>

            </a>


            <a href="speedtest.php">

                <i class="bi bi-speedometer2"></i>

                <span>
                    Speed Test
                </span>

            </a>


            <a
                href="complaint.php"
                class="active"
            >

                <i class="bi bi-ticket-detailed-fill"></i>

                <span>
                    Complaint
                </span>

            </a>


            <a href="network_status.php">

                <i class="bi bi-broadcast-pin"></i>

                <span>
                    Status Jaringan
                </span>

            </a>


            <a href="chat.php">

                <i class="bi bi-chat-dots-fill"></i>

                <span>
                    Chat Support
                </span>

            </a>


            <a href="upgrade.php">

                <i class="bi bi-arrow-up-circle-fill"></i>

                <span>
                    Upgrade Paket
                </span>

            </a>


            <a href="service_request.php">

                <i class="bi bi-tools"></i>

                <span>
                    Permintaan Layanan
                </span>

            </a>


            <a href="profile.php">

                <i class="bi bi-person-fill"></i>

                <span>
                    Profile
                </span>

            </a>


        </nav>


        <!-- SIDEBAR FOOTER -->

        <div class="sidebar-footer">

            <div class="sidebar-user">


                <div class="sidebar-avatar">

                    <?= e($initial) ?>

                </div>


                <div class="sidebar-user-info">

                    <strong>
                        <?= e($nama) ?>
                    </strong>

                    <small>
                        Customer
                    </small>

                </div>


            </div>

        </div>


    </aside>


    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="main-content">


        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="topbar">


            <div class="topbar-title">

                <h4>
                    Complaint
                </h4>

                <p>
                    Kelola dan pantau laporan gangguan kamu
                </p>

            </div>


            <div class="topbar-profile">

                <div class="topbar-avatar">

                    <?= e($initial) ?>

                </div>


                <div>

                    <strong>
                        <?= e($nama) ?>
                    </strong>

                    <small>
                        Customer
                    </small>

                </div>

            </div>


        </header>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <section class="complaint-page">


            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div class="complaint-header">


                <div class="complaint-title-area">


                    <div class="page-label">

                        <i class="bi bi-ticket-detailed-fill"></i>

                        CUSTOMER SUPPORT

                    </div>


                    <h1>
                        Complaint Saya
                    </h1>


                    <p>
                        Laporkan masalah dan pantau proses
                        penanganan gangguan internet kamu.
                    </p>


                </div>


                <a
                    href="complaint_create.php"
                    class="btn-create-complaint"
                >

                    <i class="bi bi-plus-lg"></i>

                    Buat Complaint

                </a>


            </div>


            <!-- =================================================
                 STATISTICS
            ================================================== -->

            <div class="complaint-stats">


                <!-- TOTAL -->

                <div class="stat-card">

                    <div class="stat-icon icon-total">

                        <i class="bi bi-ticket-perforated-fill"></i>

                    </div>

                    <div class="stat-content">

                        <span>
                            Total Complaint
                        </span>

                        <strong>
                            <?= $totalComplaint ?>
                        </strong>

                    </div>

                </div>


                <!-- OPEN -->

                <div class="stat-card">

                    <div class="stat-icon icon-open">

                        <i class="bi bi-exclamation-circle-fill"></i>

                    </div>

                    <div class="stat-content">

                        <span>
                            Complaint Baru
                        </span>

                        <strong>
                            <?= $openComplaint ?>
                        </strong>

                    </div>

                </div>


                <!-- PROCESS -->

                <div class="stat-card">

                    <div class="stat-icon icon-process">

                        <i class="bi bi-arrow-repeat"></i>

                    </div>

                    <div class="stat-content">

                        <span>
                            Sedang Diproses
                        </span>

                        <strong>
                            <?= $processComplaint ?>
                        </strong>

                    </div>

                </div>


                <!-- CLOSED -->

                <div class="stat-card">

                    <div class="stat-icon icon-closed">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div class="stat-content">

                        <span>
                            Selesai
                        </span>

                        <strong>
                            <?= $closedComplaint ?>
                        </strong>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 COMPLAINT CARD
            ================================================== -->

            <div class="complaint-card">


                <!-- CARD HEADER -->

                <div class="card-heading">


                    <div>

                        <h5>
                            Riwayat Complaint
                        </h5>

                        <p>
                            Daftar laporan yang pernah kamu buat
                        </p>

                    </div>


                    <div class="total-badge">

                        <?= $totalComplaint ?>

                        Complaint

                    </div>


                </div>


                <!-- =================================================
                     LIST
                ================================================== -->

                <?php if (!empty($complaints)): ?>


                    <div class="complaint-list">


                        <?php foreach ($complaints as $row): ?>


                            <?php

                            $complaintId = (int) (
                                $row['id'] ?? 0
                            );


                            $status = trim(
                                (string) (
                                    $row['status'] ?? ''
                                )
                            );


                            if ($status === '') {
                                $status = 'unknown';
                            }


                            $priority = trim(
                                (string) (
                                    $row['prioritas']
                                    ?? 'normal'
                                )
                            );


                            if ($priority === '') {
                                $priority = 'normal';
                            }


                            $subject = trim(
                                (string) (
                                    $row['subject']
                                    ?? ''
                                )
                            );


                            if ($subject === '') {
                                $subject = 'Tanpa Subject';
                            }


                            $kodePelanggan = trim(
                                (string) (
                                    $row['kode_pelanggan']
                                    ?? '-'
                                )
                            );


                            if ($kodePelanggan === '') {
                                $kodePelanggan = '-';
                            }


                            $createdAt = '-';


                            if (
                                !empty(
                                    $row['created_at']
                                    ?? ''
                                )
                            ) {

                                $timestamp = strtotime(
                                    $row['created_at']
                                );


                                if ($timestamp !== false) {

                                    $createdAt = date(
                                        'd M Y, H:i',
                                        $timestamp
                                    );

                                }

                            }


                            $statusCss =
                                statusClass($status);


                            $priorityCss =
                                priorityClass($priority);

                            ?>


                            <!-- COMPLAINT ITEM -->

                            <div class="complaint-item">


                                <!-- ICON -->

                                <div class="complaint-ticket-icon">

                                    <i class="bi bi-ticket-detailed-fill"></i>

                                </div>


                                <!-- MAIN -->

                                <div class="complaint-main">


                                    <div class="complaint-top">


                                        <span class="complaint-code">

                                            <?= e(
                                                $kodePelanggan
                                            ) ?>

                                        </span>


                                        <span class="complaint-date">

                                            <i class="bi bi-clock"></i>

                                            <?= e(
                                                $createdAt
                                            ) ?>

                                        </span>


                                    </div>


                                    <h6>

                                        <?= e(
                                            $subject
                                        ) ?>

                                    </h6>


                                    <div class="complaint-meta">


                                        <span
                                            class="complaint-badge <?= e($priorityCss) ?>"
                                        >

                                            <i class="bi bi-flag-fill"></i>

                                            <?= e(
                                                strtoupper(
                                                    $priority
                                                )
                                            ) ?>

                                        </span>


                                        <span
                                            class="complaint-badge <?= e($statusCss) ?>"
                                        >

                                            <i class="bi bi-circle-fill"></i>

                                            <?= e(
                                                strtoupper(
                                                    $status
                                                )
                                            ) ?>

                                        </span>


                                    </div>


                                </div>


                                <!-- ACTION -->

                                <?php if ($complaintId > 0): ?>

                                    <div class="complaint-action">

                                        <a
                                            href="complaint_detail.php?id=<?= $complaintId ?>"
                                            class="btn-detail"
                                            title="Lihat detail complaint"
                                            aria-label="Lihat detail complaint"
                                        >

                                            <i class="bi bi-chevron-right"></i>

                                        </a>

                                    </div>

                                <?php endif; ?>


                            </div>


                        <?php endforeach; ?>


                    </div>


                <?php else: ?>


                    <!-- =================================================
                         EMPTY STATE
                    ================================================== -->

                    <div class="empty-state">


                        <div class="empty-icon">

                            <i class="bi bi-ticket-detailed"></i>

                        </div>


                        <h4>
                            Belum Ada Complaint
                        </h4>


                        <p>
                            Kamu belum memiliki laporan complaint.
                            Jika mengalami masalah internet, kamu bisa
                            membuat laporan baru.
                        </p>


                        <a
                            href="complaint_create.php"
                            class="btn-create-complaint"
                        >

                            <i class="bi bi-plus-lg"></i>

                            Buat Complaint

                        </a>


                    </div>


                <?php endif; ?>


            </div>


        </section>


    </main>


</div>


<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>

</html>
