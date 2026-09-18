<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


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


function formatTanggal($tanggal): string
{
    if (!$tanggal || $tanggal === '-') {
        return '-';
    }

    $time = strtotime($tanggal);

    if (!$time) {
        return e($tanggal);
    }

    return date('d M Y', $time);
}


function tableExists(
    mysqli $conn,
    string $table
): bool {

    $table = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$table}'"
    );

    return $result &&
           $result->num_rows > 0;
}


function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {

    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result &&
           $result->num_rows > 0;
}


/*
|--------------------------------------------------------------------------
| NORMALISASI STATUS
|--------------------------------------------------------------------------
*/

function normalizeSubscriptionStatus(
    $status
): string {

    $status = strtolower(
        trim(
            (string) $status
        )
    );


    /*
    |----------------------------------------------------------------------
    | STATUS AKTIF
    |----------------------------------------------------------------------
    */

    if (
        in_array(
            $status,
            [
                'active',
                'aktif'
            ],
            true
        )
    ) {

        return 'active';
    }


    /*
    |----------------------------------------------------------------------
    | STATUS PENDING
    |----------------------------------------------------------------------
    */

    if (
        in_array(
            $status,
            [
                'pending',
                'proses',
                'menunggu_pemasangan',
                'menunggu pemasangan'
            ],
            true
        )
    ) {

        return 'pending';
    }


    /*
    |----------------------------------------------------------------------
    | STATUS SUSPENDED
    |----------------------------------------------------------------------
    */

    if (
        in_array(
            $status,
            [
                'suspended',
                'ditangguhkan'
            ],
            true
        )
    ) {

        return 'suspended';
    }


    /*
    |----------------------------------------------------------------------
    | STATUS TERMINATED
    |----------------------------------------------------------------------
    */

    if (
        in_array(
            $status,
            [
                'terminated',
                'berakhir',
                'nonaktif'
            ],
            true
        )
    ) {

        return 'terminated';
    }


    /*
    |----------------------------------------------------------------------
    | DEFAULT
    |----------------------------------------------------------------------
    */

    return 'belum_berlangganan';
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) (
    $_SESSION['user_id'] ?? 0
);


if ($userId <= 0) {

    header(
        "Location: ../login.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| DEFAULT DATA
|--------------------------------------------------------------------------
*/

$customer = null;

$customerId = 0;

$nama =
    $_SESSION['nama']
    ??
    $_SESSION['username']
    ??
    'Customer';

$email =
    $_SESSION['email']
    ??
    '';

$telephone = '';

$alamat = '';


$statusLangganan =
    'belum_berlangganan';


$paketNama =
    'Belum Memilih Paket';

$paketSpeed =
    '-';

$paketHarga =
    0;


$totalInstalasi =
    0;

$totalGangguan =
    0;


$jumlahTagihan =
    0;

$jatuhTempo =
    '-';

$nomorTagihan =
    '-';


$riwayatPembayaran =
    [];


/*
|--------------------------------------------------------------------------
| DATA CONTOH UNTUK TAMPILAN
|--------------------------------------------------------------------------
*/

$downloadMbps =
    87.4;

$uploadMbps =
    18.2;

$pingMs =
    9;

$networkNormal =
    true;


/*
|--------------------------------------------------------------------------
| CEK TABEL CUSTOMER
|--------------------------------------------------------------------------
*/

if (
    !tableExists(
        $conn,
        'customers'
    )
) {

    die(
        "Tabel customers tidak ditemukan."
    );
}


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER + PAKET
|--------------------------------------------------------------------------
*/

$sqlCustomer = "

    SELECT

        c.*,

        p.nama_paket,

        p.speed_mbps,

        p.harga AS harga_paket,

        p.deskripsi AS deskripsi_paket

    FROM customers c

    LEFT JOIN paket_wifi p

        ON p.id = c.paket_id

    WHERE c.user_id = ?

    LIMIT 1

";


$stmt =
    $conn->prepare(
        $sqlCustomer
    );


if (!$stmt) {

    die(
        "Query customer gagal: " .
        e($conn->error)
    );
}


$stmt->bind_param(
    "i",
    $userId
);


$stmt->execute();


$result =
    $stmt->get_result();


if (
    $result &&
    $result->num_rows > 0
) {

    $customer =
        $result->fetch_assoc();

} else {

    $stmt->close();

    die(
        "Data customer tidak ditemukan."
    );
}


$stmt->close();


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$customerId =
    (int) (
        $customer['id']
        ??
        0
    );


$nama = trim(
    (string) (
        $customer['nama']
        ??
        $customer['name']
        ??
        $_SESSION['nama']
        ??
        $_SESSION['username']
        ??
        'Customer'
    )
);


if ($nama === '') {

    $nama =
        'Customer';
}


$email =
    $customer['email']
    ??
    $_SESSION['email']
    ??
    '';


$telephone =
    $customer['telephone']
    ??
    $customer['no_hp']
    ??
    $customer['phone']
    ??
    '';


$alamat =
    $customer['alamat']
    ??
    $customer['address']
    ??
    '';


/*
|--------------------------------------------------------------------------
| STATUS LANGGANAN
|--------------------------------------------------------------------------
|
| Database boleh menyimpan:
|
| active
| aktif
| pending
| proses
| menunggu_pemasangan
|
| Tetapi aplikasi menggunakan status yang konsisten.
|
*/

$statusLangganan =
    normalizeSubscriptionStatus(
        $customer['status_langganan']
        ??
        ''
    );


/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

if (
    !empty(
        $customer['nama_paket']
    )
) {

    $paketNama =
        $customer['nama_paket'];
}


if (
    isset(
        $customer['speed_mbps']
    ) &&
    $customer['speed_mbps'] !== null
) {

    $paketSpeed =
        $customer['speed_mbps'] .
        ' Mbps';
}


if (
    isset(
        $customer['harga_paket']
    ) &&
    $customer['harga_paket'] !== null
) {

    $paketHarga =
        (float)
        $customer['harga_paket'];
}


/*
|--------------------------------------------------------------------------
| STATUS DISPLAY
|--------------------------------------------------------------------------
*/

$statusLabel =
    'Belum Berlangganan';

$statusClass =
    'pending';

$statusIcon =
    'bi-cart3';


switch (
    $statusLangganan
) {

    case 'active':

        $statusLabel =
            'Layanan Aktif';

        $statusClass =
            'active';

        $statusIcon =
            'bi-check-circle-fill';

        break;


    case 'pending':

        $statusLabel =
            'Menunggu Aktivasi';

        $statusClass =
            'pending';

        $statusIcon =
            'bi-hourglass-split';

        break;


    case 'suspended':

        $statusLabel =
            'Layanan Ditangguhkan';

        $statusClass =
            'suspended';

        $statusIcon =
            'bi-pause-circle-fill';

        break;


    case 'terminated':

        $statusLabel =
            'Layanan Berakhir';

        $statusClass =
            'terminated';

        $statusIcon =
            'bi-x-circle-fill';

        break;


    default:

        $statusLabel =
            'Belum Berlangganan';

        $statusClass =
            'pending';

        $statusIcon =
            'bi-cart3';

        break;
}


/*
|--------------------------------------------------------------------------
| TOTAL INSTALASI
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists(
        $conn,
        'instalasi'
    ) &&
    columnExists(
        $conn,
        'instalasi',
        'id_customer'
    )
) {

    $stmt =
        $conn->prepare("
            SELECT COUNT(*) AS total
            FROM instalasi
            WHERE id_customer = ?
        ");


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $customerId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();


        if ($result) {

            $row =
                $result->fetch_assoc();

            $totalInstalasi =
                (int) (
                    $row['total']
                    ??
                    0
                );
        }


        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| TOTAL GANGGUAN
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists(
        $conn,
        'complaint'
    ) &&
    columnExists(
        $conn,
        'complaint',
        'id_customer'
    )
) {

    $sqlComplaint = "

        SELECT COUNT(*) AS total

        FROM complaint

        WHERE id_customer = ?

    ";


    if (
        columnExists(
            $conn,
            'complaint',
            'status'
        )
    ) {

        $sqlComplaint .= "

            AND LOWER(status)
            NOT IN (
                'closed',
                'resolved'
            )

        ";
    }


    $stmt =
        $conn->prepare(
            $sqlComplaint
        );


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $customerId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();


        if ($result) {

            $row =
                $result->fetch_assoc();

            $totalGangguan =
                (int) (
                    $row['total']
                    ??
                    0
                );
        }


        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| TAGIHAN AKTIF
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists(
        $conn,
        'tagihan'
    ) &&
    columnExists(
        $conn,
        'tagihan',
        'id_customer'
    )
) {

    $statusCondition = '';


    if (
        columnExists(
            $conn,
            'tagihan',
            'status'
        )
    ) {

        $statusCondition = "

            AND LOWER(status)

            IN (
                'unpaid',
                'pending',
                'belum bayar',
                'belum dibayar'
            )

        ";
    }


    $order = '';


    if (
        columnExists(
            $conn,
            'tagihan',
            'jatuh_tempo'
        )
    ) {

        $order = "

            ORDER BY jatuh_tempo ASC

        ";
    }


    $sqlTagihan = "

        SELECT *

        FROM tagihan

        WHERE id_customer = ?

        {$statusCondition}

        {$order}

        LIMIT 1

    ";


    $stmt =
        $conn->prepare(
            $sqlTagihan
        );


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $customerId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();


        if (
            $result &&
            $result->num_rows > 0
        ) {

            $tagihan =
                $result->fetch_assoc();


            $jumlahTagihan =
                $tagihan['jumlah']
                ??
                $tagihan['total']
                ??
                $tagihan['nominal']
                ??
                $tagihan['amount']
                ??
                0;


            $jatuhTempo =
                $tagihan['jatuh_tempo']
                ??
                $tagihan['tanggal_jatuh_tempo']
                ??
                '-';


            $nomorTagihan =
                $tagihan['nomor_tagihan']
                ??
                $tagihan['invoice']
                ??
                $tagihan['kode_tagihan']
                ??
                '-';
        }


        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| RIWAYAT PEMBAYARAN
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists(
        $conn,
        'pembayaran'
    ) &&
    columnExists(
        $conn,
        'pembayaran',
        'id_customer'
    )
) {

    $order = '';


    if (
        columnExists(
            $conn,
            'pembayaran',
            'created_at'
        )
    ) {

        $order = "

            ORDER BY created_at DESC

        ";

    } elseif (
        columnExists(
            $conn,
            'pembayaran',
            'tanggal_bayar'
        )
    ) {

        $order = "

            ORDER BY tanggal_bayar DESC

        ";
    }


    $sqlPayment = "

        SELECT *

        FROM pembayaran

        WHERE id_customer = ?

        {$order}

        LIMIT 5

    ";


    $stmt =
        $conn->prepare(
            $sqlPayment
        );


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $customerId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();


        if ($result) {

            while (
                $row =
                $result->fetch_assoc()
            ) {

                $riwayatPembayaran[] =
                    $row;
            }
        }


        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| AVATAR
|--------------------------------------------------------------------------
*/

$avatar =
    strtoupper(
        substr(
            trim($nama) ?: 'C',
            0,
            1
        )
    );


/*
|--------------------------------------------------------------------------
| CURRENT PAGE
|--------------------------------------------------------------------------
*/

$currentPage =
    basename(
        $_SERVER['PHP_SELF']
    );

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#0f4cdb"
    >

    <title>
        Dashboard Customer - WiFi Management
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


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="assets/css/dashboard.css"
    >

</head>


<body class="dashboard-page">

<div class="customer-layout">


    <!-- =====================================================
         MOBILE OVERLAY
    ====================================================== -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >


        <div class="sidebar-brand">

            <div class="brand-logo">

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


            <button
                type="button"
                class="sidebar-close"
                id="sidebarClose"
            >

                <i class="bi bi-x-lg"></i>

            </button>

        </div>


        <div class="sidebar-scroll">


            <div class="menu-section">

                <div class="menu-label">
                    UTAMA
                </div>


                <a
                    href="dashboard.php"
                    class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : ''; ?>"
                >

                    <span class="menu-icon">

                        <i class="bi bi-grid-fill"></i>

                    </span>


                    <span class="menu-text">

                        Dashboard

                    </span>

                </a>


                <?php if (
                    $statusLangganan === 'active'
                ): ?>

                    <a
                        href="billing.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-receipt-cutoff"></i>

                        </span>


                        <span class="menu-text">
                            Tagihan
                        </span>

                    </a>


                    <a
                        href="usage.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-bar-chart-fill"></i>

                        </span>


                        <span class="menu-text">
                            Pemakaian
                        </span>

                    </a>


                    <a
                        href="speedtest.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-speedometer2"></i>

                        </span>


                        <span class="menu-text">
                            Speed Test
                        </span>

                    </a>


                    <a
                        href="complaint.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-tools"></i>

                        </span>


                        <span class="menu-text">
                            Gangguan
                        </span>


                        <?php if (
                            $totalGangguan > 0
                        ): ?>

                            <span class="menu-badge">

                                <?= $totalGangguan; ?>

                            </span>

                        <?php endif; ?>

                    </a>


                    <a
                        href="network_status.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-globe2"></i>

                        </span>


                        <span class="menu-text">
                            Status Jaringan
                        </span>

                    </a>


                    <a
                        href="chat.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-chat-dots-fill"></i>

                        </span>


                        <span class="menu-text">
                            Chat CS
                        </span>

                    </a>

                <?php endif; ?>

            </div>


            <div class="menu-section">

                <div class="menu-label">
                    LAYANAN
                </div>


                <?php if (
                    $statusLangganan ===
                    'belum_berlangganan'
                ): ?>

                    <a
                        href="langganan.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-box-seam-fill"></i>

                        </span>


                        <span class="menu-text">
                            Pilih Paket
                        </span>

                    </a>


                <?php elseif (
                    $statusLangganan ===
                    'pending'
                ): ?>

                    <a
                        href="installation.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-hammer"></i>

                        </span>


                        <span class="menu-text">
                            Status Pemasangan
                        </span>

                    </a>


                <?php elseif (
                    $statusLangganan ===
                    'active'
                ): ?>

                    <a
                        href="upgrade.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-arrow-up-circle-fill"></i>

                        </span>


                        <span class="menu-text">
                            Upgrade Paket
                        </span>

                    </a>


                    <a
                        href="service_request.php"
                        class="menu-item"
                    >

                        <span class="menu-icon">

                            <i class="bi bi-plus-circle-fill"></i>

                        </span>


                        <span class="menu-text">
                            Layanan Tambahan
                        </span>

                    </a>

                <?php endif; ?>

            </div>


            <div class="menu-section">

                <div class="menu-label">
                    AKUN
                </div>


                <a
                    href="profile.php"
                    class="menu-item"
                >

                    <span class="menu-icon">

                        <i class="bi bi-person-circle"></i>

                    </span>


                    <span class="menu-text">
                        Profile Saya
                    </span>

                </a>


                <a
                    href="notifikasi.php"
                    class="menu-item"
                >

                    <span class="menu-icon">

                        <i class="bi bi-bell-fill"></i>

                    </span>


                    <span class="menu-text">
                        Notifikasi
                    </span>

                </a>


                <a
                    href="../logout.php"
                    class="menu-item logout-item"
                >

                    <span class="menu-icon">

                        <i class="bi bi-box-arrow-right"></i>

                    </span>


                    <span class="menu-text">
                        Logout
                    </span>

                </a>

            </div>

        </div>


        <!-- SIDEBAR USER -->

        <div class="sidebar-user">

            <div class="sidebar-user-avatar">

                <?= e($avatar); ?>

            </div>


            <div class="sidebar-user-info">

                <strong>
                    <?= e($nama); ?>
                </strong>

                <span>
                    Customer
                </span>

            </div>


            <i class="bi bi-three-dots"></i>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- TOPBAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="mobileMenuButton"
                >

                    <i class="bi bi-list"></i>

                </button>


                <div>

                    <div class="breadcrumb-text">

                        Customer Portal

                        <span>
                            /
                        </span>

                        Dashboard

                    </div>


                    <h1>
                        Dashboard
                    </h1>

                </div>

            </div>


            <div class="topbar-right">

                <a
                    href="notifikasi.php"
                    class="notification-button"
                    title="Notifikasi"
                >

                    <i class="bi bi-bell"></i>

                    <span class="notification-pulse"></span>

                </a>


                <div class="top-profile">

                    <div class="top-profile-avatar">

                        <?= e($avatar); ?>

                    </div>


                    <div class="top-profile-info">

                        <strong>
                            <?= e($nama); ?>
                        </strong>

                        <span>
                            Customer
                        </span>

                    </div>


                    <i class="bi bi-chevron-down"></i>

                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <div class="dashboard-content">


            <!-- =================================================
                 WELCOME
            ================================================== -->

            <section class="welcome-hero">

                <div class="hero-decoration hero-decoration-one"></div>

                <div class="hero-decoration hero-decoration-two"></div>


                <div class="hero-content">

                    <div class="hero-label">

                        <span class="hero-label-dot"></span>

                        WIFI MANAGEMENT

                    </div>


                    <h2>

                        Halo,
                        <?= e($nama); ?>
                        👋

                    </h2>


                    <?php if (
                        $statusLangganan ===
                        'active'
                    ): ?>

                        <p>

                            Semua layanan internet kamu
                            siap dipantau dari satu dashboard.

                        </p>


                    <?php elseif (
                        $statusLangganan ===
                        'pending'
                    ): ?>

                        <p>

                            Pengajuan pemasangan kamu sedang
                            diproses oleh tim kami.

                        </p>


                    <?php elseif (
                        $statusLangganan ===
                        'suspended'
                    ): ?>

                        <p>

                            Layanan internet kamu sedang
                            ditangguhkan. Silakan hubungi
                            Customer Service.

                        </p>


                    <?php elseif (
                        $statusLangganan ===
                        'terminated'
                    ): ?>

                        <p>

                            Layanan internet kamu sudah
                            berakhir. Hubungi Customer Service
                            untuk informasi lebih lanjut.

                        </p>


                    <?php else: ?>

                        <p>

                            Belum berlangganan?
                            Pilih paket internet yang sesuai
                            dan mulai perjalanan internet kamu.

                        </p>

                    <?php endif; ?>


                    <div class="hero-actions">


                        <?php if (
                            $statusLangganan ===
                            'active'
                        ): ?>

                            <a
                                href="billing.php"
                                class="hero-button"
                            >

                                <i class="bi bi-receipt"></i>

                                Lihat Tagihan

                            </a>


                        <?php elseif (
                            $statusLangganan ===
                            'pending'
                        ): ?>

                            <a
                                href="installation.php"
                                class="hero-button"
                            >

                                <i class="bi bi-geo-alt"></i>

                                Lihat Pemasangan

                            </a>


                        <?php elseif (
                            $statusLangganan ===
                            'belum_berlangganan'
                        ): ?>

                            <a
                                href="langganan.php"
                                class="hero-button"
                            >

                                <i class="bi bi-box-seam"></i>

                                Pilih Paket Internet

                            </a>


                        <?php else: ?>

                            <a
                                href="chat.php"
                                class="hero-button"
                            >

                                <i class="bi bi-headset"></i>

                                Hubungi CS

                            </a>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="hero-visual">

                    <div class="wifi-orbit orbit-one"></div>

                    <div class="wifi-orbit orbit-two"></div>

                    <div class="wifi-core">

                        <i class="bi bi-wifi"></i>

                    </div>


                    <span class="signal-dot dot-one"></span>

                    <span class="signal-dot dot-two"></span>

                    <span class="signal-dot dot-three"></span>

                </div>

            </section>


            <!-- =================================================
                 STATUS MINI
            ================================================== -->

            <section class="status-strip">


                <div class="status-strip-item">

                    <div class="status-strip-icon blue">

                        <i class="bi bi-shield-check"></i>

                    </div>


                    <div>

                        <span>
                            Status Layanan
                        </span>

                        <strong>
                            <?= e($statusLabel); ?>
                        </strong>

                    </div>

                </div>


                <div class="status-divider"></div>


                <div class="status-strip-item">

                    <div class="status-strip-icon green">

                        <i class="bi bi-router"></i>

                    </div>


                    <div>

                        <span>
                            Paket Saat Ini
                        </span>

                        <strong>
                            <?= e($paketNama); ?>
                        </strong>

                    </div>

                </div>


                <div class="status-divider"></div>


                <div class="status-strip-item">

                    <div class="status-strip-icon purple">

                        <i class="bi bi-lightning-charge"></i>

                    </div>


                    <div>

                        <span>
                            Kecepatan
                        </span>

                        <strong>
                            <?= e($paketSpeed); ?>
                        </strong>

                    </div>

                </div>

            </section>


            <?php if (
                $statusLangganan ===
                'active'
            ): ?>


                <!-- =================================================
                     STATISTICS
                ================================================== -->

                <div class="section-title-row">

                    <div>

                        <span class="section-kicker">
                            OVERVIEW
                        </span>

                        <h2>
                            Ringkasan Layanan
                        </h2>

                    </div>


                    <span class="section-date">

                        <i class="bi bi-calendar3"></i>

                        Data pelanggan

                    </span>

                </div>


                <div class="stats-grid">


                    <!-- TAGIHAN -->

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon blue">

                                <i class="bi bi-receipt"></i>

                            </div>


                            <span class="stat-tag">
                                BULAN INI
                            </span>

                        </div>


                        <div class="stat-value">

                            <?= rupiah(
                                $jumlahTagihan
                            ); ?>

                        </div>


                        <div class="stat-label">
                            Tagihan aktif
                        </div>


                        <div class="stat-bottom">

                            <span>

                                <i class="bi bi-calendar-event"></i>

                                <?= formatTanggal(
                                    $jatuhTempo
                                ); ?>

                            </span>


                            <a href="billing.php">

                                Detail

                                <i class="bi bi-arrow-up-right"></i>

                            </a>

                        </div>

                    </div>


                    <!-- SPEED -->

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon purple">

                                <i class="bi bi-speedometer2"></i>

                            </div>


                            <span class="stat-tag green">
                                LIVE
                            </span>

                        </div>


                        <div class="stat-value">

                            <?= number_format(
                                $downloadMbps,
                                1
                            ); ?>

                            <small>
                                Mbps
                            </small>

                        </div>


                        <div class="stat-label">
                            Kecepatan download
                        </div>


                        <div class="stat-bottom">

                            <span class="positive">

                                <i class="bi bi-arrow-down"></i>

                                <?= number_format(
                                    $downloadMbps,
                                    1
                                ); ?>

                            </span>


                            <span class="upload-text">

                                <i class="bi bi-arrow-up"></i>

                                <?= number_format(
                                    $uploadMbps,
                                    1
                                ); ?>

                                Mbps

                            </span>

                        </div>

                    </div>


                    <!-- GANGGUAN -->

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon orange">

                                <i class="bi bi-tools"></i>

                            </div>


                            <span class="stat-tag">
                                AKTIF
                            </span>

                        </div>


                        <div class="stat-value">

                            <?= $totalGangguan; ?>

                        </div>


                        <div class="stat-label">
                            Laporan gangguan
                        </div>


                        <div class="stat-bottom">

                            <span>

                                <i class="bi bi-activity"></i>

                                Perlu ditangani

                            </span>


                            <a href="complaint.php">

                                Detail

                                <i class="bi bi-arrow-up-right"></i>

                            </a>

                        </div>

                    </div>


                    <!-- INSTALASI -->

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon green">

                                <i class="bi bi-hammer"></i>

                            </div>


                            <span class="stat-tag green">
                                TOTAL
                            </span>

                        </div>


                        <div class="stat-value">

                            <?= $totalInstalasi; ?>

                        </div>


                        <div class="stat-label">
                            Riwayat instalasi
                        </div>


                        <div class="stat-bottom">

                            <span>

                                <i class="bi bi-check2-circle"></i>

                                Tercatat

                            </span>


                            <a href="installation.php">

                                Detail

                                <i class="bi bi-arrow-up-right"></i>

                            </a>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     MAIN GRID
                ================================================== -->

                <div class="main-grid">


                    <!-- LEFT -->

                    <div class="main-column">


                        <!-- BANDWIDTH -->

                        <div class="dashboard-card bandwidth-card">

                            <div class="card-heading">

                                <div>

                                    <span class="card-kicker">
                                        NETWORK MONITORING
                                    </span>

                                    <h3>
                                        Aktivitas Bandwidth
                                    </h3>

                                    <p>
                                        Pantau performa koneksi
                                        internet secara berkala.
                                    </p>

                                </div>


                                <div class="live-status">

                                    <span></span>

                                    LIVE

                                </div>

                            </div>


                            <div class="chart-summary">


                                <div>

                                    <span>
                                        Download
                                    </span>

                                    <strong>

                                        <?= number_format(
                                            $downloadMbps,
                                            1
                                        ); ?>

                                        <small>
                                            Mbps
                                        </small>

                                    </strong>

                                </div>


                                <div>

                                    <span>
                                        Upload
                                    </span>

                                    <strong>

                                        <?= number_format(
                                            $uploadMbps,
                                            1
                                        ); ?>

                                        <small>
                                            Mbps
                                        </small>

                                    </strong>

                                </div>


                                <div>

                                    <span>
                                        Ping
                                    </span>

                                    <strong>

                                        <?= $pingMs; ?>

                                        <small>
                                            ms
                                        </small>

                                    </strong>

                                </div>

                            </div>


                            <div class="chart-container">

                                <canvas
                                    id="bandwidthChart"
                                ></canvas>

                            </div>

                        </div>


                        <!-- PAYMENT -->

                        <div class="dashboard-card payment-card">

                            <div class="card-heading compact">

                                <div>

                                    <span class="card-kicker">
                                        TRANSAKSI
                                    </span>

                                    <h3>
                                        Riwayat Pembayaran
                                    </h3>

                                </div>


                                <a
                                    href="billing.php"
                                    class="view-all"
                                >

                                    Lihat Semua

                                    <i class="bi bi-arrow-right"></i>

                                </a>

                            </div>


                            <div class="payment-list">


                                <div class="payment-list-head">

                                    <span>
                                        Tanggal
                                    </span>

                                    <span>
                                        Invoice
                                    </span>

                                    <span>
                                        Jumlah
                                    </span>

                                    <span>
                                        Status
                                    </span>

                                </div>


                                <?php if (
                                    empty(
                                        $riwayatPembayaran
                                    )
                                ): ?>


                                    <div class="payment-empty">

                                        <div class="empty-icon">

                                            <i class="bi bi-receipt"></i>

                                        </div>


                                        <strong>
                                            Belum ada pembayaran
                                        </strong>


                                        <span>

                                            Riwayat pembayaran kamu
                                            akan muncul di sini.

                                        </span>

                                    </div>


                                <?php else: ?>


                                    <?php foreach (
                                        $riwayatPembayaran
                                        as $payment
                                    ): ?>


                                        <?php

                                        $paymentDate =
                                            $payment[
                                                'tanggal_bayar'
                                            ]
                                            ??
                                            $payment[
                                                'created_at'
                                            ]
                                            ??
                                            $payment[
                                                'tanggal'
                                            ]
                                            ??
                                            '-';


                                        $invoice =
                                            $payment[
                                                'nomor_tagihan'
                                            ]
                                            ??
                                            $payment[
                                                'invoice'
                                            ]
                                            ??
                                            $payment[
                                                'kode_pembayaran'
                                            ]
                                            ??
                                            '-';


                                        $amount =
                                            $payment[
                                                'jumlah'
                                            ]
                                            ??
                                            $payment[
                                                'nominal'
                                            ]
                                            ??
                                            $payment[
                                                'total'
                                            ]
                                            ??
                                            $payment[
                                                'amount'
                                            ]
                                            ??
                                            0;


                                        $paymentStatus =
                                            $payment[
                                                'status'
                                            ]
                                            ??
                                            'paid';


                                        $statusText =
                                            strtolower(
                                                (string)
                                                $paymentStatus
                                            );


                                        $isSuccess =
                                            in_array(
                                                $statusText,
                                                [
                                                    'paid',
                                                    'lunas',
                                                    'success',
                                                    'berhasil'
                                                ],
                                                true
                                            );

                                        ?>


                                        <div class="payment-row">


                                            <span>

                                                <?= formatTanggal(
                                                    $paymentDate
                                                ); ?>

                                            </span>


                                            <strong>

                                                <?= e(
                                                    $invoice
                                                ); ?>

                                            </strong>


                                            <span>

                                                <?= rupiah(
                                                    $amount
                                                ); ?>

                                            </span>


                                            <span>


                                                <span
                                                    class="payment-badge <?= $isSuccess ? 'success' : 'warning'; ?>"
                                                >

                                                    <i class="bi bi-check-circle-fill"></i>

                                                    <?= e(
                                                        ucfirst(
                                                            $paymentStatus
                                                        )
                                                    ); ?>

                                                </span>


                                            </span>

                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>


                    <!-- RIGHT -->

                    <div class="side-column">


                        <!-- PACKAGE -->

                        <div class="dashboard-card package-card">


                            <div class="card-heading compact">

                                <div>

                                    <span class="card-kicker">
                                        SUBSCRIPTION
                                    </span>

                                    <h3>
                                        Paket Internet
                                    </h3>

                                </div>


                                <span class="active-pill">

                                    <i class="bi bi-check-circle-fill"></i>

                                    AKTIF

                                </span>

                            </div>


                            <div class="package-visual">

                                <div class="router-icon">

                                    <i class="bi bi-router-fill"></i>

                                </div>


                                <div class="router-waves">

                                    <span></span>

                                    <span></span>

                                    <span></span>

                                </div>

                            </div>


                            <div class="package-info">

                                <span>
                                    PAKET SAAT INI
                                </span>


                                <h4>
                                    <?= e(
                                        $paketNama
                                    ); ?>
                                </h4>


                                <div class="package-speed">

                                    <strong>

                                        <?= e(
                                            $paketSpeed
                                        ); ?>

                                    </strong>


                                    <span>
                                        Kecepatan internet
                                    </span>

                                </div>


                                <?php if (
                                    $paketHarga > 0
                                ): ?>

                                    <div class="package-price">

                                        <strong>

                                            <?= rupiah(
                                                $paketHarga
                                            ); ?>

                                        </strong>


                                        <span>
                                            / bulan
                                        </span>

                                    </div>

                                <?php endif; ?>

                            </div>


                            <a
                                href="upgrade.php"
                                class="full-outline-button"
                            >

                                <i class="bi bi-arrow-up-circle"></i>

                                Upgrade Paket

                                <i class="bi bi-arrow-right"></i>

                            </a>

                        </div>


                        <!-- NETWORK -->

                        <div class="dashboard-card network-card">


                            <div class="card-heading compact">

                                <div>

                                    <span class="card-kicker">
                                        CONNECTIVITY
                                    </span>

                                    <h3>
                                        Status Jaringan
                                    </h3>

                                </div>


                                <span class="online-dot">
                                    ONLINE
                                </span>

                            </div>


                            <div class="network-main">


                                <div class="network-check">

                                    <i class="bi bi-check-lg"></i>

                                </div>


                                <div>

                                    <strong>
                                        Jaringan Normal
                                    </strong>

                                    <span>
                                        Tidak ada gangguan
                                        terdeteksi.
                                    </span>

                                </div>

                            </div>


                            <div class="network-info">


                                <div>

                                    <i class="bi bi-broadcast-pin"></i>

                                    <span>
                                        Koneksi stabil
                                    </span>

                                </div>


                                <div>

                                    <i class="bi bi-shield-check"></i>

                                    <span>
                                        Sistem aman
                                    </span>

                                </div>

                            </div>


                            <a
                                href="network_status.php"
                                class="network-link"
                            >

                                Lihat detail jaringan

                                <i class="bi bi-arrow-up-right"></i>

                            </a>

                        </div>


                        <!-- HELP -->

                        <div class="dashboard-card help-card">

                            <div class="help-background"></div>


                            <div class="help-content">


                                <div class="help-icon">

                                    <i class="bi bi-headset"></i>

                                </div>


                                <span class="card-kicker">
                                    CUSTOMER SERVICE
                                </span>


                                <h3>
                                    Butuh bantuan?
                                </h3>


                                <p>

                                    Tim Customer Service siap
                                    membantu masalah internet kamu.

                                </p>


                                <a
                                    href="chat.php"
                                    class="help-button"
                                >

                                    <i class="bi bi-chat-dots-fill"></i>

                                    Chat Customer Service

                                </a>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     QUICK SERVICES
                ================================================== -->

                <div class="section-title-row quick-title">

                    <div>

                        <span class="section-kicker">
                            SHORTCUT
                        </span>

                        <h2>
                            Layanan Cepat
                        </h2>

                    </div>

                </div>


                <div class="quick-services">


                    <a
                        href="billing.php"
                        class="quick-service"
                    >

                        <div class="quick-icon blue">

                            <i class="bi bi-receipt-cutoff"></i>

                        </div>


                        <div>

                            <strong>
                                Tagihan
                            </strong>

                            <span>
                                Bayar & cek tagihan
                            </span>

                        </div>


                        <i class="bi bi-arrow-up-right"></i>

                    </a>


                    <a
                        href="speedtest.php"
                        class="quick-service"
                    >

                        <div class="quick-icon purple">

                            <i class="bi bi-speedometer2"></i>

                        </div>


                        <div>

                            <strong>
                                Speed Test
                            </strong>

                            <span>
                                Cek kecepatan internet
                            </span>

                        </div>


                        <i class="bi bi-arrow-up-right"></i>

                    </a>


                    <a
                        href="complaint.php"
                        class="quick-service"
                    >

                        <div class="quick-icon orange">

                            <i class="bi bi-tools"></i>

                        </div>


                        <div>

                            <strong>
                                Lapor Gangguan
                            </strong>

                            <span>
                                Laporkan masalah jaringan
                            </span>

                        </div>


                        <i class="bi bi-arrow-up-right"></i>

                    </a>


                    <a
                        href="chat.php"
                        class="quick-service"
                    >

                        <div class="quick-icon green">

                            <i class="bi bi-chat-dots-fill"></i>

                        </div>


                        <div>

                            <strong>
                                Chat CS
                            </strong>

                            <span>
                                Hubungi customer service
                            </span>

                        </div>


                        <i class="bi bi-arrow-up-right"></i>

                    </a>

                </div>


            <?php elseif (
                $statusLangganan ===
                'pending'
            ): ?>


                <!-- =================================================
                     PENDING
                ================================================== -->

                <section class="state-card pending-state">


                    <div class="state-icon">

                        <i class="bi bi-hourglass-split"></i>

                    </div>


                    <span class="state-label">
                        PENGAJUAN PEMASANGAN
                    </span>


                    <h2>
                        Pengajuan kamu sedang diproses
                    </h2>


                    <p>

                        Tim kami sedang memproses pengajuan
                        pemasangan internet kamu. Pantau status
                        pemasangan melalui halaman berikut.

                    </p>


                    <div class="state-steps">


                        <div class="state-step done">

                            <span>

                                <i class="bi bi-check-lg"></i>

                            </span>


                            <div>

                                <strong>
                                    Pengajuan diterima
                                </strong>

                                <small>
                                    Data kamu sudah tercatat.
                                </small>

                            </div>

                        </div>


                        <div class="state-step active">

                            <span>

                                <i class="bi bi-clock"></i>

                            </span>


                            <div>

                                <strong>
                                    Proses pemasangan
                                </strong>

                                <small>
                                    Menunggu proses dari tim teknisi.
                                </small>

                            </div>

                        </div>


                        <div class="state-step">

                            <span>

                                <i class="bi bi-wifi"></i>

                            </span>


                            <div>

                                <strong>
                                    Aktivasi layanan
                                </strong>

                                <small>
                                    Layanan akan aktif setelah instalasi selesai.
                                </small>

                            </div>

                        </div>

                    </div>


                    <a
                        href="installation.php"
                        class="state-button"
                    >

                        <i class="bi bi-geo-alt-fill"></i>

                        Lihat Status Pemasangan

                    </a>

                </section>


            <?php elseif (
                $statusLangganan ===
                'suspended'
            ): ?>


                <!-- SUSPENDED -->

                <section class="state-card suspended-state">


                    <div class="state-icon">

                        <i class="bi bi-pause-circle-fill"></i>

                    </div>


                    <span class="state-label">
                        LAYANAN DITANGGUHKAN
                    </span>


                    <h2>
                        Layanan internet sedang ditangguhkan
                    </h2>


                    <p>

                        Untuk informasi lebih lanjut mengenai
                        status layanan kamu, silakan hubungi
                        Customer Service.

                    </p>


                    <a
                        href="chat.php"
                        class="state-button"
                    >

                        <i class="bi bi-headset"></i>

                        Hubungi Customer Service

                    </a>

                </section>


            <?php elseif (
                $statusLangganan ===
                'terminated'
            ): ?>


                <!-- TERMINATED -->

                <section class="state-card terminated-state">


                    <div class="state-icon">

                        <i class="bi bi-x-circle-fill"></i>

                    </div>


                    <span class="state-label">
                        LAYANAN BERAKHIR
                    </span>


                    <h2>
                        Layanan internet sudah berakhir
                    </h2>


                    <p>

                        Hubungi Customer Service untuk mendapatkan
                        informasi mengenai layanan internet kamu.

                    </p>


                    <a
                        href="chat.php"
                        class="state-button"
                    >

                        <i class="bi bi-headset"></i>

                        Hubungi Customer Service

                    </a>

                </section>


            <?php else: ?>


                <!-- =================================================
                     BELUM BERLANGGANAN
                ================================================== -->

                <section class="state-card subscription-state">


                    <div class="state-visual">


                        <div class="floating-icon icon-one">

                            <i class="bi bi-wifi"></i>

                        </div>


                        <div class="floating-icon icon-two">

                            <i class="bi bi-lightning-charge-fill"></i>

                        </div>


                        <div class="subscription-main-icon">

                            <i class="bi bi-router-fill"></i>

                        </div>

                    </div>


                    <span class="state-label">
                        BELUM BERLANGGANAN
                    </span>


                    <h2>
                        Siap menikmati internet yang lebih nyaman?
                    </h2>


                    <p>

                        Pilih paket WiFi yang sesuai kebutuhan kamu.
                        Setelah memilih paket, lanjutkan proses
                        pemasangan melalui dashboard.

                    </p>


                    <a
                        href="langganan.php"
                        class="state-button"
                    >

                        <i class="bi bi-box-seam-fill"></i>

                        Pilih Paket Internet

                        <i class="bi bi-arrow-right"></i>

                    </a>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 FOOTER
            ================================================== -->

            <footer class="dashboard-footer">

                <span>

                    © <?= date('Y'); ?>

                    WiFi Management

                </span>


                <span>
                    Customer Portal
                </span>

            </footer>


        </div>

    </main>

</div>


<!-- Chart.js -->

<script
    src="https://cdn.jsdelivr.net/npm/chart.js"
></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /*
        |--------------------------------------------------------------------------
        | BANDWIDTH CHART
        |--------------------------------------------------------------------------
        */

        const chartElement =
            document.getElementById(
                'bandwidthChart'
            );


        if (chartElement) {

            const ctx =
                chartElement.getContext(
                    '2d'
                );


            const gradientDownload =
                ctx.createLinearGradient(
                    0,
                    0,
                    0,
                    260
                );


            gradientDownload.addColorStop(
                0,
                'rgba(37, 99, 235, 0.20)'
            );


            gradientDownload.addColorStop(
                1,
                'rgba(37, 99, 235, 0)'
            );


            const gradientUpload =
                ctx.createLinearGradient(
                    0,
                    0,
                    0,
                    260
                );


            gradientUpload.addColorStop(
                0,
                'rgba(139, 92, 246, 0.12)'
            );


            gradientUpload.addColorStop(
                1,
                'rgba(139, 92, 246, 0)'
            );


            new Chart(
                ctx,
                {

                    type: 'line',


                    data: {

                        labels: [

                            '10:00',
                            '10:05',
                            '10:10',
                            '10:15',
                            '10:20',
                            '10:25',
                            '10:30',
                            '10:35',
                            '10:40',
                            '10:45'

                        ],


                        datasets: [


                            {

                                label:
                                    'Download',


                                data: [

                                    42,
                                    56,
                                    51,
                                    68,
                                    61,
                                    77,
                                    72,
                                    81,
                                    76,
                                    <?= (float) $downloadMbps; ?>

                                ],


                                borderColor:
                                    '#2563eb',


                                backgroundColor:
                                    gradientDownload,


                                borderWidth:
                                    2.5,


                                pointRadius:
                                    0,


                                pointHoverRadius:
                                    5,


                                tension:
                                    0.42,


                                fill:
                                    true

                            },


                            {

                                label:
                                    'Upload',


                                data: [

                                    9,
                                    12,
                                    11,
                                    14,
                                    13,
                                    16,
                                    14,
                                    17,
                                    15,
                                    <?= (float) $uploadMbps; ?>

                                ],


                                borderColor:
                                    '#8b5cf6',


                                backgroundColor:
                                    gradientUpload,


                                borderWidth:
                                    2,


                                pointRadius:
                                    0,


                                pointHoverRadius:
                                    5,


                                tension:
                                    0.42,


                                fill:
                                    true

                            }

                        ]

                    },


                    options: {

                        responsive:
                            true,


                        maintainAspectRatio:
                            false,


                        interaction: {

                            intersect:
                                false,

                            mode:
                                'index'

                        },


                        plugins: {


                            legend: {

                                display:
                                    true,


                                position:
                                    'top',


                                align:
                                    'end',


                                labels: {

                                    usePointStyle:
                                        true,


                                    pointStyle:
                                        'circle',


                                    padding:
                                        18,


                                    boxWidth:
                                        7,


                                    font: {

                                        family:
                                            'Inter',

                                        size:
                                            10,

                                        weight:
                                            '600'

                                    }

                                }

                            },


                            tooltip: {

                                backgroundColor:
                                    '#172033',


                                titleColor:
                                    '#ffffff',


                                bodyColor:
                                    '#dbe5f4',


                                padding:
                                    12,


                                cornerRadius:
                                    10,


                                displayColors:
                                    true

                            }

                        },


                        scales: {


                            x: {

                                grid: {

                                    display:
                                        false

                                },


                                border: {

                                    display:
                                        false

                                },


                                ticks: {

                                    color:
                                        '#94a3b8',


                                    font: {

                                        family:
                                            'Inter',

                                        size:
                                            9

                                    }

                                }

                            },


                            y: {

                                beginAtZero:
                                    true,


                                suggestedMax:
                                    100,


                                grid: {

                                    color:
                                        'rgba(148,163,184,.12)',


                                    drawTicks:
                                        false

                                },


                                border: {

                                    display:
                                        false

                                },


                                ticks: {

                                    color:
                                        '#94a3b8',


                                    padding:
                                        8,


                                    font: {

                                        family:
                                            'Inter',

                                        size:
                                            9

                                    },


                                    callback:
                                        function (
                                            value
                                        ) {

                                            return value +
                                                ' Mbps';

                                        }

                                }

                            }

                        }

                    }

                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE SIDEBAR
        |--------------------------------------------------------------------------
        */

        const sidebar =
            document.getElementById(
                'sidebar'
            );


        const overlay =
            document.getElementById(
                'sidebarOverlay'
            );


        const mobileButton =
            document.getElementById(
                'mobileMenuButton'
            );


        const closeButton =
            document.getElementById(
                'sidebarClose'
            );


        function openSidebar()
        {

            if (!sidebar) {
                return;
            }


            sidebar.classList.add(
                'show'
            );


            if (overlay) {

                overlay.classList.add(
                    'show'
                );
            }


            document.body.classList.add(
                'sidebar-open'
            );
        }


        function closeSidebar()
        {

            if (!sidebar) {
                return;
            }


            sidebar.classList.remove(
                'show'
            );


            if (overlay) {

                overlay.classList.remove(
                    'show'
                );
            }


            document.body.classList.remove(
                'sidebar-open'
            );
        }


        if (mobileButton) {

            mobileButton.addEventListener(
                'click',
                openSidebar
            );
        }


        if (closeButton) {

            closeButton.addEventListener(
                'click',
                closeSidebar
            );
        }


        if (overlay) {

            overlay.addEventListener(
                'click',
                closeSidebar
            );
        }

    }
);

</script>


</body>

</html>
