<?php

session_start();

require_once __DIR__ . '/config/database.php';

$paket = [];

$result = $conn->query("
    SELECT *
    FROM paket
    ORDER BY id ASC
");

while ($row = $result->fetch_assoc()) {
    $paket[] = $row;
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

    <title>Paket Internet - YesNet</title>

    <link
        rel="stylesheet"
        href="landing.css"
    >

</head>

<body>

<header class="navbar">

    <div class="logo">
        YES<span>NET</span>
    </div>

    <a
        href="index.php"
        class="nav-login"
    >
        Beranda
    </a>

</header>


<section class="section">

    <div class="section-title">

        <span>
            PAKET INTERNET
        </span>

        <h2>
            Pilih paket sesuai kebutuhan
        </h2>

        <p>
            Pilih paket internet YesNet
            yang paling sesuai.
        </p>

    </div>


    <div class="features">

        <?php foreach ($paket as $p): ?>

            <?php

            $nama =
                $p['nama_paket']
                ?? $p['nama']
                ?? 'Paket Internet';

            $harga =
                $p['harga']
                ?? 0;

            $speed =
                $p['kecepatan']
                ?? $p['speed']
                ?? '-';

            ?>

            <div class="feature">

                <h3>
                    <?= htmlspecialchars($nama) ?>
                </h3>

                <div
                    style="
                        color:#1264d8;
                        font-size:30px;
                        font-weight:800;
                        margin:15px 0;
                    "
                >
                    <?= htmlspecialchars($speed) ?>
                </div>

                <h3>
                    Rp
                    <?= number_format(
                        $harga,
                        0,
                        ',',
                        '.'
                    ) ?>

                    <small>
                        /bulan
                    </small>
                </h3>

                <p>
                    Paket internet YesNet
                    untuk kebutuhan rumah.
                </p>

                <a
                    href="detail-paket.php?id=<?= (int)$p['id'] ?>"
                    class="btn primary"
                    style="
                        display:block;
                        text-align:center;
                        margin-top:20px;
                    "
                >
                    Lihat Detail
                </a>

            </div>

        <?php endforeach; ?>

    </div>

</section>

</body>
</html>
