<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nama = trim($_POST['nama_paket'] ?? '');
    $download = (int)($_POST['download'] ?? 0);
    $upload = (int)($_POST['upload'] ?? 0);
    $harga = (float)($_POST['harga'] ?? 0);
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    if ($nama === '') {

        $error = "Nama paket wajib diisi.";

    } elseif ($download <= 0) {

        $error = "Kecepatan download tidak valid.";

    } elseif ($upload <= 0) {

        $error = "Kecepatan upload tidak valid.";

    } elseif ($harga <= 0) {

        $error = "Harga paket tidak valid.";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO packages
            (
                nama_paket,
                kecepatan_download,
                kecepatan_upload,
                harga,
                deskripsi
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "siids",
            $nama,
            $download,
            $upload,
            $harga,
            $deskripsi
        );

        $stmt->execute();

        header("Location: index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<title>Tambah Paket</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

</head>

<body>

<div class="container py-4">

<h3>Tambah Paket</h3>

<?php if ($error !== ""): ?>

<div class="alert alert-danger">
    <?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<form method="POST">

<div class="mb-3">

<label class="form-label">
Nama Paket
</label>

<input
    type="text"
    name="nama_paket"
    class="form-control"
    required
>

</div>

<div class="row">

<div class="col-md-6 mb-3">

<label class="form-label">
Download Mbps
</label>

<input
    type="number"
    name="download"
    class="form-control"
    min="1"
    required
>

</div>

<div class="col-md-6 mb-3">

<label class="form-label">
Upload Mbps
</label>

<input
    type="number"
    name="upload"
    class="form-control"
    min="1"
    required
>

</div>

</div>

<div class="mb-3">

<label class="form-label">
Harga
</label>

<input
    type="number"
    name="harga"
    class="form-control"
    min="1"
    required
>

</div>

<div class="mb-3">

<label class="form-label">
Deskripsi
</label>

<textarea
    name="deskripsi"
    class="form-control"
    rows="4"
></textarea>

</div>

<button class="btn btn-primary">
    Simpan
</button>

<a
    href="index.php"
    class="btn btn-secondary"
>
    Kembali
</a>

</form>

</div>

</body>
</html>