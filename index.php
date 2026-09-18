<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';

switch ($role) {

    case 'admin':
        header("Location: admin/dashboard.php");
        exit;

    case 'teknisi':
        header("Location: teknisi/dashboard.php");
        exit;

    case 'customer':
        header("Location: customer/dashboard.php");
        exit;

    default:
        session_destroy();
        header("Location: login.php");
        exit;
}