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


/*
|--------------------------------------------------------------------------
| CEK TABLE
|--------------------------------------------------------------------------
|
| JANGAN menggunakan:
|
| SHOW TABLES LIKE ?
|
| Karena MariaDB/MySQL tidak menerima placeholder
| pada statement SHOW TABLES seperti ini.
|
*/

function tableExists(mysqli $conn, string $table): bool
{
    /*
    |--------------------------------------------------------------------------
    | Validasi nama tabel
    |--------------------------------------------------------------------------
    |
    | Nama tabel harus hanya mengandung:
    | huruf, angka, dan underscore.
    |
    */

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Escape nama tabel
    |--------------------------------------------------------------------------
    */

    $safeTable =
        mysqli_real_escape_string(
            $conn,
            $table
        );


    /*
    |--------------------------------------------------------------------------
    | Query
    |--------------------------------------------------------------------------
    */

    $sql =
        "SHOW TABLES LIKE '{$safeTable}'";


    $result =
        mysqli_query(
            $conn,
            $sql
        );


    if (!$result) {
        return false;
    }


    $exists =
        mysqli_num_rows($result) > 0;


    mysqli_free_result($result);


    return $exists;
}


/*
|--------------------------------------------------------------------------
| SESSION USER
|--------------------------------------------------------------------------
*/

$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;


if ($userId <= 0) {

    header(
        "Location: ../login.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER DEFAULT
|--------------------------------------------------------------------------
*/

$customerName =
    $_SESSION['nama']
    ?? $_SESSION['name']
    ?? $_SESSION['username']
    ?? 'Pelanggan';


$customerEmail =
    $_SESSION['email']
    ?? '';


$customerId = 0;

$customerStatus = '';

$paketName =
    'Belum ada paket';

$paketSpeed = 0;


/*
|--------------------------------------------------------------------------
| CEK TABLE customers
|--------------------------------------------------------------------------
*/

if (
    !tableExists(
        $conn,
        'customers'
    )
) {

    die('
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Error Database</title>
            <style>
                body {
                    margin: 0;
                    padding: 40px;
                    font-family: Arial, sans-serif;
                    background: #f8fafc;
                    color: #1e293b;
                }

                .error-box {
                    max-width: 700px;
                    margin: 50px auto;
                    padding: 30px;
                    background: white;
                    border-radius: 16px;
                    box-shadow:
                        0 10px 30px rgba(0,0,0,.08);
                    border-left: 5px solid #dc2626;
                }

                h2 {
                    margin-top: 0;
                    color: #dc2626;
                }

                code {
                    padding: 3px 7px;
                    background: #f1f5f9;
                    border-radius: 5px;
                }
            </style>
        </head>

        <body>

            <div class="error-box">

                <h2>
                    Tabel customers tidak ditemukan
                </h2>

                <p>
                    Sistem membutuhkan tabel
                    <code>customers</code>
                    untuk mengambil data pelanggan.
                </p>

                <p>
                    Silakan periksa database
                    <code>wifi_management</code>.
                </p>

            </div>

        </body>
        </html>
    ');

    exit;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

$sqlCustomer = "
    SELECT
        c.id,
        c.nama,
        c.email,
        c.status_langganan,
        c.paket_id,
        p.nama_paket,
        p.speed_mbps

    FROM customers c

    LEFT JOIN paket_wifi p
        ON p.id = c.paket_id

    WHERE c.user_id = ?

    LIMIT 1
";


$stmtCustomer =
    mysqli_prepare(
        $conn,
        $sqlCustomer
    );


mysqli_stmt_bind_param(
    $stmtCustomer,
    "i",
    $userId
);


mysqli_stmt_execute(
    $stmtCustomer
);


$resultCustomer =
    mysqli_stmt_get_result(
        $stmtCustomer
    );


$customer =
    mysqli_fetch_assoc(
        $resultCustomer
    );


mysqli_stmt_close(
    $stmtCustomer
);


/*
|--------------------------------------------------------------------------
| CUSTOMER NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$customer) {

    session_destroy();

    header(
        "Location: ../login.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| SET CUSTOMER DATA
|--------------------------------------------------------------------------
*/

$customerId =
    (int) $customer['id'];


if (!empty($customer['nama'])) {

    $customerName =
        $customer['nama'];
}


if (!empty($customer['email'])) {

    $customerEmail =
        $customer['email'];
}


$customerStatus =
    $customer['status_langganan']
    ?? '';


if (!empty($customer['nama_paket'])) {

    $paketName =
        $customer['nama_paket'];
}


if (
    isset($customer['speed_mbps']) &&
    $customer['speed_mbps'] !== null
) {

    $paketSpeed =
        (float)
        $customer['speed_mbps'];
}


/*
|--------------------------------------------------------------------------
| DATE RANGE
|--------------------------------------------------------------------------
*/

$today =
    date('Y-m-d');


$sevenDaysAgo =
    date(
        'Y-m-d',
        strtotime('-6 days')
    );


$startDateTime =
    $sevenDaysAgo .
    ' 00:00:00';


/*
|--------------------------------------------------------------------------
| DEFAULT SUMMARY
|--------------------------------------------------------------------------
*/

$avgDownload = 0;

$avgUpload = 0;

$avgPing = 0;

$maxDownload = 0;

$maxUpload = 0;

$totalRecords = 0;


/*
|--------------------------------------------------------------------------
| DEFAULT DAILY DATA
|--------------------------------------------------------------------------
*/

$usageData = [];


for (
    $i = 6;
    $i >= 0;
    $i--
) {

    $date =
        date(
            'Y-m-d',
            strtotime(
                "-{$i} days"
            )
        );


    $usageData[$date] = [

        'download' => 0,

        'upload' => 0,

        'ping' => 0,

        'count' => 0
    ];
}


/*
|--------------------------------------------------------------------------
| DEFAULT HISTORY
|--------------------------------------------------------------------------
*/

$usageHistory = [];


/*
|--------------------------------------------------------------------------
| CEK usage_data
|--------------------------------------------------------------------------
*/

$hasUsageTable =
    tableExists(
        $conn,
        'usage_data'
    );


/*
|--------------------------------------------------------------------------
| DATA ASLI DARI usage_data
|--------------------------------------------------------------------------
*/

if ($hasUsageTable) {


    /*
    |--------------------------------------------------------------------------
    | SUMMARY
    |--------------------------------------------------------------------------
    */

    $sqlSummary = "

        SELECT

            COALESCE(
                AVG(download_mbps),
                0
            ) AS avg_download,

            COALESCE(
                AVG(upload_mbps),
                0
            ) AS avg_upload,

            COALESCE(
                AVG(ping_ms),
                0
            ) AS avg_ping,

            COALESCE(
                MAX(download_mbps),
                0
            ) AS max_download,

            COALESCE(
                MAX(upload_mbps),
                0
            ) AS max_upload,

            COUNT(*) AS total_records

        FROM usage_data

        WHERE customer_id = ?

        AND recorded_at >= ?

        AND recorded_at < DATE_ADD(
            ?,
            INTERVAL 1 DAY
        )
    ";


    $stmtSummary =
        mysqli_prepare(
            $conn,
            $sqlSummary
        );


    mysqli_stmt_bind_param(
        $stmtSummary,
        "iss",
        $customerId,
        $startDateTime,
        $today
    );


    mysqli_stmt_execute(
        $stmtSummary
    );


    $resultSummary =
        mysqli_stmt_get_result(
            $stmtSummary
        );


    $rowSummary =
        mysqli_fetch_assoc(
            $resultSummary
        );


    if ($rowSummary) {

        $avgDownload =
            (float)
            (
                $rowSummary[
                    'avg_download'
                ] ?? 0
            );


        $avgUpload =
            (float)
            (
                $rowSummary[
                    'avg_upload'
                ] ?? 0
            );


        $avgPing =
            (float)
            (
                $rowSummary[
                    'avg_ping'
                ] ?? 0
            );


        $maxDownload =
            (float)
            (
                $rowSummary[
                    'max_download'
                ] ?? 0
            );


        $maxUpload =
            (float)
            (
                $rowSummary[
                    'max_upload'
                ] ?? 0
            );


        $totalRecords =
            (int)
            (
                $rowSummary[
                    'total_records'
                ] ?? 0
            );
    }


    mysqli_stmt_close(
        $stmtSummary
    );


    /*
    |--------------------------------------------------------------------------
    | CHART
    |--------------------------------------------------------------------------
    */

    $sqlChart = "

        SELECT

            DATE(recorded_at)
                AS tanggal,

            COALESCE(
                AVG(download_mbps),
                0
            ) AS download_mbps,

            COALESCE(
                AVG(upload_mbps),
                0
            ) AS upload_mbps,

            COALESCE(
                AVG(ping_ms),
                0
            ) AS ping_ms,

            COUNT(*) AS total_data

        FROM usage_data

        WHERE customer_id = ?

        AND recorded_at >= ?

        AND recorded_at < DATE_ADD(
            ?,
            INTERVAL 1 DAY
        )

        GROUP BY
            DATE(recorded_at)

        ORDER BY
            tanggal ASC
    ";


    $stmtChart =
        mysqli_prepare(
            $conn,
            $sqlChart
        );


    mysqli_stmt_bind_param(
        $stmtChart,
        "iss",
        $customerId,
        $startDateTime,
        $today
    );


    mysqli_stmt_execute(
        $stmtChart
    );


    $resultChart =
        mysqli_stmt_get_result(
            $stmtChart
        );


    while (
        $row =
        mysqli_fetch_assoc(
            $resultChart
        )
    ) {

        $tanggal =
            $row['tanggal'];


        if (
            isset(
                $usageData[$tanggal]
            )
        ) {

            $usageData[$tanggal] = [

                'download' =>
                    (float)
                    (
                        $row[
                            'download_mbps'
                        ] ?? 0
                    ),

                'upload' =>
                    (float)
                    (
                        $row[
                            'upload_mbps'
                        ] ?? 0
                    ),

                'ping' =>
                    (float)
                    (
                        $row[
                            'ping_ms'
                        ] ?? 0
                    ),

                'count' =>
                    (int)
                    (
                        $row[
                            'total_data'
                        ] ?? 0
                    )
            ];
        }
    }


    mysqli_stmt_close(
        $stmtChart
    );


    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */

    $sqlHistory = "

        SELECT

            id,

            download_mbps,

            upload_mbps,

            ping_ms,

            recorded_at

        FROM usage_data

        WHERE customer_id = ?

        ORDER BY
            recorded_at DESC

        LIMIT 10
    ";


    $stmtHistory =
        mysqli_prepare(
            $conn,
            $sqlHistory
        );


    mysqli_stmt_bind_param(
        $stmtHistory,
        "i",
        $customerId
    );


    mysqli_stmt_execute(
        $stmtHistory
    );


    $resultHistory =
        mysqli_stmt_get_result(
            $stmtHistory
        );


    while (
        $row =
        mysqli_fetch_assoc(
            $resultHistory
        )
    ) {

        $usageHistory[] =
            $row;
    }


    mysqli_stmt_close(
        $stmtHistory
    );
}


/*
|--------------------------------------------------------------------------
| DUMMY DATA
|--------------------------------------------------------------------------
|
| Dipakai jika:
|
| 1. usage_data belum ada
| 2. usage_data ada tetapi belum mempunyai data
|
*/

if (
    !$hasUsageTable ||
    $totalRecords <= 0
) {


    /*
    |--------------------------------------------------------------------------
    | BASE SPEED
    |--------------------------------------------------------------------------
    */

    $baseSpeed =
        $paketSpeed > 0
            ? $paketSpeed
            : 50;


    /*
    |--------------------------------------------------------------------------
    | DUMMY HISTORY
    |--------------------------------------------------------------------------
    */

    $dummyHistory = [];


    /*
    |--------------------------------------------------------------------------
    | DUMMY 7 HARI
    |--------------------------------------------------------------------------
    */

    $dummyValues = [

        6 => [
            'download' => 0.82,
            'upload'   => 0.36,
            'ping'     => 9
        ],

        5 => [
            'download' => 0.91,
            'upload'   => 0.40,
            'ping'     => 10
        ],

        4 => [
            'download' => 0.87,
            'upload'   => 0.38,
            'ping'     => 8
        ],

        3 => [
            'download' => 0.95,
            'upload'   => 0.43,
            'ping'     => 11
        ],

        2 => [
            'download' => 0.89,
            'upload'   => 0.39,
            'ping'     => 9
        ],

        1 => [
            'download' => 0.97,
            'upload'   => 0.44,
            'ping'     => 8
        ],

        0 => [
            'download' => 0.93,
            'upload'   => 0.41,
            'ping'     => 9
        ]
    ];


    /*
    |--------------------------------------------------------------------------
    | BUAT DATA GRAFIK
    |--------------------------------------------------------------------------
    */

    for (
        $i = 6;
        $i >= 0;
        $i--
    ) {

        $date =
            date(
                'Y-m-d',
                strtotime(
                    "-{$i} days"
                )
            );


        $factor =
            $dummyValues[$i];


        $download =
            round(
                $baseSpeed *
                $factor['download'],
                2
            );


        $upload =
            round(
                max(
                    1,
                    $baseSpeed *
                    0.45 *
                    $factor['upload']
                ),
                2
            );


        $ping =
            (float)
            $factor['ping'];


        $usageData[$date] = [

            'download' =>
                $download,

            'upload' =>
                $upload,

            'ping' =>
                $ping,

            'count' =>
                1
        ];


        $dummyHistory[] = [

            'id' =>
                9000 + $i,

            'download_mbps' =>
                $download,

            'upload_mbps' =>
                $upload,

            'ping_ms' =>
                $ping,

            'recorded_at' =>
                $date .
                ' ' .
                str_pad(
                    (string)
                    (20 + $i),
                    2,
                    '0',
                    STR_PAD_LEFT
                ) .
                ':00:00'
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | HITUNG AVERAGE
    |--------------------------------------------------------------------------
    */

    $downloadValues = [];

    $uploadValues = [];

    $pingValues = [];


    foreach (
        $usageData
        as $data
    ) {

        $downloadValues[] =
            (float)
            $data['download'];


        $uploadValues[] =
            (float)
            $data['upload'];


        $pingValues[] =
            (float)
            $data['ping'];
    }


    if (
        !empty(
            $downloadValues
        )
    ) {

        $avgDownload =
            array_sum(
                $downloadValues
            )
            /
            count(
                $downloadValues
            );


        $maxDownload =
            max(
                $downloadValues
            );
    }


    if (
        !empty(
            $uploadValues
        )
    ) {

        $avgUpload =
            array_sum(
                $uploadValues
            )
            /
            count(
                $uploadValues
            );


        $maxUpload =
            max(
                $uploadValues
            );
    }


    if (
        !empty(
            $pingValues
        )
    ) {

        $avgPing =
            array_sum(
                $pingValues
            )
            /
            count(
                $pingValues
            );
    }


    $totalRecords = 7;


    /*
    |--------------------------------------------------------------------------
    | HISTORY DUMMY
    |--------------------------------------------------------------------------
    */

    if (
        empty(
            $usageHistory
        )
    ) {

        $usageHistory =
            $dummyHistory;
    }
}


/*
|--------------------------------------------------------------------------
| GRAPH SCALE
|--------------------------------------------------------------------------
*/

$maxUsage =
    max(
        $maxDownload,
        $maxUpload,
        1
    );


$chartMax =
    ceil(
        $maxUsage * 1.15
    );


if (
    $chartMax < 10
) {

    $chartMax = 10;
}


/*
|--------------------------------------------------------------------------
| SVG POINTS
|--------------------------------------------------------------------------
*/

$downloadPoints = [];

$uploadPoints = [];


$chartCount =
    count(
        $usageData
    );


$index = 0;


foreach (
    $usageData
    as $tanggal => $data
) {

    $x =
        $chartCount > 1
            ? (
                $index /
                ($chartCount - 1)
            ) * 100
            : 50;


    $download =
        (float)
        $data['download'];


    $upload =
        (float)
        $data['upload'];


    $downloadY =
        100 -
        (
            min(
                $download,
                $chartMax
            )
            /
            $chartMax
        ) * 100;


    $uploadY =
        100 -
        (
            min(
                $upload,
                $chartMax
            )
            /
            $chartMax
        ) * 100;


    $downloadPoints[] = [

        'x' =>
            $x,

        'y' =>
            $downloadY,

        'value' =>
            $download,

        'date' =>
            $tanggal
    ];


    $uploadPoints[] = [

        'x' =>
            $x,

        'y' =>
            $uploadY,

        'value' =>
            $upload,

        'date' =>
            $tanggal
    ];


    $index++;
}


/*
|--------------------------------------------------------------------------
| SVG POINT FUNCTION
|--------------------------------------------------------------------------
*/

function svgPoints(
    array $points
): string {

    $result = [];


    foreach (
        $points
        as $point
    ) {

        $result[] =
            number_format(
                $point['x'],
                2,
                '.',
                ''
            )
            .
            ','
            .
            number_format(
                $point['y'],
                2,
                '.',
                ''
            );
    }


    return implode(
        ' ',
        $result
    );
}


$downloadPolyline =
    svgPoints(
        $downloadPoints
    );


$uploadPolyline =
    svgPoints(
        $uploadPoints
    );


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusLabel =
    'Pelanggan';


if (
    in_array(
        $customerStatus,
        [
            'active',
            'aktif'
        ],
        true
    )
) {

    $statusLabel =
        'Pelanggan Aktif';

} elseif (
    in_array(
        $customerStatus,
        [
            'pending',
            'proses',
            'menunggu_pemasangan',
            'menunggu pemasangan'
        ],
        true
    )
) {

    $statusLabel =
        'Menunggu Aktivasi';

} elseif (
    in_array(
        $customerStatus,
        [
            'suspended',
            'ditangguhkan'
        ],
        true
    )
) {

    $statusLabel =
        'Layanan Ditangguhkan';

} elseif (
    in_array(
        $customerStatus,
        [
            'terminated',
            'berakhir',
            'nonaktif'
        ],
        true
    )
) {

    $statusLabel =
        'Layanan Berakhir';
}


/*
|--------------------------------------------------------------------------
| CONNECTION
|--------------------------------------------------------------------------
*/

$isOnline =
    $totalRecords > 0;


/*
|--------------------------------------------------------------------------
| LAST UPDATE
|--------------------------------------------------------------------------
*/

$lastUpdate = null;


if (
    !empty(
        $usageHistory
    )
) {

    $lastUpdate =
        $usageHistory[0][
            'recorded_at'
        ];
}


/*
|--------------------------------------------------------------------------
| AVATAR
|--------------------------------------------------------------------------
*/

$avatarInitial =
    strtoupper(
        substr(
            trim(
                $customerName
            ),
            0,
            1
        )
    );


if (
    $avatarInitial === ''
) {

    $avatarInitial = 'P';
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
    Pemakaian Internet - WiFi Management
</title>


<link
    rel="stylesheet"
    href="assets/css/customer-dashboard.css"
>


<link
    rel="stylesheet"
    href="assets/css/usage.css?v=5"
>


<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

</head>


<body>


<div class="dashboard-container">


<!-- =====================================================
     SIDEBAR
====================================================== -->

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



    <nav class="sidebar-menu">


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
            class="menu-item"
        >

            <i class="bi bi-credit-card-fill"></i>

            <span>
                Tagihan
            </span>

        </a>



        <a
            href="usage.php"
            class="menu-item active"
        >

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Pemakaian
            </span>

        </a>



        <a
            href="speedtest.php"
            class="menu-item"
        >

            <i class="bi bi-speedometer2"></i>

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
                Pengaduan
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
            href="notifikasi.php"
            class="menu-item"
        >

            <i class="bi bi-bell-fill"></i>

            <span>
                Notifikasi
            </span>

        </a>



        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profil
            </span>

        </a>


    </nav>



    <div class="sidebar-bottom">


        <div class="user-mini">


            <div class="user-avatar">

                <?= e(
                    $avatarInitial
                ) ?>

            </div>


            <div>

                <strong>
                    <?= e(
                        $customerName
                    ) ?>
                </strong>


                <small>
                    <?= e(
                        $statusLabel
                    ) ?>
                </small>

            </div>


        </div>


    </div>


</aside>



<!-- =====================================================
     MAIN
====================================================== -->

<main class="main-content">


<header class="topbar">


    <div>

        <h4>
            Pemakaian Internet
        </h4>


        <span>
            Pantau performa internet kamu selama 7 hari terakhir.
        </span>

    </div>



    <div class="topbar-right">


        <a
            href="notifikasi.php"
            class="notification"
            aria-label="Notifikasi"
        >

            <i class="bi bi-bell"></i>

        </a>



        <a
            href="profile.php"
            class="top-profile"
        >


            <div class="top-avatar">

                <?= e(
                    $avatarInitial
                ) ?>

            </div>


            <div class="top-user">

                <strong>
                    <?= e(
                        $customerName
                    ) ?>
                </strong>


                <span>
                    Customer
                </span>

            </div>


        </a>


    </div>


</header>



<div class="usage-page">


<!-- =====================================================
     SUMMARY
====================================================== -->

<section class="summary-grid">


    <div class="summary-card download">


        <div class="summary-icon">

            <i
                class="bi bi-arrow-down-circle-fill"
            ></i>

        </div>


        <div class="summary-info">


            <span>
                Rata-rata Download
            </span>


            <strong>

                <?= number_format(
                    $avgDownload,
                    2,
                    ',',
                    '.'
                ) ?>

                <small>
                    Mbps
                </small>

            </strong>


            <em>
                7 hari terakhir
            </em>


        </div>


    </div>



    <div class="summary-card upload">


        <div class="summary-icon">

            <i
                class="bi bi-arrow-up-circle-fill"
            ></i>

        </div>


        <div class="summary-info">


            <span>
                Rata-rata Upload
            </span>


            <strong>

                <?= number_format(
                    $avgUpload,
                    2,
                    ',',
                    '.'
                ) ?>

                <small>
                    Mbps
                </small>

            </strong>


            <em>
                7 hari terakhir
            </em>


        </div>


    </div>



    <div class="summary-card total">


        <div class="summary-icon">

            <i
                class="bi bi-speedometer2"
            ></i>

        </div>


        <div class="summary-info">


            <span>
                Rata-rata Ping
            </span>


            <strong>

                <?= number_format(
                    $avgPing,
                    1,
                    ',',
                    '.'
                ) ?>

                <small>
                    ms
                </small>

            </strong>


            <em>

                <?= e(
                    $totalRecords
                ) ?>

                pengukuran

            </em>


        </div>


    </div>


</section>



<!-- =====================================================
     PACKAGE
====================================================== -->

<section class="content-card quota-card">


    <div class="card-header">


        <div>


            <span class="card-kicker">
                PAKET INTERNET
            </span>


            <h2>
                <?= e(
                    $paketName
                ) ?>
            </h2>


            <p>
                Paket internet yang sedang digunakan.
            </p>


        </div>



        <div class="package-speed">


            <strong>

                <?= $paketSpeed > 0

                    ? number_format(
                        $paketSpeed,
                        0,
                        ',',
                        '.'
                    )

                    : '—'
                ?>

            </strong>


            <span>
                Mbps
            </span>


        </div>


    </div>


</section>



<!-- =====================================================
     MONITORING
====================================================== -->

<section class="content-card bandwidth-card">


    <div class="bandwidth-heading">


        <div>


            <span class="card-kicker">
                MONITORING KONEKSI
            </span>


            <h2>
                Performa Internet
            </h2>


            <p>
                Kecepatan rata-rata koneksi selama 7 hari terakhir.
            </p>


        </div>



        <div
            class="connection-status
            <?= $isOnline
                ? 'online'
                : 'offline'
            ?>"
        >


            <span></span>


            <?= $isOnline
                ? 'Koneksi Aktif'
                : 'Belum Ada Data'
            ?>


        </div>


    </div>



    <!-- MINI SUMMARY -->

    <div class="chart-summary">


        <div>


            <span>
                Download
            </span>


            <strong>

                <?= number_format(
                    $avgDownload,
                    1,
                    ',',
                    '.'
                ) ?>


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
                    $avgUpload,
                    1,
                    ',',
                    '.'
                ) ?>


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

                <?= number_format(
                    $avgPing,
                    1,
                    ',',
                    '.'
                ) ?>


                <small>
                    ms
                </small>


            </strong>


        </div>



        <div class="package-limit">


            <span>
                Paket
            </span>


            <strong>

                <?= $paketSpeed > 0

                    ? number_format(
                        $paketSpeed,
                        0,
                        ',',
                        '.'
                    )

                    : '—'
                ?>


                <small>
                    Mbps
                </small>


            </strong>


        </div>


    </div>



    <!-- GRAPH -->

    <div class="modern-chart">


        <div class="chart-y-labels">


            <span>
                <?= number_format(
                    $chartMax,
                    0,
                    ',',
                    '.'
                ) ?>
            </span>


            <span>
                <?= number_format(
                    $chartMax * .75,
                    0,
                    ',',
                    '.'
                ) ?>
            </span>


            <span>
                <?= number_format(
                    $chartMax * .50,
                    0,
                    ',',
                    '.'
                ) ?>
            </span>


            <span>
                <?= number_format(
                    $chartMax * .25,
                    0,
                    ',',
                    '.'
                ) ?>
            </span>


            <span>
                0
            </span>


        </div>



        <div class="chart-stage">


            <div class="chart-grid">

                <span></span>

                <span></span>

                <span></span>

                <span></span>

                <span></span>

            </div>



            <svg
                class="usage-svg"
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                aria-label="Grafik kecepatan internet"
            >


                <defs>


                    <linearGradient
                        id="downloadGradient"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >

                        <stop
                            offset="0%"
                            stop-color="#2563eb"
                            stop-opacity=".28"
                        />


                        <stop
                            offset="100%"
                            stop-color="#2563eb"
                            stop-opacity="0"
                        />

                    </linearGradient>



                    <linearGradient
                        id="uploadGradient"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >

                        <stop
                            offset="0%"
                            stop-color="#8b5cf6"
                            stop-opacity=".20"
                        />


                        <stop
                            offset="100%"
                            stop-color="#8b5cf6"
                            stop-opacity="0"
                        />

                    </linearGradient>


                </defs>



                <polygon
                    class="download-area"
                    fill="url(#downloadGradient)"
                    points="
                        0,100
                        <?= e(
                            $downloadPolyline
                        ) ?>
                        100,100
                    "
                />



                <polygon
                    class="upload-area"
                    fill="url(#uploadGradient)"
                    points="
                        0,100
                        <?= e(
                            $uploadPolyline
                        ) ?>
                        100,100
                    "
                />



                <polyline
                    class="download-line"
                    points="<?= e(
                        $downloadPolyline
                    ) ?>"
                />



                <polyline
                    class="upload-line"
                    points="<?= e(
                        $uploadPolyline
                    ) ?>"
                />



                <?php foreach (
                    $downloadPoints
                    as $point
                ): ?>


                    <circle
                        class="download-point"
                        cx="<?= e(
                            $point['x']
                        ) ?>"
                        cy="<?= e(
                            $point['y']
                        ) ?>"
                        r="1.25"
                    >

                        <title>

                            <?= e(
                                date(
                                    'd M',
                                    strtotime(
                                        $point['date']
                                    )
                                )
                            ) ?>


                            • Download:


                            <?= number_format(
                                $point['value'],
                                2,
                                ',',
                                '.'
                            ) ?>


                            Mbps

                        </title>

                    </circle>


                <?php endforeach; ?>



                <?php foreach (
                    $uploadPoints
                    as $point
                ): ?>


                    <circle
                        class="upload-point"
                        cx="<?= e(
                            $point['x']
                        ) ?>"
                        cy="<?= e(
                            $point['y']
                        ) ?>"
                        r="1.25"
                    >

                        <title>

                            <?= e(
                                date(
                                    'd M',
                                    strtotime(
                                        $point['date']
                                    )
                                )
                            ) ?>


                            • Upload:


                            <?= number_format(
                                $point['value'],
                                2,
                                ',',
                                '.'
                            ) ?>


                            Mbps

                        </title>

                    </circle>


                <?php endforeach; ?>


            </svg>



            <div class="chart-x-axis">


                <?php foreach (
                    $usageData
                    as $tanggal => $data
                ): ?>


                    <span>

                        <?= e(
                            date(
                                'd M',
                                strtotime(
                                    $tanggal
                                )
                            )
                        ) ?>

                    </span>


                <?php endforeach; ?>


            </div>


        </div>


    </div>



    <!-- LEGEND -->

    <div class="chart-bottom">


        <div class="chart-legend">


            <span>

                <i class="legend-download"></i>

                Download

            </span>


            <span>

                <i class="legend-upload"></i>

                Upload

            </span>


        </div>



        <span class="chart-period">

            <i class="bi bi-calendar3"></i>

            7 hari terakhir

        </span>


    </div>


</section>



<!-- =====================================================
     INFO
====================================================== -->

<section class="info-grid">


    <div class="info-card">


        <div class="info-icon">

            <i
                class="bi bi-speedometer2"
            ></i>

        </div>


        <div>

            <span>
                Paket Internet
            </span>


            <strong>
                <?= e(
                    $paketName
                ) ?>
            </strong>

        </div>


    </div>



    <div class="info-card">


        <div class="info-icon">

            <i class="bi bi-wifi"></i>

        </div>


        <div>

            <span>
                Status Koneksi
            </span>


            <strong
                class="<?= $isOnline
                    ? 'online'
                    : ''
                ?>"
            >

                <?= $isOnline
                    ? 'Online'
                    : 'Belum Ada Data'
                ?>

            </strong>

        </div>


    </div>



    <div class="info-card">


        <div class="info-icon">

            <i
                class="bi bi-clock-history"
            ></i>

        </div>


        <div>

            <span>
                Update Data
            </span>


            <strong>


                <?php if (
                    $lastUpdate
                ): ?>


                    <?= e(
                        date(
                            'd M Y H:i',
                            strtotime(
                                $lastUpdate
                            )
                        )
                    ) ?>


                <?php else: ?>


                    Belum ada data


                <?php endif; ?>


            </strong>


        </div>


    </div>


</section>



<!-- =====================================================
     HISTORY
====================================================== -->

<section class="content-card history-card">


    <div class="card-header">


        <div>


            <span class="card-kicker">
                DATA PENGUKURAN
            </span>


            <h2>
                Riwayat Pemakaian
            </h2>


            <p>
                Sepuluh hasil pengukuran koneksi terbaru.
            </p>


        </div>


    </div>



    <div class="table-wrapper">


        <table>


            <thead>


                <tr>


                    <th>
                        Waktu
                    </th>


                    <th>
                        Download
                    </th>


                    <th>
                        Upload
                    </th>


                    <th>
                        Ping
                    </th>


                </tr>


            </thead>



            <tbody>


            <?php if (
                !empty(
                    $usageHistory
                )
            ): ?>


                <?php foreach (
                    $usageHistory
                    as $history
                ): ?>


                    <tr>


                        <td>

                            <?= e(
                                date(
                                    'd M Y H:i',
                                    strtotime(
                                        $history[
                                            'recorded_at'
                                        ]
                                    )
                                )
                            ) ?>

                        </td>



                        <td>

                            <span
                                class="download-text"
                            >

                                <i
                                    class="bi bi-arrow-down"
                                ></i>


                                <?= number_format(
                                    (float)
                                    $history[
                                        'download_mbps'
                                    ],
                                    2,
                                    ',',
                                    '.'
                                ) ?>


                                Mbps

                            </span>

                        </td>



                        <td>

                            <span
                                class="upload-text"
                            >

                                <i
                                    class="bi bi-arrow-up"
                                ></i>


                                <?= number_format(
                                    (float)
                                    $history[
                                        'upload_mbps'
                                    ],
                                    2,
                                    ',',
                                    '.'
                                ) ?>


                                Mbps

                            </span>

                        </td>



                        <td>


                            <strong>

                                <?= number_format(
                                    (float)
                                    $history[
                                        'ping_ms'
                                    ],
                                    1,
                                    ',',
                                    '.'
                                ) ?>


                                ms

                            </strong>


                        </td>


                    </tr>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>


                    <td
                        colspan="4"
                        class="empty-state"
                    >


                        <i
                            class="bi bi-graph-up"
                        ></i>


                        <span>
                            Belum ada data pemakaian.
                        </span>


                    </td>


                </tr>


            <?php endif; ?>


            </tbody>


        </table>


    </div>


</section>



<!-- =====================================================
     NOTE
====================================================== -->

<div class="usage-note">


    <i
        class="bi bi-info-circle"
    ></i>


    <span>

        Data pemakaian berasal dari hasil
        pengukuran koneksi yang tersimpan
        pada sistem.


        <?php if (
            !$hasUsageTable
        ): ?>


            Saat ini halaman menggunakan
            <strong>
                data simulasi
            </strong>
            karena tabel
            <strong>
                usage_data
            </strong>
            belum tersedia.


        <?php endif; ?>


    </span>


</div>


</div>

</main>

</div>


</body>

</html>
