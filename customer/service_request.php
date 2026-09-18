<?php
session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Ambil data customer
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$customer = $result->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}

$customerId = $customer['id'];

$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);


/*
|--------------------------------------------------------------------------
| Session request
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['service_requests'])) {
    $_SESSION['service_requests'] = [];
}


/*
|--------------------------------------------------------------------------
| Proses pengajuan request
|--------------------------------------------------------------------------
*/

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $serviceType = trim($_POST['service_type'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $schedule = trim($_POST['schedule'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $allowedServices = [
        'Perbaikan WiFi',
        'Instalasi',
        'Pemindahan Lokasi',
        'Penggantian Router',
        'Upgrade / Downgrade Paket',
        'Permintaan Teknisi',
        'Lainnya'
    ];

    $allowedPriority = [
        'Normal',
        'Penting',
        'Darurat'
    ];

    if (!in_array($serviceType, $allowedServices, true)) {

        $error = "Jenis layanan tidak valid.";

    } elseif (!in_array($priority, $allowedPriority, true)) {

        $error = "Prioritas tidak valid.";

    } elseif ($description === '') {

        $error = "Silakan isi detail permintaan.";

    } elseif ($phone === '') {

        $error = "Nomor telepon wajib diisi.";

    } else {

        $requestId = 'SR-' . date('Ymd') . '-' . strtoupper(
            substr(md5(uniqid()), 0, 5)
        );

        $_SESSION['service_requests'][] = [

            'id' => $requestId,

            'customer_id' => $customerId,

            'service_type' => $serviceType,

            'priority' => $priority,

            'description' => $description,

            'schedule' => $schedule,

            'phone' => $phone,

            'status' => 'Menunggu',

            'created_at' => date('Y-m-d H:i:s')
        ];

        $success = "Permintaan layanan berhasil dikirim.";

        $_POST = [];
    }
}


/*
|--------------------------------------------------------------------------
| Ambil request milik customer
|--------------------------------------------------------------------------
*/

$requests = array_reverse(
    $_SESSION['service_requests']
);

$serviceIcons = [

    'Perbaikan WiFi' =>
        'bi-wifi',

    'Instalasi' =>
        'bi-router',

    'Pemindahan Lokasi' =>
        'bi-geo-alt-fill',

    'Penggantian Router' =>
        'bi-router-fill',

    'Upgrade / Downgrade Paket' =>
        'bi-arrow-up-circle-fill',

    'Permintaan Teknisi' =>
        'bi-person-gear',

    'Lainnya' =>
        'bi-three-dots'
];

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Service Request - Customer</title>


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


    <!-- Service Request CSS -->

    <link
        rel="stylesheet"
        href="assets/css/service-request.css"
    >

</head>


<body>


<div class="dashboard-wrapper">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">


        <div class="sidebar-logo">

            <div class="logo-icon">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="logo-text">

                <strong>WiFi Portal</strong>

                <span>Customer Area</span>

            </div>

        </div>


        <nav class="sidebar-menu">


            <div class="menu-title">
                MENU UTAMA
            </div>


            <a
                href="dashboard.php"
                class="menu-item"
            >

                <i class="bi bi-grid-1x2-fill"></i>

                <span>Dashboard</span>

            </a>


            <a
                href="billing.php"
                class="menu-item"
            >

                <i class="bi bi-receipt"></i>

                <span>Tagihan</span>

            </a>


            <a
                href="usage.php"
                class="menu-item"
            >

                <i class="bi bi-bar-chart-fill"></i>

                <span>Penggunaan</span>

            </a>


            <a
                href="speedtest.php"
                class="menu-item"
            >

                <i class="bi bi-speedometer2"></i>

                <span>Speed Test</span>

            </a>


            <a
                href="network_status.php"
                class="menu-item"
            >

                <i class="bi bi-router-fill"></i>

                <span>Status Jaringan</span>

            </a>


            <div class="menu-title">
                LAYANAN
            </div>


            <a
                href="upgrade.php"
                class="menu-item"
            >

                <i class="bi bi-rocket-takeoff-fill"></i>

                <span>Upgrade WiFi</span>

            </a>


            <a
                href="complaint.php"
                class="menu-item"
            >

                <i class="bi bi-chat-left-text-fill"></i>

                <span>Keluhan</span>

            </a>


            <a
                href="service_request.php"
                class="menu-item active"
            >

                <i class="bi bi-tools"></i>

                <span>Permintaan Layanan</span>

            </a>


            <a
                href="chat.php"
                class="menu-item"
            >

                <i class="bi bi-headset"></i>

                <span>Chat CS</span>

            </a>


            <div class="menu-title">
                AKUN
            </div>


            <a
                href="profile.php"
                class="menu-item"
            >

                <i class="bi bi-person-fill"></i>

                <span>Profil Saya</span>

            </a>


            <a
                href="../logout.php"
                class="menu-item logout"
            >

                <i class="bi bi-box-arrow-right"></i>

                <span>Logout</span>

            </a>


        </nav>


        <!-- USER -->

        <div class="sidebar-user">

            <div class="user-avatar">
                <?= htmlspecialchars($initial) ?>
            </div>

            <div class="user-detail">

                <strong>
                    <?= htmlspecialchars($nama) ?>
                </strong>

                <small>
                    Customer
                </small>

            </div>

        </div>


    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- TOPBAR -->

        <header class="topbar">


            <div class="topbar-left">

                <div>

                    <h5>
                        Permintaan Layanan
                    </h5>

                    <span>
                        Ajukan kebutuhan layanan internet kamu
                    </span>

                </div>

            </div>


            <div class="topbar-right">


                <a
                    href="chat.php"
                    class="notification"
                >

                    <i class="bi bi-headset"></i>

                </a>


                <div class="top-profile">


                    <div class="top-avatar">

                        <?= htmlspecialchars($initial) ?>

                    </div>


                    <div class="top-user">

                        <strong>
                            <?= htmlspecialchars($nama) ?>
                        </strong>

                        <small>
                            Customer
                        </small>

                    </div>


                </div>


            </div>


        </header>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <div class="content service-content">


            <!-- PAGE HEADER -->

            <div class="service-page-header">


                <div>

                    <span class="page-label">
                        CUSTOMER SERVICE
                    </span>

                    <h1>
                        Permintaan Layanan
                    </h1>

                    <p>
                        Ajukan layanan yang kamu butuhkan dan
                        pantau prosesnya dari halaman ini.
                    </p>

                </div>


                <div class="service-header-icon">

                    <i class="bi bi-tools"></i>

                </div>


            </div>


            <!-- ALERT SUCCESS -->

            <?php if ($success): ?>

                <div class="service-alert success">

                    <div class="alert-icon">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div>

                        <strong>
                            Berhasil
                        </strong>

                        <span>
                            <?= htmlspecialchars($success) ?>
                        </span>

                    </div>

                </div>

            <?php endif; ?>


            <!-- ALERT ERROR -->

            <?php if ($error): ?>

                <div class="service-alert error">

                    <div class="alert-icon">

                        <i class="bi bi-exclamation-circle-fill"></i>

                    </div>

                    <div>

                        <strong>
                            Terjadi Kesalahan
                        </strong>

                        <span>
                            <?= htmlspecialchars($error) ?>
                        </span>

                    </div>

                </div>

            <?php endif; ?>


            <div class="row g-4">


                <!-- =================================================
                     FORM
                ================================================== -->

                <div class="col-xl-7">


                    <div class="service-card">


                        <div class="service-card-header">

                            <div class="service-title-icon">

                                <i class="bi bi-file-earmark-plus-fill"></i>

                            </div>

                            <div>

                                <h2>
                                    Buat Permintaan
                                </h2>

                                <p>
                                    Lengkapi informasi layanan yang kamu butuhkan.
                                </p>

                            </div>

                        </div>


                        <form
                            method="POST"
                            class="service-form"
                        >


                            <!-- SERVICE -->

                            <div class="form-group">


                                <label>
                                    Jenis Layanan
                                    <span>*</span>
                                </label>


                                <div class="service-options">


                                    <?php

                                    $options = [

                                        [
                                            'name' => 'Perbaikan WiFi',
                                            'icon' => 'bi-wifi'
                                        ],

                                        [
                                            'name' => 'Instalasi',
                                            'icon' => 'bi-router'
                                        ],

                                        [
                                            'name' => 'Pemindahan Lokasi',
                                            'icon' => 'bi-geo-alt'
                                        ],

                                        [
                                            'name' => 'Penggantian Router',
                                            'icon' => 'bi-router-fill'
                                        ],

                                        [
                                            'name' => 'Upgrade / Downgrade Paket',
                                            'icon' => 'bi-arrow-up-circle'
                                        ],

                                        [
                                            'name' => 'Permintaan Teknisi',
                                            'icon' => 'bi-person-gear'
                                        ],

                                        [
                                            'name' => 'Lainnya',
                                            'icon' => 'bi-three-dots'
                                        ]

                                    ];

                                    ?>


                                    <?php foreach ($options as $option): ?>

                                        <label class="service-option">


                                            <input
                                                type="radio"
                                                name="service_type"
                                                value="<?= htmlspecialchars($option['name']) ?>"
                                                required
                                            >


                                            <span class="option-box">

                                                <i
                                                    class="bi <?= htmlspecialchars($option['icon']) ?>"
                                                ></i>

                                                <span>
                                                    <?= htmlspecialchars($option['name']) ?>
                                                </span>

                                            </span>


                                        </label>

                                    <?php endforeach; ?>


                                </div>

                            </div>


                            <!-- PHONE -->

                            <div class="form-group">


                                <label for="phone">

                                    Nomor Telepon

                                    <span>*</span>

                                </label>


                                <div class="input-icon">

                                    <i class="bi bi-telephone-fill"></i>

                                    <input
                                        type="text"
                                        id="phone"
                                        name="phone"
                                        placeholder="Contoh: 081234567890"
                                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                        required
                                    >

                                </div>


                            </div>


                            <!-- PRIORITY -->

                            <div class="form-group">


                                <label for="priority">

                                    Prioritas

                                    <span>*</span>

                                </label>


                                <div class="select-wrapper">

                                    <i class="bi bi-flag-fill"></i>


                                    <select
                                        id="priority"
                                        name="priority"
                                        required
                                    >

                                        <option value="">
                                            Pilih prioritas
                                        </option>

                                        <option value="Normal">
                                            Normal
                                        </option>

                                        <option value="Penting">
                                            Penting
                                        </option>

                                        <option value="Darurat">
                                            Darurat
                                        </option>

                                    </select>


                                    <i class="bi bi-chevron-down select-arrow"></i>

                                </div>


                                <small class="form-help">
                                    Gunakan prioritas Darurat hanya untuk
                                    gangguan yang benar-benar mendesak.
                                </small>


                            </div>


                            <!-- SCHEDULE -->

                            <div class="form-group">


                                <label for="schedule">

                                    Waktu yang Diinginkan

                                </label>


                                <div class="input-icon">

                                    <i class="bi bi-calendar-event-fill"></i>


                                    <input
                                        type="datetime-local"
                                        id="schedule"
                                        name="schedule"
                                        value="<?= htmlspecialchars($_POST['schedule'] ?? '') ?>"
                                    >

                                </div>


                            </div>


                            <!-- DESCRIPTION -->

                            <div class="form-group">


                                <label for="description">

                                    Detail Permintaan

                                    <span>*</span>

                                </label>


                                <textarea
                                    id="description"
                                    name="description"
                                    rows="5"
                                    maxlength="500"
                                    placeholder="Jelaskan kebutuhan atau kendala kamu secara detail..."
                                    required
                                ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>


                                <div class="textarea-footer">

                                    <small>
                                        Jelaskan masalah secara detail agar
                                        teknisi dapat membantu lebih cepat.
                                    </small>

                                    <span>
                                        <b id="charCount">0</b>/500
                                    </span>

                                </div>


                            </div>


                            <!-- SUBMIT -->

                            <button
                                type="submit"
                                class="btn-submit-service"
                            >

                                <i class="bi bi-send-fill"></i>

                                Kirim Permintaan

                            </button>


                        </form>


                    </div>


                </div>


                <!-- =================================================
                     RIGHT SIDE
                ================================================== -->

                <div class="col-xl-5">


                    <!-- HOW IT WORKS -->

                    <div class="service-card process-card">


                        <div class="service-card-header">

                            <div class="service-title-icon">

                                <i class="bi bi-info-circle-fill"></i>

                            </div>

                            <div>

                                <h2>
                                    Cara Kerja
                                </h2>

                                <p>
                                    Proses permintaan layanan kamu.
                                </p>

                            </div>

                        </div>


                        <div class="service-process">


                            <div class="process-item">

                                <div class="process-number">
                                    1
                                </div>

                                <div>

                                    <strong>
                                        Kirim Permintaan
                                    </strong>

                                    <span>
                                        Isi formulir sesuai kebutuhan layanan.
                                    </span>

                                </div>

                            </div>


                            <div class="process-line"></div>


                            <div class="process-item">

                                <div class="process-number">
                                    2
                                </div>

                                <div>

                                    <strong>
                                        Diverifikasi CS
                                    </strong>

                                    <span>
                                        Tim CS akan memeriksa permintaan kamu.
                                    </span>

                                </div>

                            </div>


                            <div class="process-line"></div>


                            <div class="process-item">

                                <div class="process-number">
                                    3
                                </div>

                                <div>

                                    <strong>
                                        Diproses
                                    </strong>

                                    <span>
                                        Teknisi akan dijadwalkan jika diperlukan.
                                    </span>

                                </div>

                            </div>


                            <div class="process-line"></div>


                            <div class="process-item">

                                <div class="process-number">
                                    4
                                </div>

                                <div>

                                    <strong>
                                        Selesai
                                    </strong>

                                    <span>
                                        Permintaan ditandai selesai setelah layanan
                                        berhasil diberikan.
                                    </span>

                                </div>

                            </div>


                        </div>


                    </div>


                    <!-- CS CARD -->

                    <div class="service-cs-card">


                        <div class="cs-avatar">

                            <i class="bi bi-headset"></i>

                            <span class="online-dot"></span>

                        </div>


                        <div class="cs-content">

                            <span>
                                BUTUH BANTUAN?
                            </span>

                            <strong>
                                Customer Service
                            </strong>

                            <p>
                                Hubungi CS jika kamu membutuhkan
                                bantuan lebih lanjut.
                            </p>

                            <a href="chat.php">

                                <i class="bi bi-chat-dots-fill"></i>

                                Chat dengan CS

                            </a>

                        </div>


                    </div>


                </div>


            </div>


            <!-- =================================================
                 REQUEST HISTORY
            ================================================== -->

            <section class="request-history">


                <div class="history-header">

                    <div>

                        <span>
                            AKTIVITAS
                        </span>

                        <h2>
                            Riwayat Permintaan
                        </h2>

                    </div>


                    <div class="history-count">

                        <?= count($requests) ?>

                        <small>
                            Request
                        </small>

                    </div>

                </div>


                <?php if (empty($requests)): ?>


                    <div class="empty-request">

                        <div class="empty-icon">

                            <i class="bi bi-inbox"></i>

                        </div>

                        <h3>
                            Belum Ada Permintaan
                        </h3>

                        <p>
                            Semua permintaan layanan yang kamu kirim
                            akan muncul di sini.
                        </p>

                    </div>


                <?php else: ?>


                    <div class="request-list">


                        <?php foreach ($requests as $request): ?>


                            <div class="request-item">


                                <div class="request-type-icon">

                                    <i class="bi
                                        <?= htmlspecialchars(
                                            $serviceIcons[$request['service_type']]
                                            ?? 'bi-tools'
                                        )
                                    ?>"></i>

                                </div>


                                <div class="request-info">

                                    <div class="request-top">

                                        <strong>
                                            <?= htmlspecialchars(
                                                $request['service_type']
                                            ) ?>
                                        </strong>


                                        <span class="request-status">

                                            <i class="bi bi-clock-fill"></i>

                                            <?= htmlspecialchars(
                                                $request['status']
                                            ) ?>

                                        </span>

                                    </div>


                                    <p>
                                        <?= htmlspecialchars(
                                            $request['description']
                                        ) ?>
                                    </p>


                                    <div class="request-meta">

                                        <span>

                                            <i class="bi bi-hash"></i>

                                            <?= htmlspecialchars(
                                                $request['id']
                                            ) ?>

                                        </span>


                                        <span>

                                            <i class="bi bi-calendar3"></i>

                                            <?= date(
                                                'd M Y H:i',
                                                strtotime(
                                                    $request['created_at']
                                                )
                                            ) ?>

                                        </span>


                                        <span class="priority-<?= strtolower(
                                            $request['priority']
                                        ) ?>">

                                            <i class="bi bi-flag-fill"></i>

                                            <?= htmlspecialchars(
                                                $request['priority']
                                            ) ?>

                                        </span>

                                    </div>

                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>


                <?php endif; ?>


            </section>


        </div>


    </main>


</div>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /*
        |--------------------------------------------------------------------------
        | Character Counter
        |--------------------------------------------------------------------------
        */

        const textarea =
            document.getElementById('description');

        const charCount =
            document.getElementById('charCount');


        function updateCharacterCount() {

            if (!textarea) {
                return;
            }

            charCount.textContent =
                textarea.value.length;

        }


        textarea.addEventListener(
            'input',
            updateCharacterCount
        );


        updateCharacterCount();


        /*
        |--------------------------------------------------------------------------
        | Submit Loading
        |--------------------------------------------------------------------------
        */

        const form =
            document.querySelector('.service-form');

        const submitButton =
            document.querySelector('.btn-submit-service');


        form.addEventListener(
            'submit',
            function () {

                submitButton.disabled = true;

                submitButton.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm"
                        role="status"
                    ></span>
                    Mengirim Permintaan...
                `;

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Auto Hide Alert
        |--------------------------------------------------------------------------
        */

        const alertBox =
            document.querySelector('.service-alert');

        if (alertBox) {

            setTimeout(
                function () {

                    alertBox.classList.add(
                        'hide-alert'
                    );

                },
                5000
            );

        }

    }
);

</script>


</body>

</html>