<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($nominal): string
{
    return 'Rp ' . number_format(
        (float) $nominal,
        0,
        ',',
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| AMBIL PAKET
|--------------------------------------------------------------------------
*/

$packages = [];

$sql = "
    SELECT
        id,
        nama_paket,
        speed_mbps,
        harga,
        deskripsi,
        status
    FROM paket_wifi
    WHERE
        status IS NULL
        OR TRIM(status) = ''
        OR LOWER(TRIM(status)) IN (
            'aktif',
            'active',
            '1',
            'tersedia',
            'available'
        )
    ORDER BY
        CAST(harga AS DECIMAL(15,2)) ASC,
        CAST(speed_mbps AS DECIMAL(15,2)) ASC
";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $packages[] = $row;
}

$totalPackages = count($packages);

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
        name="description"
        content="Pilih paket internet YesNet sesuai kebutuhan Anda"
    >

    <title>Paket Internet | YesNet</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Arial,
                sans-serif;

            background: #f8fafc;
            color: #0f172a;
        }

        a {
            text-decoration: none;
        }

        /* NAVBAR */

        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;

            height: 76px;

            display: flex;
            align-items: center;

            padding: 0 7%;

            background: rgba(255,255,255,.88);

            backdrop-filter: blur(18px);

            border-bottom: 1px solid #e2e8f0;
        }

        .logo {
            margin-right: auto;

            font-size: 25px;
            font-weight: 900;

            letter-spacing: -1px;

            color: #0f172a;
        }

        .logo span {
            color: #2563eb;
        }

        .nav-links {
            display: flex;
            gap: 30px;

            margin-right: 30px;
        }

        .nav-links a {
            color: #475569;

            font-size: 14px;
            font-weight: 600;

            transition: .2s;
        }

        .nav-links a:hover {
            color: #2563eb;
        }

        .login-btn {
            padding: 11px 20px;

            border-radius: 11px;

            color: white;
            background: #2563eb;

            font-size: 14px;
            font-weight: 700;
        }

        /* HERO */

        .hero {
            position: relative;

            overflow: hidden;

            padding: 95px 7% 85px;

            text-align: center;

            background:
                radial-gradient(
                    circle at 50% 0%,
                    rgba(37,99,235,.15),
                    transparent 40%
                ),
                #f8fafc;
        }

        .hero-badge {
            display: inline-flex;

            padding: 8px 15px;

            margin-bottom: 22px;

            border-radius: 50px;

            background: #dbeafe;

            color: #2563eb;

            font-size: 12px;
            font-weight: 800;

            letter-spacing: 1px;
        }

        .hero h1 {
            max-width: 800px;

            margin: auto;

            font-size: clamp(42px, 6vw, 68px);

            line-height: 1.05;

            letter-spacing: -3px;

            font-weight: 900;
        }

        .hero h1 span {
            color: #2563eb;
        }

        .hero p {
            max-width: 650px;

            margin: 22px auto 0;

            color: #64748b;

            font-size: 17px;

            line-height: 1.8;
        }

        /* PACKAGE */

        .package-section {
            max-width: 1200px;

            margin: auto;

            padding: 30px 20px 100px;
        }

        .package-count {
            margin-bottom: 30px;

            color: #64748b;

            font-size: 14px;
        }

        .package-count strong {
            color: #0f172a;
        }

        .package-grid {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 24px;
        }

        .package-card {
            position: relative;

            display: flex;

            flex-direction: column;

            padding: 32px;

            min-height: 520px;

            background: white;

            border: 1px solid #e2e8f0;

            border-radius: 25px;

            box-shadow:
                0 15px 45px rgba(15,23,42,.06);

            transition: .3s;
        }

        .package-card:hover {
            transform: translateY(-8px);

            box-shadow:
                0 25px 60px rgba(15,23,42,.12);
        }

        .package-card.popular {
            border: 2px solid #2563eb;

            box-shadow:
                0 20px 60px rgba(37,99,235,.14);
        }

        .popular {
            position: absolute;

            top: 0;
            left: 50%;

            transform: translate(-50%, -50%);

            padding: 7px 15px;

            border-radius: 50px;

            background: #2563eb;

            color: white;

            font-size: 11px;

            font-weight: 800;

            white-space: nowrap;
        }

        .package-icon {
            width: 58px;
            height: 58px;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-bottom: 25px;

            border-radius: 17px;

            background: #dbeafe;

            color: #2563eb;

            font-size: 25px;
        }

        .package-card h2 {
            margin-bottom: 5px;

            font-size: 23px;
        }

        .package-label {
            color: #64748b;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 1px;
        }

        .speed {
            margin: 25px 0 12px;
        }

        .speed strong {
            font-size: 50px;

            line-height: 1;

            letter-spacing: -2px;
        }

        .speed span {
            color: #64748b;

            font-size: 15px;
        }

        .description {
            min-height: 50px;

            color: #64748b;

            font-size: 14px;

            line-height: 1.7;
        }

        .price {
            padding: 22px 0;

            margin-top: 20px;

            border-top: 1px solid #e2e8f0;
        }

        .price small {
            display: block;

            color: #64748b;

            margin-bottom: 4px;
        }

        .price strong {
            font-size: 25px;
        }

        .price span {
            color: #64748b;

            font-size: 13px;
        }

        .features {
            display: grid;

            gap: 11px;

            margin-bottom: 25px;
        }

        .features div {
            color: #475569;

            font-size: 13px;
        }

        .features i {
            color: #16a34a;

            margin-right: 7px;
        }

        .choose-btn {
            width: 100%;

            margin-top: auto;

            padding: 14px;

            border: 0;

            border-radius: 12px;

            background: #2563eb;

            color: white;

            text-align: center;

            font-size: 14px;

            font-weight: 800;

            cursor: pointer;

            transition: .2s;
        }

        .choose-btn:hover {
            background: #1d4ed8;

            transform: translateY(-2px);
        }

        /* EMPTY */

        .empty {
            padding: 80px 20px;

            text-align: center;
        }

        .empty i {
            font-size: 50px;

            color: #94a3b8;
        }

        .empty h2 {
            margin: 20px 0 8px;
        }

        .empty p {
            color: #64748b;
        }

        /* FOOTER */

        footer {
            padding: 50px 7%;

            background: #0f172a;

            color: white;

            text-align: center;
        }

        footer p {
            margin-top: 10px;

            color: #94a3b8;

            font-size: 13px;
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {

            .package-grid {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 650px) {

            .navbar {
                padding: 0 5%;
            }

            .nav-links {
                display: none;
            }

            .package-grid {
                grid-template-columns: 1fr;
            }

            .hero {
                padding-top: 70px;
            }

            .hero h1 {
                letter-spacing: -2px;
            }

        }

    </style>

</head>


<body>


<header class="navbar">

    <div class="logo">
        YES<span>NET</span>
    </div>


    <nav class="nav-links">

        <a href="index.php">
            Home
        </a>

        <a href="coverage.php">
            Cek Coverage
        </a>

        <a href="paket.php">
            Paket
        </a>

        <a href="index.php#keunggulan">
            Keunggulan
        </a>

    </nav>


    <a
        href="login.php"
        class="login-btn"
    >
        Login
    </a>

</header>


<section class="hero">

    <div class="hero-badge">

        <i class="bi bi-wifi"></i>

        PAKET INTERNET YESNET

    </div>


    <h1>

        Pilih Internet yang

        <span>
            Cocok Untukmu
        </span>

    </h1>


    <p>

        Paket internet cepat dan stabil untuk
        bekerja, belajar, streaming, gaming,
        dan kebutuhan keluarga.

    </p>

</section>


<section class="package-section">


    <?php if ($totalPackages > 0): ?>

        <div class="package-count">

            Menampilkan

            <strong>
                <?= $totalPackages ?>
            </strong>

            paket internet tersedia.

        </div>


        <div class="package-grid">


            <?php foreach ($packages as $index => $package): ?>

                <?php

                $packageId = (int) (
                    $package['id'] ?? 0
                );

                $packageName = trim(
                    (string) (
                        $package['nama_paket']
                        ?? 'Paket Internet'
                    )
                );

                $speed = (int) (
                    $package['speed_mbps'] ?? 0
                );

                $harga = (float) (
                    $package['harga'] ?? 0
                );

                $deskripsi = trim(
                    (string) (
                        $package['deskripsi'] ?? ''
                    )
                );

                if ($deskripsi === '') {
                    $deskripsi =
                        'Internet cepat dan stabil untuk kebutuhan rumah.';
                }

                $isPopular = ($speed >= 100);

                ?>


                <article
                    class="package-card <?= $isPopular ? 'popular-card' : '' ?>"
                >

                    <?php if ($isPopular): ?>

                        <div class="popular">
                            <i class="bi bi-star-fill"></i>
                            POPULER
                        </div>

                    <?php endif; ?>


                    <div class="package-icon">

                        <i class="bi bi-wifi"></i>

                    </div>


                    <span class="package-label">
                        INTERNET RUMAH
                    </span>


                    <h2>
                        <?= e($packageName) ?>
                    </h2>


                    <div class="speed">

                        <strong>
                            <?= number_format($speed) ?>
                        </strong>

                        <span>
                            Mbps
                        </span>

                    </div>


                    <p class="description">

                        <?= e($deskripsi) ?>

                    </p>


                    <div class="price">

                        <small>
                            Harga mulai dari
                        </small>

                        <strong>
                            <?= e(rupiah($harga)) ?>
                        </strong>

                        <span>
                            /bulan
                        </span>

                    </div>


                    <div class="features">

                        <div>
                            <i class="bi bi-check-circle-fill"></i>
                            Internet cepat & stabil
                        </div>

                        <div>
                            <i class="bi bi-check-circle-fill"></i>
                            Customer Support
                        </div>

                        <div>
                            <i class="bi bi-check-circle-fill"></i>
                            Customer Portal
                        </div>

                        <div>
                            <i class="bi bi-check-circle-fill"></i>
                            Pembayaran mudah
                        </div>

                    </div>


                    <a
                        href="register.php?paket_id=<?= $packageId ?>"
                        class="choose-btn"
                    >
                        Pilih Paket Ini
                        <i class="bi bi-arrow-right"></i>
                    </a>

                </article>


            <?php endforeach; ?>


        </div>


    <?php else: ?>


        <div class="empty">

            <i class="bi bi-wifi-off"></i>

            <h2>
                Paket Belum Tersedia
            </h2>

            <p>
                Saat ini belum ada paket internet
                yang tersedia.
            </p>

        </div>

    <?php endif; ?>


</section>


<footer>

    <strong>
        YESNET
    </strong>

    <p>
        Internet cepat dan stabil untuk kebutuhanmu.
    </p>

    <p>
        © <?= date('Y') ?> YesNet. All Rights Reserved.
    </p>

</footer>


</body>

</html>
