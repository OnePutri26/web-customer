<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$sql = "
    SELECT
        c.id,
        c.nomor_tiket,
        c.judul,
        c.kategori,
        c.status,
        c.priority,
        c.created_at,
        cu.nama AS nama_customer,
        t.nama AS nama_teknisi

    FROM complaints c

    INNER JOIN customers cu
        ON cu.id = c.customer_id

    LEFT JOIN technicians t
        ON t.id = c.technician_id

    ORDER BY c.created_at DESC
";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<title>Pengaduan</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

</head>

<body>

<div class="container-fluid py-4">

<h3>
Pengaduan Pelanggan
</h3>

<table class="table table-bordered table-hover">

<thead>

<tr>

<th>Ticket</th>
<th>Pelanggan</th>
<th>Keluhan</th>
<th>Prioritas</th>
<th>Teknisi</th>
<th>Status</th>
<th>Aksi</th>

</tr>

</thead>

<tbody>

<?php while ($row = $result->fetch_assoc()): ?>

<tr>

<td>
<?= htmlspecialchars($row['nomor_tiket']) ?>
</td>

<td>
<?= htmlspecialchars($row['nama_customer']) ?>
</td>

<td>
<?= htmlspecialchars($row['judul']) ?>
</td>

<td>
<?= htmlspecialchars($row['priority']) ?>
</td>

<td>
<?= htmlspecialchars(
    $row['nama_teknisi'] ?? 'Belum ditugaskan'
) ?>
</td>

<td>
<?= htmlspecialchars($row['status']) ?>
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

</body>
</html>