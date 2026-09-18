<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('admin');

$id = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$allowed = [
    'open',
    'verified',
    'processing',
    'assigned',
    'waiting_customer',
    'resolved',
    'closed'
];

if (!in_array($status, $allowed)) {
    die("Status tidak valid.");
}

$stmt = $conn->prepare(
    "UPDATE complaints
     SET status = ?
     WHERE id = ?"
);

$stmt->bind_param(
    "si",
    $status,
    $id
);

$stmt->execute();

header("Location: complaints.php");
exit;