<?php
require __DIR__ . '/complaint.php';
require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT id FROM customers WHERE user_id = ?"
);

$stmt->bind_param("i", $userId);

if (!$stmt->execute()) {
    die("Query customer gagal: " . $stmt->error);
}

$customer =
    $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $category = trim((string) ($_POST['category'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? 'low'));

    if ($category === '' || $subject === '' || $description === '') {
        die("Data complaint belum lengkap.");
    }

    $code =
        "CMP" . date("YmdHis") . rand(10,99);

    $stmt = $conn->prepare(
        "INSERT INTO complaint
        (
            id_customer,
            complaint_code,
            category,
            subject,
            description,
            priority
        )
        VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        die("Query insert complaint gagal: " . $conn->error);
    }

    $stmt->bind_param(
        "isssss",
        $customer['id'],
        $code,
        $category,
        $subject,
        $description,
        $priority
    );

    if (!$stmt->execute()) {
        die("Gagal menyimpan complaint: " . $stmt->error);
    }

    header("Location: complaint.php");
    exit;
}
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

<link rel="stylesheet" href="assets/css/theme.css">

</head>

<body>

<div class="container py-4">

<h3>
🎫 Buat Complaint
</h3>

<form method="POST">

<label class="form-label">
Jenis Gangguan
</label>

<select
name="category"
class="form-select mb-3"
required
>

<option value="internet_down">
Internet Down
</option>

<option value="slow_connection">
Internet Lambat
</option>

<option value="wifi_problem">
Masalah WiFi
</option>

<option value="router_problem">
Masalah Router
</option>

<option value="billing">
Billing
</option>

<option value="other">
Lainnya
</option>

</select>

<label>
Judul
</label>

<input
name="subject"
class="form-control mb-3"
required
>

<label>
Prioritas
</label>

<select
name="priority"
class="form-select mb-3"
>

<option value="low">Low</option>
<option value="medium">Medium</option>
<option value="high">High</option>
<option value="critical">Critical</option>

</select>

<label>
Deskripsi Gangguan
</label>

<textarea
name="description"
class="form-control mb-3"
rows="5"
required
></textarea>

<button class="btn btn-danger">
Kirim Complaint
</button>

</form>

</div>

</body>

</html>
