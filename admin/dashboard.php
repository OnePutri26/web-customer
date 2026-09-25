<?php

require_once "auth_check.php";
require_once "../config/database.php";

$totalPelanggan = 0;
$pelangganAktif = 0;
$pengaduanAktif = 0;
$totalBelumBayar = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM customers
");

if ($result) {
    $totalPelanggan = (int)$result->fetch_assoc()['total'];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM customers
    WHERE status_langganan = 'aktif'
");

if ($result) {
    $pelangganAktif = (int)$result->fetch_assoc()['total'];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE status IN (
        'baru',
        'diproses',
        'ditugaskan'
    )
");

if ($result) {
    $pengaduanAktif = (int)$result->fetch_assoc()['total'];
}

$result = $conn->query("
    SELECT COALESCE(SUM(jumlah), 0) AS total
    FROM invoices
    WHERE status IN (
        'belum_bayar',
        'terlambat'
    )
");

if ($result) {
    $totalBelumBayar = (float)$result->fetch_assoc()['total'];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Dashboard Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body>

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            WiFi Management - Admin
        </span>

        <div class="text-white">

            <?= htmlspecialchars($_SESSION['nama']) ?>

            <a
                href="logout.php"
                class="btn btn-sm btn-danger ms-3"
            >
                Logout
            </a>

        </div>

    </div>

</nav>

<div class="container-fluid p-4">

    <h3 class="mb-4">
        Dashboard
    </h3>

    <div class="row g-4">

        <div class="col-md-3">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h6>Total Pelanggan</h6>

                    <h2>
                        <?= number_format($totalPelanggan) ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h6>Pelanggan Aktif</h6>

                    <h2>
                        <?= number_format($pelangganAktif) ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h6>Pengaduan Aktif</h6>

                    <h2>
                        <?= number_format($pengaduanAktif) ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h6>Tagihan Belum Bayar</h6>

                    <h2>
                        Rp <?= number_format(
                            $totalBelumBayar,
                            0,
                            ',',
                            '.'
                        ) ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>

    <div class="row mt-4">

        <div class="col-md-4">

            <div class="list-group shadow-sm">

                <a
                    href="pelanggan/index.php"
                    class="list-group-item list-group-item-action"
                >
                    👥 Pelanggan
                </a>

                <a
                    href="paket/index.php"
                    class="list-group-item list-group-item-action"
                >
                    📦 Paket Internet
                </a>

                <a
                    href="tagihan/index.php"
                    class="list-group-item list-group-item-action"
                >
                    🧾 Tagihan
                </a>

                <a
                    href="pembayaran/index.php"
                    class="list-group-item list-group-item-action"
                >
                    💳 Pembayaran
                </a>

                <a
                    href="pengaduan/index.php"
                    class="list-group-item list-group-item-action"
                >
                    🛠 Pengaduan
                </a>

                <a
                    href="teknisi/index.php"
                    class="list-group-item list-group-item-action"
                >
                    👷 Teknisi
                </a>

                <a
                    href="jaringan/area.php"
                    class="list-group-item list-group-item-action"
                >
                    🌐 Jaringan
                </a>

            </div>

        </div>

    </div>

</div>

</body>
</html>