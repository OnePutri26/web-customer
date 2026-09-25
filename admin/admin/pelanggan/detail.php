<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$id) {
    die("ID pelanggan tidak valid.");
}

$stmt = $conn->prepare("
    SELECT
        c.*,
        u.username,
        p.nama_paket,
        p.kecepatan_download,
        p.kecepatan_upload,
        p.harga
    FROM customers c

    INNER JOIN users u
        ON u.id = c.user_id

    LEFT JOIN packages p
        ON p.id = c.paket_id

    WHERE c.id = ?

    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Pelanggan tidak ditemukan.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM invoices
    WHERE customer_id = ?
    ORDER BY id DESC
    LIMIT 10
");

$stmt->bind_param("i", $id);
$stmt->execute();

$invoices = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<title>Detail Pelanggan</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

</head>

<body>

<div class="container py-4">

<h3 class="mb-4">
Detail Pelanggan
</h3>

<div class="card mb-4">

<div class="card-body">

<div class="row">

<div class="col-md-6">

<p>
<strong>Nama:</strong>
<?= htmlspecialchars($customer['nama']) ?>
</p>

<p>
<strong>Username:</strong>
<?= htmlspecialchars($customer['username']) ?>
</p>

<p>
<strong>Email:</strong>
<?= htmlspecialchars($customer['email']) ?>
</p>

<p>
<strong>Telepon:</strong>
<?= htmlspecialchars($customer['telephone']) ?>
</p>

</div>

<div class="col-md-6">

<p>
<strong>Paket:</strong>
<?= htmlspecialchars(
    $customer['nama_paket'] ?? 'Belum memilih'
) ?>
</p>

<p>
<strong>Kecepatan:</strong>

<?= $customer['kecepatan_download']
    ? $customer['kecepatan_download'] . ' Mbps'
    : '-'
?>

</p>

<p>
<strong>Status:</strong>

<?= htmlspecialchars(
    $customer['status_langganan']
) ?>

</p>

</div>

</div>

<hr>

<p>
<strong>Alamat:</strong><br>

<?= nl2br(
    htmlspecialchars($customer['alamat'])
) ?>

</p>

</div>

</div>

<h4>
Riwayat Tagihan
</h4>

<table class="table table-bordered">

<thead>

<tr>

<th>Invoice</th>
<th>Periode</th>
<th>Jumlah</th>
<th>Jatuh Tempo</th>
<th>Status</th>

</tr>

</thead>

<tbody>

<?php while ($invoice = $invoices->fetch_assoc()): ?>

<tr>

<td>
<?= htmlspecialchars($invoice['nomor_invoice']) ?>
</td>

<td>
<?= $invoice['periode_mulai'] ?>
-
<?= $invoice['periode_selesai'] ?>
</td>

<td>
Rp <?= number_format(
    $invoice['jumlah'],
    0,
    ',',
    '.'
) ?>
</td>

<td>
<?= $invoice['jatuh_tempo'] ?>
</td>

<td>
<?= htmlspecialchars($invoice['status']) ?>
</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

<a
href="index.php"
class="btn btn-secondary"
>
Kembali
</a>

</div>

</body>
</html>