<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminPosition = strpos($scriptName, '/admin/');
    $basePath = $adminPosition === false ? '' : substr($scriptName, 0, $adminPosition);
    header('Location: ' . $basePath . '/admin/login.php');
    exit;
}