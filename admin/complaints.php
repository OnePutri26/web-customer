<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('admin');

$sql = "
SELECT
    c.*,
    cu.name AS customer_name,
    cu.phone
FROM complaints c
JOIN customers cu
    ON cu.id = c.customer_id
ORDER BY c.created_at DESC
";

$result = $conn->query($sql);
?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<title>Complaint</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

</head>

<body>

<div class="container py-4">

<h3>
🎫 Data Complaint
</h3>

<table class="table table-hover">

<thead>

<tr>

<th>Kode</th>
<th>Customer</th>
<th>Phone</th>
<th>Subject</th>
<th>Priority</th>
<th>Status</th>

</tr>

</thead>

<tbody>

<?php while ($row = $result->fetch_assoc()): ?>

<tr>

<td>
<?= htmlspecialchars($row['complaint_code']) ?>
</td>

<td>
<?= htmlspecialchars($row['customer_name']) ?>
</td>

<td>
<?= htmlspecialchars($row['phone']) ?>
</td>

<td>
<?= htmlspecialchars($row['subject']) ?>
</td>

<td>
<?= strtoupper($row['priority']) ?>
</td>

<td>

<form method="POST" action="update_complaint.php">

<input
type="hidden"
name="id"
value="<?= $row['id'] ?>"
>

<select
name="status"
class="form-select form-select-sm"
onchange="this.form.submit()"
>

<?php

$statuses = [
    'open',
    'verified',
    'processing',
    'assigned',
    'waiting_customer',
    'resolved',
    'closed'
];

foreach ($statuses as $status):

?>

<option
value="<?= $status ?>"
<?= $row['status'] === $status ? 'selected' : '' ?>
>

<?= strtoupper($status) ?>

</option>

<?php endforeach; ?>

</select>

</form>

</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

</body>

</html>