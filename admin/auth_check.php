<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$adminPosition = strpos($scriptName, '/admin/');
$basePath = $adminPosition === false ? '' : substr($scriptName, 0, $adminPosition);

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ' . $basePath . '/admin/login.php');
    exit;
}

$stmt = $conn->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
$userId = (int) $_SESSION['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$role = strtolower(trim((string) ($user['role'] ?? '')));
$status = strtolower(trim((string) ($user['status'] ?? '')));
if (
    !$user ||
    $role !== 'admin' ||
    !in_array($status, ['1', 'active', 'aktif'], true)
) {
    unset($_SESSION['user_id'], $_SESSION['nama'], $_SESSION['username'], $_SESSION['role']);
    $_SESSION['login_error'] = 'Sesi administrator tidak valid. Silakan masuk kembali.';
    header('Location: ' . $basePath . '/admin/login.php');
    exit;
}