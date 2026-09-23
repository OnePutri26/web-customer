<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireCustomerInstallation($conn);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: packages.php");
    exit;
}

$packageId = (int) ($_POST['package_id'] ?? 0);

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM packages
     WHERE id = ? AND status = 'aktif'"
);

mysqli_stmt_bind_param($stmt, "i", $packageId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$package = mysqli_fetch_assoc($result);

if (!$package) {
    die("Paket tidak ditemukan.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_installation'])) {

    $nama = trim($_POST['nama'] ?? '');
    $noHp = trim($_POST['no_hp'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $patokan = trim($_POST['patokan'] ?? '');
    $latitude = $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : 0;
    $longitude = $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : 0;
    $catatan = trim($_POST['catatan'] ?? '');

    if ($nama === '' || $noHp === '' || $alamat === '') {

        $error = "Nama, nomor HP, dan alamat wajib diisi.";

    } else {

        mysqli_begin_transaction($conn);

        try {

            $customerStmt = mysqli_prepare(
                $conn,
                "SELECT id FROM customers WHERE user_id = ? LIMIT 1"
            );
            mysqli_stmt_bind_param($customerStmt, "i", $userId);
            mysqli_stmt_execute($customerStmt);
            $customer = mysqli_stmt_get_result($customerStmt)->fetch_assoc();
            mysqli_stmt_close($customerStmt);

            if (!$customer) {
                throw new RuntimeException("Data customer tidak ditemukan.");
            }

            $customerId = (int) $customer['id'];
            $catatan = trim($catatan . ($patokan !== '' ? "\nPatokan: " . $patokan : ''));

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO subscriptions
                (id_user, id_package, status, tgl_pengajuan, tgl_aktif, tgl_berakhir)
                VALUES (?, ?, 'pending', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ii",
                $userId,
                $packageId
            );

            mysqli_stmt_execute($stmt);

            $subscriptionId = mysqli_insert_id($conn);

            $installationCode = "INS" . date("YmdHis") . random_int(10, 99);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO instalasi
                (
                    id_customer,
                    id_paket,
                    kode_instalasi,
                    alamat,
                    latitude,
                    longitude,
                    tgl_permintaan,
                    status,
                    catatan
                )
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'new', ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "iissdds",
                $customerId,
                $packageId,
                $installationCode,
                $alamat,
                $latitude,
                $longitude,
                $catatan
            );

            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);

            header("Location: installation_status.php");
            exit;

        } catch (Exception $e) {

            mysqli_rollback($conn);

            $error = "Pengajuan gagal diproses.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Pengajuan Pemasangan</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/installation.css">

</head>

<body>

<div class="installation-page">
    <nav class="installation-navbar">
        <a href="packages.php" class="back-dashboard" aria-label="Kembali ke paket"><i class="bi bi-arrow-left"></i></a>
        <div class="nav-title"><strong>Pengajuan Pemasangan</strong><span>Lengkapi data pemasangan layanan</span></div>
        <a href="dashboard.php" class="dashboard-link"><i class="bi bi-grid-fill"></i> Dashboard</a>
    </nav>

<div class="container">

    <div class="form-card">

        <div class="form-intro"><div class="form-icon"><i class="bi bi-house-add-fill"></i></div><div><span class="eyebrow">LANGKAH TERAKHIR</span><h1>Ajukan pemasangan</h1></div></div>

        <p class="subtitle">
            <i class="bi bi-box-seam"></i> Paket yang dipilih:
            <strong>
                <?= htmlspecialchars($package['nama_paket']) ?>
            </strong>
        </p>

        <?php if (isset($error)): ?>

            <div class="error"><i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="package_id"
                value="<?= $packageId ?>"
            >

            <label for="nama"><i class="bi bi-person-fill"></i> Nama Pelanggan</label>

            <input
                id="nama" type="text"
                name="nama"
                required
            >

            <label for="no_hp"><i class="bi bi-telephone-fill"></i> Nomor HP</label>

            <input
                id="no_hp" type="text"
                name="no_hp"
                required
            >

            <label for="alamat"><i class="bi bi-geo-alt-fill"></i> Alamat Pemasangan</label>

            <textarea
                name="alamat"
                rows="4"
                required
            ></textarea>

            <label for="patokan"><i class="bi bi-signpost-2-fill"></i> Patokan Lokasi</label>

            <input
                id="patokan" type="text"
                name="patokan"
                placeholder="Contoh: dekat masjid..."
            >

            <div class="location-row">

                <div>
                    <label for="latitude">Latitude</label>

                    <input
                        type="text"
                        name="latitude"
                        id="latitude"
                    >
                </div>

                <div>
                    <label for="longitude">Longitude</label>

                    <input
                        type="text"
                        name="longitude"
                        id="longitude"
                    >
                </div>

            </div>

            <button
                type="button"
                onclick="getLocation()"
                class="location-btn"
            >
                <i class="bi bi-crosshair"></i> Gunakan Lokasi Saya
            </button>

            <label for="catatan"><i class="bi bi-chat-left-text-fill"></i> Catatan</label>

            <textarea
                name="catatan"
                rows="3"
                placeholder="Informasi tambahan..."
            ></textarea>

            <button
                type="submit"
                name="submit_installation"
                class="submit-btn"
            >
                <i class="bi bi-send-fill"></i> Ajukan Pemasangan
            </button>

        </form>

    </div>

</div>

<script>

function getLocation() {

    if (!navigator.geolocation) {
        alert("Browser tidak mendukung GPS.");
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {

            document.getElementById('latitude').value =
                position.coords.latitude;

            document.getElementById('longitude').value =
                position.coords.longitude;

        },
        function() {

            alert("Lokasi tidak dapat diambil.");

        }
    );
}

</script>

</body>
</html>