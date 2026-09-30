<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

$error = '';
$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $alamat = trim($_POST['alamat'] ?? '');
    $kodePos = trim($_POST['kode_pos'] ?? '');

    if ($alamat === '') {

        $error = 'Alamat wajib diisi.';

    } else {

        /*
         * Contoh pengecekan coverage.
         *
         * Nantinya bagian ini bisa diganti
         * dengan data coverage jaringan YesNet.
         */

        $tersedia = false;

        if ($kodePos !== '') {

            $stmt = $conn->prepare("
                SELECT id
                FROM coverage_areas
                WHERE kode_pos = ?
                AND status = 1
                LIMIT 1
            ");

            $stmt->bind_param(
                's',
                $kodePos
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $tersedia =
                $result->num_rows > 0;
        }

        $_SESSION['coverage'] = [
            'alamat' => $alamat,
            'kode_pos' => $kodePos,
            'tersedia' => $tersedia
        ];

        $hasil = $tersedia;
    }
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

    <title>Cek Coverage - YesNet</title>

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
            COVERAGE YESNET
        </span>

        <h2>
            Cek ketersediaan jaringan
        </h2>

        <p>
            Masukkan alamat pemasangan
            untuk mengetahui apakah layanan
            YesNet tersedia di lokasi kamu.
        </p>

    </div>


    <div
        style="
            max-width:600px;
            margin:auto;
            background:#fff;
            padding:35px;
            border-radius:18px;
            box-shadow:0 15px 45px rgba(0,0,0,.08);
        "
    >

        <?php if ($error): ?>

            <div
                style="
                    padding:15px;
                    background:#fff1f1;
                    color:#b42318;
                    border-radius:10px;
                    margin-bottom:20px;
                "
            >
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <?php if ($hasil === null): ?>

            <form method="POST">

                <label>
                    Alamat Lengkap
                </label>

                <textarea
                    name="alamat"
                    required
                    style="
                        width:100%;
                        min-height:120px;
                        padding:14px;
                        border:1px solid #ddd;
                        border-radius:10px;
                    "
                ></textarea>


                <label>
                    Kode Pos
                </label>

                <input
                    type="text"
                    name="kode_pos"
                    placeholder="Contoh: 612xx"
                    style="
                        width:100%;
                        padding:14px;
                        margin-top:8px;
                        border:1px solid #ddd;
                        border-radius:10px;
                    "
                >


                <button
                    type="submit"
                    class="btn primary"
                    style="
                        width:100%;
                        border:none;
                        margin-top:20px;
                        cursor:pointer;
                    "
                >
                    Cek Ketersediaan
                </button>

            </form>


        <?php elseif ($hasil === true): ?>

            <div
                style="
                    padding:25px;
                    background:#eaf8ef;
                    color:#177245;
                    border-radius:12px;
                "
            >

                <h3>
                    ✓ Coverage Tersedia
                </h3>

                <p>
                    Alamat kamu dapat dilayani
                    oleh jaringan YesNet.
                </p>

            </div>


            <a
                href="paket.php"
                class="btn primary"
                style="
                    display:block;
                    text-align:center;
                    margin-top:20px;
                "
            >
                Pilih Paket
            </a>


        <?php else: ?>

            <div
                style="
                    padding:25px;
                    background:#fff4e5;
                    color:#9a6700;
                    border-radius:12px;
                "
            >

                <h3>
                    Coverage Belum Tersedia
                </h3>

                <p>
                    Saat ini alamat kamu belum
                    terjangkau jaringan YesNet.
                </p>

            </div>


            <a
                href="coverage-request.php"
                class="btn primary"
                style="
                    display:block;
                    text-align:center;
                    margin-top:20px;
                "
            >
                Request Coverage
            </a>

        <?php endif; ?>


    </div>

</section>

</body>
</html>

