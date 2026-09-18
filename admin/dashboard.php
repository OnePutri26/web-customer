<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('admin');

$customers =
$conn->query("SELECT COUNT(*) total FROM customers")
->fetch_assoc()['total'];

$installations =
$conn->query(
    "SELECT COUNT(*) total
     FROM installations
     WHERE status NOT IN ('completed','cancelled')"
)
->fetch_assoc()['total'];

$complaints =
$conn->query(
    "SELECT COUNT(*) total
     FROM complaints
     WHERE status NOT IN ('resolved','closed')"
)
->fetch_assoc()['total'];

$technicians =
$conn->query(
    "SELECT COUNT(*) total
     FROM technicians"
)
->fetch_assoc()['total'];

?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<title>Admin Dashboard</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

</head>

<body>

<nav class="navbar navbar-dark bg-dark">

<div class="container">

<span class="navbar-brand">
📡 WiFi Admin
</span>

<a
href="../logout.php"
class="btn btn-light btn-sm"
>
Logout
</a>

</div>

</nav>

<div class="container py-4">

<h3>
Dashboard Admin
</h3>

<div class="row g-3 mt-2">

<div class="col-md-3">

<div class="card shadow-sm">

<div class="card-body">

<h6>Customer</h6>

<h2>
<?= $customers ?>
</h2>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card shadow-sm">

<div class="card-body">

<h6>Pemasangan</h6>

<h2>
<?= $installations ?>
</h2>

<a href="installations.php">
Lihat
</a>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card shadow-sm">

<div class="card-body">

<h6>Complaint</h6>

<h2>
<?= $complaints ?>
</h2>

<a href="complaints.php">
Lihat
</a>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card shadow-sm">

<div class="card-body">

<h6>Teknisi</h6>

<h2>
<?= $technicians ?>
</h2>

<a href="technicians.php">
Lihat
</a>

</div>

</div>

</div>

</div>

</div>

</body>

</html>