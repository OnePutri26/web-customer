<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

if (
    !isset($_SESSION['speedtest_csrf']) ||
    !is_string($_SESSION['speedtest_csrf'])
) {
    $_SESSION['speedtest_csrf'] = bin2hex(random_bytes(32));
}

$speedtestCsrf = $_SESSION['speedtest_csrf'];


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
    'Paket belum tercatat';

$paketSpeed =
    $customer['speed'] ??
    $customer['kecepatan'] ??
    'Belum tersedia';


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
    'Server aplikasi YESNET';

$serverIp =
    $_SERVER['SERVER_ADDR'] ??
    'Tidak tersedia';

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
                Uji koneksi browser ke server aplikasi YESNET, bukan kecepatan paket ISP
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
                Uji koneksi perangkat kamu ke server aplikasi YESNET.
                Hasil mengukur jalur ke server ini, bukan pembacaan langsung dari router.
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

            <div class="speed-result-card">
                <div class="result-icon ping">
                    <i class="bi bi-activity"></i>
                </div>
                <div>
                    <span>Jitter</span>
                    <div class="result-number">
                        <strong id="jitterValue">--</strong>
                        <small>ms</small>
                    </div>
                    <p id="jitterStatus">Belum diuji</p>
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
                        Hasil mengukur jalur browser ke server aplikasi YESNET dan tidak mewakili
                        kecepatan jaringan ISP secara menyeluruh.
                    </p>

                </div>


                <div class="online-status">

                    <span></span>

                    Server uji aplikasi

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

        <section class="speed-history-card" aria-labelledby="speedHistoryTitle">
            <div class="speed-history-heading">
                <div>
                    <h2 id="speedHistoryTitle">Riwayat Speed Test</h2>
                    <p>Hasil pengujian browser ke server aplikasi YESNET.</p>
                </div>
                <span id="speedHistoryStatus" role="status" aria-live="polite"></span>
            </div>
            <div class="speed-history-list" id="speedHistoryList">
                <p class="speed-history-empty">Memuat riwayat...</p>
            </div>
        </section>


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

const speedtestCsrf = <?= json_encode($speedtestCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

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

const jitterValue =
    document.getElementById("jitterValue");

const pingStatus =
    document.getElementById("pingStatus");

const downloadStatus =
    document.getElementById("downloadStatus");

const uploadStatus =
    document.getElementById("uploadStatus");

const jitterStatus =
    document.getElementById("jitterStatus");

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
        "speedtest-download.php?_="
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
                    cache: "no-store",
                    headers: {
                        "X-YESNET-CSRF": speedtestCsrf
                    }
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

    const jitter = samples.length > 1
        ? samples.slice(1).reduce(
            (total, sample, index) => total + Math.abs(sample - samples[index]),
            0
        ) / (samples.length - 1)
        : null;

    return {
        ping: average,
        jitter: jitter
    };
}

function formatHistoryDate(value)
{
    const date = new Date(String(value).replace(' ', 'T') + '+07:00');
    if (Number.isNaN(date.getTime())) {
        return 'Waktu tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta'
    }).format(date) + ' WIB';
}

function renderSpeedHistory(history)
{
    const list = document.getElementById('speedHistoryList');
    if (!list) {
        return;
    }

    list.textContent = '';
    if (!Array.isArray(history) || history.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'speed-history-empty';
        empty.textContent = 'Belum ada hasil speed test tersimpan.';
        list.appendChild(empty);
        return;
    }

    history.forEach(function (item) {
        const row = document.createElement('article');
        row.className = 'speed-history-row';
        const testedAt = document.createElement('time');
        testedAt.textContent = formatHistoryDate(item.tested_at);

        const download = document.createElement('span');
        download.innerHTML = '<i class="bi bi-download" aria-hidden="true"></i> ';
        download.appendChild(document.createTextNode(
            Number(item.download_mbps).toFixed(2) + ' Mbps'
        ));

        const upload = document.createElement('span');
        upload.innerHTML = '<i class="bi bi-upload" aria-hidden="true"></i> ';
        upload.appendChild(document.createTextNode(
            Number(item.upload_mbps).toFixed(2) + ' Mbps'
        ));

        const ping = document.createElement('span');
        ping.innerHTML = '<i class="bi bi-broadcast-pin" aria-hidden="true"></i> ';
        ping.appendChild(document.createTextNode(
            Number(item.ping_ms).toFixed(0) + ' ms'
        ));

        const jitter = document.createElement('span');
        jitter.innerHTML = '<i class="bi bi-activity" aria-hidden="true"></i> ';
        jitter.appendChild(document.createTextNode(
            item.jitter_ms === null ? '—' : Number(item.jitter_ms).toFixed(1) + ' ms'
        ));

        row.append(testedAt, download, upload, ping, jitter);
        list.appendChild(row);
    });
}

async function loadSpeedHistory()
{
    const status = document.getElementById('speedHistoryStatus');
    try {
        const response = await fetch('speedtest_history.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const payload = await response.json();
        if (!response.ok || !Array.isArray(payload.history)) {
            throw new Error(payload.error || 'Riwayat speed test tidak dapat dimuat.');
        }
        renderSpeedHistory(payload.history);
        if (status) {
            status.textContent = '';
        }
    } catch (error) {
        console.error('Gagal memuat riwayat speed test:', error);
        if (status) {
            status.textContent = 'Riwayat gagal dimuat. Pastikan migrasi database tersedia.';
        }
        const list = document.getElementById('speedHistoryList');
        if (list) {
            list.textContent = '';
            const errorMessage = document.createElement('p');
            errorMessage.className = 'speed-history-empty';
            errorMessage.textContent = 'Riwayat belum dapat dimuat.';
            list.appendChild(errorMessage);
        }
    }
}

async function saveSpeedHistory(result)
{
    const status = document.getElementById('speedHistoryStatus');
    if (status) {
        status.textContent = 'Menyimpan hasil...';
    }

    const response = await fetch('speedtest_history.php', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-YESNET-CSRF': speedtestCsrf
        },
        credentials: 'same-origin',
        body: JSON.stringify(result)
    });
    const payload = await response.json();
    if (!response.ok || !payload.saved) {
        throw new Error(payload.error || 'Hasil speed test gagal disimpan.');
    }

    if (status) {
        status.textContent = 'Hasil berhasil disimpan.';
    }
    await loadSpeedHistory();
}

loadSpeedHistory();


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
                cache: "no-store",
                headers: {
                    "X-YESNET-CSRF": speedtestCsrf
                }
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
                        "application/octet-stream",
                    "X-YESNET-CSRF": speedtestCsrf
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


            const pingResult =
                await testPing();
            const ping = pingResult.ping;


            pingValue.textContent =
                ping.toFixed(0);

            jitterValue.textContent = pingResult.jitter === null
                ? '--'
                : pingResult.jitter.toFixed(1);
            jitterStatus.textContent = pingResult.jitter === null
                ? 'Sampel tidak cukup'
                : (pingResult.jitter < 10 ? 'Stabil' : 'Fluktuatif');

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
                "Uji browser ke server aplikasi selesai";

            try {
                await saveSpeedHistory({
                    download_mbps: download,
                    upload_mbps: upload,
                    ping_ms: ping,
                    jitter_ms: pingResult.jitter
                });
            } catch (saveError) {
                console.error('Gagal menyimpan hasil speed test:', saveError);
                const historyStatus = document.getElementById('speedHistoryStatus');
                if (historyStatus) {
                    historyStatus.textContent = 'Hasil diuji, tetapi riwayat gagal disimpan.';
                }
            }


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
