<?php

session_start();

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        nama,
        no_hp,
        alamat,
        paket_id,
        status_langganan
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Query customer gagal: " . $conn->error);
}

$stmt->bind_param("i", $userId);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();


if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$customerId = (int) $customer['id'];


/*
|--------------------------------------------------------------------------
| CEK STATUS LANGGANAN
|--------------------------------------------------------------------------
*/

$statusLangganan =
    $customer['status_langganan']
    ?? 'belum_berlangganan';


/*
|--------------------------------------------------------------------------
| JIKA SUDAH AKTIF
|--------------------------------------------------------------------------
*/

if ($statusLangganan === 'active') {

    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL PAKET WIFI
|--------------------------------------------------------------------------
*/

$packages = [];

$stmt = $conn->prepare("
    SELECT
        id,
        nama_paket,
        kecepatan,
        harga,
        deskripsi
    FROM paket_wifi
    WHERE status = 'active'
    ORDER BY harga ASC
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $packages[] = $row;
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| PROSES PENGAJUAN
|--------------------------------------------------------------------------
*/

$error = '';
$success = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $packageId = (int) ($_POST['paket_id'] ?? 0);

    $alamat = trim(
        $_POST['alamat_pemasangan'] ?? ''
    );

    $tanggal =
        $_POST['tanggal_instalasi'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if ($packageId <= 0) {

        $error = "Silakan pilih paket WiFi.";

    } elseif ($alamat === '') {

        $error = "Alamat pemasangan wajib diisi.";

    } elseif ($tanggal === '') {

        $error = "Tanggal instalasi wajib dipilih.";

    } elseif ($tanggal < date('Y-m-d')) {

        $error = "Tanggal instalasi tidak boleh sebelum hari ini.";

    }


    /*
    |--------------------------------------------------------------------------
    | CEK PENGAJUAN YANG MASIH BERJALAN
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $stmt = $conn->prepare("
            SELECT id
            FROM pengajuan_pemasangan
            WHERE customer_id = ?
            AND status IN (
                'diajukan',
                'verifikasi',
                'disetujui',
                'dijadwalkan',
                'instalasi'
            )
            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $customerId
            );

            $stmt->execute();

            $existing =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if ($existing) {

                $error =
                    "Kamu masih memiliki pengajuan pemasangan yang sedang diproses.";

            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SIMPAN PENGAJUAN
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $stmt = $conn->prepare("
            INSERT INTO pengajuan_pemasangan
            (
                customer_id,
                paket_id,
                alamat_pemasangan,
                status,
                catatan
            )
            VALUES (?, ?, ?, 'diajukan', ?)
        ");

        if (!$stmt) {

            $error =
                "Gagal menyiapkan pengajuan: "
                . $conn->error;

        } else {

            $catatan =
                "Tanggal instalasi yang diinginkan: "
                . $tanggal;


            $stmt->bind_param(
                "iiss",
                $customerId,
                $packageId,
                $alamat,
                $catatan
            );


            if ($stmt->execute()) {

                /*
                |--------------------------------------------------------------------------
                | UBAH STATUS CUSTOMER
                |--------------------------------------------------------------------------
                */

                $updateCustomer = $conn->prepare("
                    UPDATE customers
                    SET
                        status_langganan = 'pending',
                        paket_id = ?
                    WHERE id = ?
                ");

                if ($updateCustomer) {

                    $updateCustomer->bind_param(
                        "ii",
                        $packageId,
                        $customerId
                    );

                    $updateCustomer->execute();

                    $updateCustomer->close();
                }


                /*
                |--------------------------------------------------------------------------
                | SELESAI
                |--------------------------------------------------------------------------
                */

                $stmt->close();

                header(
                    "Location: installation.php?success=1"
                );

                exit;

            } else {

                $error =
                    "Pengajuan gagal disimpan: "
                    . $stmt->error;

                $stmt->close();
            }
        }
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

    <title>
        Pengajuan Pemasangan WiFi
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/theme.css">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

</head>


<body>


<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-8">


            <!-- HEADER -->

            <div class="mb-4">

                <h3 class="fw-bold">

                    <i class="bi bi-wifi"></i>

                    Pengajuan Pemasangan WiFi

                </h3>

                <p class="text-muted">

                    Silakan pilih paket dan isi data
                    pemasangan WiFi.

                </p>

            </div>


            <!-- ERROR -->

            <?php if ($error): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <i class="bi bi-exclamation-circle"></i>

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <!-- FORM -->

            <div class="card shadow-sm border-0">

                <div class="card-body p-4">


                    <form method="POST">


                        <!-- CUSTOMER -->

                        <div class="mb-4">

                            <label class="form-label fw-semibold">

                                Nama Customer

                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= htmlspecialchars($customer['nama']) ?>"
                                readonly
                            >

                        </div>


                        <!-- PAKET -->

                        <div class="mb-4">

                            <label
                                class="form-label fw-semibold"
                            >

                                Pilih Paket WiFi

                            </label>


                            <select
                                name="paket_id"
                                class="form-select"
                                required
                            >

                                <option value="">

                                    -- Pilih Paket --

                                </option>


                                <?php foreach ($packages as $package): ?>

                                    <option
                                        value="<?= $package['id'] ?>"
                                        <?= (
                                            isset($_POST['paket_id'])
                                            && $_POST['paket_id']
                                            == $package['id']
                                        )
                                        ? 'selected'
                                        : ''
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $package['nama_paket']
                                        ) ?>

                                        -

                                        <?= htmlspecialchars(
                                            $package['kecepatan']
                                        ) ?>

                                        -

                                        <?= rupiah(
                                            $package['harga']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- ALAMAT -->

                        <div class="mb-4">

                            <label
                                class="form-label fw-semibold"
                            >

                                Alamat Pemasangan

                            </label>


                            <textarea
                                name="alamat_pemasangan"
                                class="form-control"
                                rows="4"
                                placeholder="Masukkan alamat lengkap pemasangan WiFi"
                                required
                            ><?= htmlspecialchars(
                                $_POST['alamat_pemasangan']
                                ?? $customer['alamat']
                                ?? ''
                            ) ?></textarea>

                        </div>


                        <!-- TANGGAL -->

                        <div class="mb-4">

                            <label
                                class="form-label fw-semibold"
                            >

                                Tanggal Instalasi yang Diinginkan

                            </label>


                            <input
                                type="date"
                                name="tanggal_instalasi"
                                class="form-control"
                                min="<?= date('Y-m-d') ?>"
                                value="<?= htmlspecialchars(
                                    $_POST['tanggal_instalasi']
                                    ?? ''
                                ) ?>"
                                required
                            >


                            <small class="text-muted">

                                Tanggal ini merupakan
                                permintaan awal dan dapat
                                berubah sesuai jadwal teknisi.

                            </small>

                        </div>


                        <!-- BUTTON -->

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >

                                <i class="bi bi-send"></i>

                                Kirim Pengajuan

                            </button>


                            <a
                                href="dashboard.php"
                                class="btn btn-secondary"
                            >

                                Kembali

                            </a>

                        </div>


                    </form>

                </div>

            </div>


        </div>

    </div>

</div>


</body>

</html>
