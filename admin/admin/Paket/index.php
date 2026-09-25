<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$result = $conn->query("
    SELECT
        c.id,
        c.nama,
        c.telephone,
        c.email,
        c.status_langganan,
        p.nama_paket
    FROM customers c

    LEFT JOIN packages p
        ON p.id = c.paket_id

    ORDER BY c.id DESC
");
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<title>Pelanggan</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

</head>

<body>

<div class="container-fluid py-4">

<h3 class="mb-4">
Data Pelanggan
</h3>

<div class="table-responsive">

<table class="table table-bordered table-hover">

<thead>

<tr>

<th>No</th>
<th>Nama</th>
<th>Telepon</th>
<th>Email</th>
<th>Paket</th>
<th>Status</th>
<th>Aksi</th>

</tr>

</thead>

<tbody>

<?php

$no = 1;

while ($row = $result->fetch_assoc()):

?>

<tr>

<td>
<?= $no++ ?>
</td>

<td>
<?= htmlspecialchars($row['nama']) ?>
</td>

<td>
<?= htmlspecialchars($row['telephone'] ?? '-') ?>
</td>

<td>
<?= htmlspecialchars($row['email'] ?? '-') ?>
</td>

<td>
<?= htmlspecialchars($row['nama_paket'] ?? 'Belum memilih paket') ?>
</td>

<td>

<?php

$status = $row['status_langganan'];

$badge = match ($status) {

    'aktif' => 'success',

    'suspend' => 'warning',

    'berhenti' => 'danger',

    default => 'secondary'

};

?>

<span class="badge bg-<?= $badge ?>">

<?= htmlspecialchars($status) ?>

</span>

</td>

<td>

<a
    href="detail.php?id=<?= $row['id'] ?>"
    class="btn btn-sm btn-primary"
>
Detail
</a>

</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

</div>

</body>
</html>