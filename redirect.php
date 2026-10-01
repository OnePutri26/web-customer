<?php

session_start();

// User belum login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';

switch ($role) {

    // Admin
    case 'admin':
        header("Location: admin/dashboard.php");
        exit;

    // Teknisi
    case 'teknisi':
        header("Location: teknisi/dashboard.php");
        exit;

    // Customer
    case 'customer':
        header("Location: customer/dashboard.php");
        exit;

    // Role tidak dikenal
    default:
        session_destroy();
        header("Location: login.php");
        exit;
}
