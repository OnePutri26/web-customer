<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];


/* =====================================================
   DATA CUSTOMER
===================================================== */

$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$customer = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);


/* =====================================================
   DATA PAKET
===================================================== */

$paketNama =
    $customer['paket'] ??
    $customer['nama_paket'] ??
    'Internet Home';

$paketSpeed =
    $customer['speed'] ??
    $customer['kecepatan'] ??
    '100 Mbps';


/* =====================================================
   CUSTOMER ID
===================================================== */

$customerId =
    $customer['id'] ?? '-';


/* =====================================================
   SERVER INFO
===================================================== */

$serverName =
    $_SERVER['SERVER_NAME'] ??
    'Local Server';

$serverIp =
    $_SERVER['SERVER_ADDR'] ??
    '127.0.0.1';

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
        Speed Test - Customer Portal
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


    <!-- Customer Dashboard CSS -->

    <link
        rel="stylesheet"
        href="assets/css/customer-dashboard.css"
    >


    <!-- Speedtest CSS -->

    <link
        rel="stylesheet"
        href="assets/css/speedtest.css"
    >

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

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
            class="menu-item"
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
            class="menu-item active"
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



<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<main class="main-content">


    <!-- =================================================
         TOPBAR
    ================================================== -->

    <header class="topbar">

        <div>

            <h4>
                Speed Test
            </h4>

            <span>
                Periksa kecepatan koneksi internet kamu
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



    <!-- =================================================
         CONTENT
    ================================================== -->

    <div class="content">


        <!-- PAGE HEADER -->

        <div class="speed-page-header">

            <span class="speed-label">
                INTERNET TOOLS
            </span>

            <h2>
                Speed Test
            </h2>

            <p>
                Uji kecepatan internet untuk mengetahui kualitas
                koneksi kamu saat ini.
            </p>

        </div>



        <!-- =================================================
             SPEEDTEST
        ================================================== -->

        <div class="speedtest-main-card">


            <!-- SPEEDOMETER -->

            <div class="speedometer-wrapper">

                <div
                    class="speedometer"
                    id="speedometer"
                >

                    <div class="speedometer-inner">

                        <span
                            class="speed-status"
                            id="testStatus"
                        >
                            SIAP
                        </span>


                        <div class="speed-value">

                            <strong id="speedValue">
                                0
                            </strong>

                            <span>
                                Mbps
                            </span>

                        </div>


                        <small id="testDescription">
                            Tekan tombol untuk memulai
                        </small>

                    </div>

                </div>

            </div>



            <!-- START BUTTON -->

            <button
                type="button"
                id="startTest"
                class="start-test-btn"
            >

                <i class="bi bi-lightning-charge-fill"></i>

                <span id="startText">
                    Mulai Speed Test
                </span>

            </button>



            <!-- PROGRESS -->

            <div class="speed-progress">

                <div class="progress-track">

                    <div
                        class="progress-bar"
                        id="progressBar"
                    ></div>

                </div>


                <div class="progress-info">

                    <span id="progressText">
                        0%
                    </span>

                    <span id="phaseText">
                        Menunggu
                    </span>

                </div>

            </div>

        </div>



        <!-- =================================================
             RESULT
        ================================================== -->

        <div class="speed-result-grid">


            <!-- PING -->

            <div class="speed-result-card">

                <div class="result-icon ping">

                    <i class="bi bi-broadcast-pin"></i>

                </div>


                <div>

                    <span>
                        Ping
                    </span>


                    <div class="result-number">

                        <strong id="pingValue">
                            --
                        </strong>

                        <small>
                            ms
                        </small>

                    </div>


                    <p id="pingStatus">
                        Belum diuji
                    </p>

                </div>

            </div>



            <!-- DOWNLOAD -->

            <div class="speed-result-card">

                <div class="result-icon download">

                    <i class="bi bi-download"></i>

                </div>


                <div>

                    <span>
                        Download
                    </span>


                    <div class="result-number">

                        <strong id="downloadValue">
                            --
                        </strong>

                        <small>
                            Mbps
                        </small>

                    </div>


                    <p id="downloadStatus">
                        Belum diuji
                    </p>

                </div>

            </div>



            <!-- UPLOAD -->

            <div class="speed-result-card">

                <div class="result-icon upload">

                    <i class="bi bi-upload"></i>

                </div>


                <div>

                    <span>
                        Upload
                    </span>


                    <div class="result-number">

                        <strong id="uploadValue">
                            --
                        </strong>

                        <small>
                            Mbps
                        </small>

                    </div>


                    <p id="uploadStatus">
                        Belum diuji
                    </p>

                </div>

            </div>


        </div>



        <!-- =================================================
             CONNECTION INFO
        ================================================== -->

        <div class="connection-card">


            <div class="connection-header">

                <div>

                    <h5>
                        Informasi Koneksi
                    </h5>

                    <p>
                        Informasi koneksi dan paket internet kamu.
                    </p>

                </div>


                <div class="online-status">

                    <span></span>

                    Online

                </div>

            </div>



            <div class="connection-grid">


                <div class="connection-item">

                    <span>

                        <i class="bi bi-person"></i>

                        Customer

                    </span>

                    <strong>
                        <?= htmlspecialchars($nama) ?>
                    </strong>

                </div>


                <div class="connection-item">

                    <span>

                        <i class="bi bi-router"></i>

                        Paket

                    </span>

                    <strong>
                        <?= htmlspecialchars($paketNama) ?>
                    </strong>

                </div>


                <div class="connection-item">

                    <span>

                        <i class="bi bi-speedometer2"></i>

                        Kecepatan Paket

                    </span>

                    <strong>
                        <?= htmlspecialchars($paketSpeed) ?>
                    </strong>

                </div>


                <div class="connection-item">

                    <span>

                        <i class="bi bi-hdd-network"></i>

                        Server

                    </span>

                    <strong>
                        <?= htmlspecialchars($serverName) ?>
                    </strong>

                </div>


                <div class="connection-item">

                    <span>

                        <i class="bi bi-globe2"></i>

                        Server IP

                    </span>

                    <strong>
                        <?= htmlspecialchars($serverIp) ?>
                    </strong>

                </div>


                <div class="connection-item">

                    <span>

                        <i class="bi bi-clock"></i>

                        Waktu Test

                    </span>

                    <strong id="testTime">
                        -
                    </strong>

                </div>


            </div>

        </div>



        <!-- =================================================
             TIPS
        ================================================== -->

        <div class="speed-tips">

            <div class="tips-icon">

                <i class="bi bi-lightbulb-fill"></i>

            </div>


            <div>

                <strong>
                    Tips mendapatkan hasil yang akurat
                </strong>

                <p>
                    Pastikan tidak ada download, streaming,
                    atau perangkat lain yang sedang menggunakan
                    koneksi secara berat ketika melakukan speed test.
                </p>

            </div>

        </div>


    </div>

</main>



<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/* =====================================================
   ELEMENT
===================================================== */

const startButton =
    document.getElementById("startTest");

const startText =
    document.getElementById("startText");

const speedValue =
    document.getElementById("speedValue");

const speedometer =
    document.getElementById("speedometer");

const testStatus =
    document.getElementById("testStatus");

const testDescription =
    document.getElementById("testDescription");

const pingValue =
    document.getElementById("pingValue");

const downloadValue =
    document.getElementById("downloadValue");

const uploadValue =
    document.getElementById("uploadValue");

const pingStatus =
    document.getElementById("pingStatus");

const downloadStatus =
    document.getElementById("downloadStatus");

const uploadStatus =
    document.getElementById("uploadStatus");

const progressBar =
    document.getElementById("progressBar");

const progressText =
    document.getElementById("progressText");

const phaseText =
    document.getElementById("phaseText");

const testTime =
    document.getElementById("testTime");


/* =====================================================
   PROGRESS
===================================================== */

function setProgress(percent, phase)
{
    percent =
        Math.max(
            0,
            Math.min(100, percent)
        );

    progressBar.style.width =
        percent + "%";

    progressText.textContent =
        Math.round(percent) + "%";

    phaseText.textContent =
        phase;
}


/* =====================================================
   SPEEDOMETER
===================================================== */

function setSpeed(speed)
{
    speedValue.textContent =
        Number(speed).toFixed(2);

    /*
     * Maksimum visual speedometer
     * 100 Mbps = full circle
     */

    const maxSpeed = 100;

    const percentage =
        Math.min(
            Number(speed) / maxSpeed,
            1
        );

    const degree =
        percentage * 360;

    speedometer.style.background =
        `
        conic-gradient(
            #0d6efd ${degree}deg,
            #e9eef7 ${degree}deg
        )
        `;
}


/* =====================================================
   PING
===================================================== */

async function testPing()
{
    const samples = [];

    const url =
        window.location.href.split("?")[0]
        + "?ping=1&_="
        + Date.now();


    for (
        let i = 0;
        i < 4;
        i++
    ) {

        const start =
            performance.now();


        try {

            await fetch(
                url,
                {
                    method: "HEAD",
                    cache: "no-store"
                }
            );


            const end =
                performance.now();


            samples.push(
                end - start
            );


        } catch (error) {

            console.error(error);

        }

    }


    if (
        samples.length === 0
    ) {

        throw new Error(
            "Ping gagal"
        );

    }


    const average =
        samples.reduce(
            (a, b) => a + b,
            0
        ) / samples.length;


    return average;
}


/* =====================================================
   DOWNLOAD
===================================================== */

async function testDownload()
{
    const url =
        "speedtest-download.php?_="
        + Date.now();


    const start =
        performance.now();


    const response =
        await fetch(
            url,
            {
                cache: "no-store"
            }
        );


    if (!response.ok) {

        throw new Error(
            "Download test gagal"
        );

    }


    const blob =
        await response.blob();


    const end =
        performance.now();


    const seconds =
        (end - start) / 1000;


    const bits =
        blob.size * 8;


    const mbps =
        bits /
        seconds /
        1000000;


    return mbps;
}


/* =====================================================
   UPLOAD
===================================================== */

async function testUpload()
{
    /*
     * 5 MB data
     */

    const size =
        5 * 1024 * 1024;


    const data =
        new Uint8Array(size);


    const start =
        performance.now();


    const response =
        await fetch(
            "speedtest-upload.php?_="
            + Date.now(),
            {
                method: "POST",

                headers: {
                    "Content-Type":
                        "application/octet-stream"
                },

                body: data,

                cache: "no-store"
            }
        );


    if (!response.ok) {

        throw new Error(
            "Upload test gagal"
        );

    }


    await response.text();


    const end =
        performance.now();


    const seconds =
        (end - start) / 1000;


    const bits =
        size * 8;


    const mbps =
        bits /
        seconds /
        1000000;


    return mbps;
}


/* =====================================================
   START TEST
===================================================== */

startButton.addEventListener(
    "click",
    async function()
    {

        if (
            startButton.disabled
        ) {
            return;
        }


        startButton.disabled =
            true;


        startText.textContent =
            "Sedang Menguji...";


        testStatus.textContent =
            "TESTING";


        testDescription.textContent =
            "Mohon tunggu...";


        testTime.textContent =
            new Date().toLocaleTimeString(
                "id-ID"
            );


        /*
         * RESET
         */

        pingValue.textContent =
            "--";

        downloadValue.textContent =
            "--";

        uploadValue.textContent =
            "--";


        pingStatus.textContent =
            "Sedang menguji...";

        downloadStatus.textContent =
            "Menunggu...";

        uploadStatus.textContent =
            "Menunggu...";


        setSpeed(0);

        setProgress(
            0,
            "Memulai test"
        );


        try {

            /*
             * PING
             */

            setProgress(
                10,
                "Mengukur ping"
            );


            const ping =
                await testPing();


            pingValue.textContent =
                ping.toFixed(0);


            pingStatus.textContent =
                ping < 30
                    ? "Sangat baik"
                    : ping < 60
                        ? "Baik"
                        : ping < 100
                            ? "Cukup"
                            : "Tinggi";


            setProgress(
                30,
                "Ping selesai"
            );


            /*
             * DOWNLOAD
             */

            downloadStatus.textContent =
                "Sedang menguji...";


            setProgress(
                35,
                "Mengukur download"
            );


            const download =
                await testDownload();


            downloadValue.textContent =
                download.toFixed(2);


            setSpeed(
                download
            );


            downloadStatus.textContent =
                download >= 50
                    ? "Sangat cepat"
                    : download >= 20
                        ? "Cepat"
                        : download >= 10
                            ? "Cukup"
                            : "Lambat";


            setProgress(
                65,
                "Download selesai"
            );


            /*
             * UPLOAD
             */

            uploadStatus.textContent =
                "Sedang menguji...";


            setProgress(
                70,
                "Mengukur upload"
            );


            const upload =
                await testUpload();


            uploadValue.textContent =
                upload.toFixed(2);


            uploadStatus.textContent =
                upload >= 20
                    ? "Sangat baik"
                    : upload >= 10
                        ? "Baik"
                        : upload >= 5
                            ? "Cukup"
                            : "Lambat";


            setSpeed(
                upload
            );


            setProgress(
                100,
                "Test selesai"
            );


            /*
             * SELESAI
             */

            testStatus.textContent =
                "SELESAI";


            testDescription.textContent =
                "Pengujian berhasil";


            startText.textContent =
                "Test Lagi";


        } catch (error) {

            console.error(error);


            testStatus.textContent =
                "ERROR";


            testDescription.textContent =
                "Speed test gagal";


            phaseText.textContent =
                "Terjadi kesalahan";


            startText.textContent =
                "Coba Lagi";

        }


        startButton.disabled =
            false;

    }
);

</script>


</body>

</html>

