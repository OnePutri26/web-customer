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

$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$customerId = $customer['id'];

$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);


/* =====================================================
   DATA NETWORK
===================================================== */

$isOnline = null;


/* =====================================================
   STATUS DATABASE
===================================================== */

$databaseStatus = false;

try {

    if ($conn && $conn->ping()) {
        $databaseStatus = true;
    }

} catch (Exception $e) {

    $databaseStatus = false;

}


/* =====================================================
   STATUS SERVICE
===================================================== */

$webStatus = true;

$networkStatus = null;


/* =====================================================
   LABEL STATUS
===================================================== */

$networkLabel = 'Status Area Belum Tersedia';


$networkDescription = 'Informasi gangguan dan pemeliharaan area belum terhubung ke dashboard.';


/* =====================================================
   WAKTU CHECK
===================================================== */

$checkedAt = date(
    'd M Y, H:i:s'
);


/* =====================================================
   FORMAT UPTIME CHECK
===================================================== */

$checkDuration = 'Normal';


/* =====================================================
   STATUS SERVICE CLASS
===================================================== */

function statusClass(?bool $status)
{
    if ($status === null) {
        return 'service-unknown';
    }

    return $status
        ? 'service-online'
        : 'service-offline';
}


function statusText(?bool $status)
{
    if ($status === null) {
        return 'Belum tersedia';
    }

    return $status
        ? 'Online'
        : 'Offline';
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
        Status Jaringan | Customer Portal
    </title>


    <!-- =================================================
         BOOTSTRAP
    ================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =================================================
         BOOTSTRAP ICONS
    ================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- =================================================
         NETWORK STATUS CSS
    ================================================== -->

    <link
        rel="stylesheet"
        href="assets/css/customer-dashboard.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/network_status.css"
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


        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="menu-item"
        >

            <i class="bi bi-grid-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <!-- Tagihan -->

        <a
            href="billing.php"
            class="menu-item"
        >

            <i class="bi bi-credit-card-fill"></i>

            <span>
                Tagihan
            </span>

        </a>


        <!-- Pemakaian -->

        <a
            href="usage.php"
            class="menu-item"
        >

            <i class="bi bi-speedometer2"></i>

            <span>
                Pemakaian
            </span>

        </a>


        <!-- Speed Test -->

        <a
            href="speedtest.php"
            class="menu-item"
        >

            <i class="bi bi-lightning-charge-fill"></i>

            <span>
                Speed Test
            </span>

        </a>


        <!-- Gangguan -->

        <a
            href="complaint.php"
            class="menu-item"
        >

            <i class="bi bi-tools"></i>

            <span>
                Gangguan
            </span>

        </a>


        <!-- Status Jaringan -->

        <a
            href="network_status.php"
            class="menu-item active"
        >

            <i class="bi bi-globe2"></i>

            <span>
                Status Jaringan
            </span>

        </a>


        <!-- Chat -->

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


        <!-- Upgrade -->

        <a
            href="upgrade.php"
            class="menu-item"
        >

            <i class="bi bi-arrow-up-circle-fill"></i>

            <span>
                Upgrade Paket
            </span>

        </a>


        <!-- Layanan -->

        <a
            href="service_request.php"
            class="menu-item"
        >

            <i class="bi bi-plus-circle-fill"></i>

            <span>
                Layanan Tambahan
            </span>

        </a>


        <!-- Profile -->

        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile Saya
            </span>

        </a>


        <!-- Logout -->

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


    <!-- =================================================
         USER SIDEBAR
    ================================================== -->

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
                Status Jaringan
            </h4>

            <span>
                Pantau kondisi jaringan internet kamu
            </span>

        </div>


        <div class="topbar-right">


            <!-- Notification -->

            <div class="notification">

                <i class="bi bi-bell"></i>

            </div>


            <!-- Profile -->

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

    <div class="content network-content">


        <!-- =================================================
             PAGE HEADER
        ================================================== -->

        <div class="page-heading">

            <div>

                <span class="page-eyebrow">
                    NETWORK MONITORING
                </span>

                <h1>
                    Status Jaringan
                </h1>

                <p>
                    Pantau kondisi koneksi dan layanan jaringan
                    internet kamu secara berkala.
                </p>

            </div>


            <button
                type="button"
                class="refresh-button"
                onclick="window.location.reload()"
            >

                <i class="bi bi-arrow-clockwise"></i>

                Refresh Status

            </button>

        </div>



        <!-- =================================================
             MAIN STATUS
        ================================================== -->

        <section
            class="
                network-main-status
                <?= $networkStatus === true
                    ? 'status-normal'
                    : ($networkStatus === false ? 'status-danger' : 'status-unknown')
                ?>
            "
            id="areaNetworkStatus"
        >


            <div class="main-status-left">


                <div class="main-status-icon">

                    <?php if ($networkStatus === true): ?>

                        <i class="bi bi-check-lg"></i>

                    <?php elseif ($networkStatus === false): ?>

                        <i class="bi bi-exclamation-lg"></i>

                    <?php else: ?>

                        <i class="bi bi-question-lg"></i>

                    <?php endif; ?>

                </div>


                <div class="main-status-info">

                    <span>
                        STATUS JARINGAN
                    </span>

                    <h2 id="areaNetworkLabel">
                        <?= htmlspecialchars($networkLabel) ?>
                    </h2>

                    <p id="areaNetworkDescription">
                        <?= htmlspecialchars($networkDescription) ?>
                    </p>

                </div>

            </div>


            <div class="status-live" id="areaNetworkBadge">

                <span class="live-dot"></span>

                <?= $networkStatus === true
                    ? 'NORMAL'
                    : ($networkStatus === false ? 'GANGGUAN' : 'BELUM TERSEDIA')
                ?>

            </div>

        </section>



        <!-- =================================================
             SERVICE STATUS
        ================================================== -->

        <section class="dashboard-card service-card">


            <div class="card-title">

                <div>

                    <h5>
                        Status Layanan
                    </h5>

                    <span>
                        Kondisi layanan yang digunakan oleh customer
                    </span>

                </div>


                <span class="live-text">
                    STATUS INTEGRASI
                </span>

            </div>



            <div class="service-grid">


                <!-- INTERNET -->

                <div class="service-box">

                    <div
                        id="internetTelemetryIcon"
                        class="service-icon
                        <?= $isOnline === true
                            ? 'icon-online'
                        : ($isOnline === false ? 'icon-offline' : 'icon-unknown')
                        ?>"
                    >

                        <i class="bi bi-globe2"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Internet
                        </strong>

                        <span>
                            Telemetri koneksi pelanggan
                        </span>

                    </div>


                    <div
                        id="internetTelemetryStatus"
                        class="<?= statusClass($isOnline) ?>"
                    >

                        <span></span>

                        <?= statusText($isOnline) ?>

                    </div>

                </div>



                <!-- DATABASE -->

                <div class="service-box">

                    <div
                        class="service-icon
                        <?= $databaseStatus
                            ? 'icon-online'
                            : 'icon-offline'
                        ?>"
                    >

                        <i class="bi bi-database-check"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Database
                        </strong>

                        <span>
                            Koneksi database sistem
                        </span>

                    </div>


                    <div
                        class="<?= statusClass($databaseStatus) ?>"
                    >

                        <span></span>

                        <?= statusText($databaseStatus) ?>

                    </div>

                </div>



                <!-- WEB SERVER -->

                <div class="service-box">

                    <div class="service-icon icon-online">

                        <i class="bi bi-hdd-rack"></i>

                    </div>


                    <div class="service-info">

                        <strong>
                            Web Server
                        </strong>

                        <span>
                            Server aplikasi customer
                        </span>

                    </div>


                    <div class="service-online">

                        <span></span>

                        Online

                    </div>

                </div>


            </div>

        </section>

        <!-- =================================================
             CONNECTION SUMMARY
        ================================================== -->

        <section class="dashboard-card network-summary">


            <div class="summary-icon">

                <i class="bi bi-wifi"></i>

            </div>


            <div class="summary-content">

                <span>
                    CONNECTION SUMMARY
                </span>

                <h3 id="areaNetworkSummaryTitle">
                    <?= $networkStatus === true
                        ? 'Jaringan area kamu berjalan normal'
                        : ($networkStatus === false
                            ? 'Terdapat gangguan jaringan area'
                            : 'Status jaringan area belum terhubung')
                    ?>
                </h3>

                <p id="areaNetworkSummaryDescription">
                    <?= $networkStatus === true
                        ? 'Informasi jaringan area tersedia dari sistem monitoring.'
                        : ($networkStatus === false
                            ? 'Sistem monitoring melaporkan gangguan. Hubungi Customer Service untuk informasi lebih lanjut.'
                            : 'Halaman ini tidak menguji koneksi server sebagai pengganti status jaringan pelanggan. Integrasi monitoring area perlu dikonfigurasi terlebih dahulu.')
                    ?>
                </p>

            </div>


            <a
                href="chat.php"
                class="btn-chat"
            >

                <i class="bi bi-headset"></i>

                Hubungi CS

            </a>

        </section>

        <section class="dashboard-card network-history-card">
            <div class="card-title">
                <div>
                    <h5>Riwayat gangguan area</h5>
                    <span>Catatan insiden dari database jaringan YESNET</span>
                </div>
            </div>
            <div class="network-history-list" id="areaNetworkHistory" aria-live="polite">
                <p class="network-history-empty">Memuat riwayat area...</p>
            </div>
        </section>


        <!-- =================================================
             FOOTER INFO
        ================================================== -->

        <div class="network-footer" id="areaNetworkUpdatedAt">

            <i class="bi bi-info-circle"></i>

            Status jaringan diperbarui setiap kali halaman
            dimuat atau tombol refresh ditekan.

        </div>


    </div>

</main>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    const formatDate = function (value) {
        if (!value) {
            return 'Waktu tidak tersedia';
        }

        const normalized = String(value).replace(' ', 'T');
        const date = new Date(/[zZ]|[+-]\d\d:\d\d$/.test(normalized)
            ? normalized
            : normalized + '+07:00');
        if (Number.isNaN(date.getTime())) {
            return 'Waktu tidak tersedia';
        }

        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Asia/Jakarta'
        }).format(date) + ' WIB';
    };

    const historyList = document.getElementById('areaNetworkHistory');
    const setText = function (id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    };

    try {
        const response = await fetch('dashboard_data.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const payload = await response.json();
        if (!response.ok || !payload.data) {
            throw new Error(payload.error || 'Data status jaringan gagal dimuat.');
        }

        const network = payload.data.network || {};
        if (network.available) {
            const state = network.status || 'unknown';
            const statusClass = state === 'outage'
                ? 'status-danger'
                : (state === 'normal'
                    ? 'status-normal'
                    : (state === 'maintenance' ? 'status-maintenance' : 'status-unknown'));
            const statusLabel = {
                normal: 'NORMAL',
                outage: 'GANGGUAN',
                maintenance: 'MAINTENANCE',
                unknown: 'BELUM TERSEDIA'
            };
            const statusIcon = {
                normal: 'bi-check-lg',
                outage: 'bi-exclamation-lg',
                maintenance: 'bi-wrench-adjustable',
                unknown: 'bi-question-lg'
            };

            const hero = document.getElementById('areaNetworkStatus');
            if (hero) {
                hero.classList.remove('status-normal', 'status-danger', 'status-maintenance', 'status-unknown');
                hero.classList.add(statusClass);
            }
            setText('areaNetworkLabel', network.title || 'Status jaringan area belum tersedia');
            setText('areaNetworkDescription', network.description || 'Informasi status area belum tersedia.');
            setText('areaNetworkSummaryTitle', network.title || 'Status jaringan area belum tersedia');
            setText(
                'areaNetworkSummaryDescription',
                network.area ? 'Wilayah layanan: ' + network.area : 'Wilayah pelanggan belum terhubung ke area jaringan.'
            );
            const badge = document.getElementById('areaNetworkBadge');
            if (badge) {
                const dot = badge.querySelector('.live-dot');
                badge.textContent = '';
                if (dot) {
                    badge.appendChild(dot);
                }
                badge.appendChild(document.createTextNode(statusLabel[state] || statusLabel.unknown));
            }

            const heroIcon = document.querySelector('#areaNetworkStatus .main-status-icon i');
            if (heroIcon) {
                heroIcon.className = 'bi ' + (statusIcon[state] || statusIcon.unknown);
            }
        }

        const telemetry = payload.data.telemetry || {};
        const telemetryStatus = document.getElementById('internetTelemetryStatus');
        const telemetryIcon = document.getElementById('internetTelemetryIcon');
        if (telemetryStatus && telemetryIcon) {
            const fresh = Boolean(telemetry.available && telemetry.is_live);
            telemetryStatus.className = fresh ? 'service-online' : 'service-unknown';
            const statusDot = telemetryStatus.querySelector('span');
            telemetryStatus.textContent = '';
            if (statusDot) {
                telemetryStatus.appendChild(statusDot);
            }
            telemetryStatus.appendChild(document.createTextNode(
                fresh ? ' Data terbaru' : ' Belum tersedia'
            ));
            telemetryIcon.className = 'service-icon ' + (fresh ? 'icon-online' : 'icon-unknown');
        }

        if (historyList) {
            historyList.textContent = '';
            const history = Array.isArray(network.history) ? network.history : [];
            if (history.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'network-history-empty';
                empty.textContent = network.available
                    ? 'Belum ada riwayat insiden yang tercatat untuk area ini.'
                    : 'Riwayat belum tersedia karena area pelanggan belum terhubung.';
                historyList.appendChild(empty);
            } else {
                history.forEach(function (incident) {
                    const item = document.createElement('article');
                    item.className = 'network-history-item';

                    const title = document.createElement('strong');
                    title.textContent = String(incident.title || 'Gangguan jaringan');
                    const description = document.createElement('p');
                    description.textContent = String(incident.description || 'Tidak ada keterangan tambahan.');
                    const date = document.createElement('small');
                    date.textContent = 'Mulai: ' + formatDate(incident.started_at);

                    item.append(title, description, date);
                    if (incident.estimated_resolution) {
                        const estimate = document.createElement('small');
                        estimate.textContent = ' · Estimasi: ' + formatDate(incident.estimated_resolution);
                        item.appendChild(estimate);
                    }
                    historyList.appendChild(item);
                });
            }
        }

        const updatedAt = document.getElementById('areaNetworkUpdatedAt');
        if (updatedAt) {
            const infoIcon = updatedAt.querySelector('i');
            updatedAt.textContent = '';
            if (infoIcon) {
                updatedAt.appendChild(infoIcon);
            }
            updatedAt.appendChild(document.createTextNode(
                ' Status jaringan diperbarui ' + formatDate(payload.data.checked_at) + '.'
            ));
        }
    } catch (error) {
        console.error('Pembaruan status jaringan gagal:', error);
        if (historyList) {
            historyList.textContent = 'Data status jaringan gagal diperbarui. Silakan coba refresh.';
        }
    }
});
</script>

</body>

</html>
