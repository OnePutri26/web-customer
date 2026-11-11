<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role'])
) {
    header('Location: ' . adminLoginUrl());
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
    !in_array($status, ['1', 'active', 'aktif'], true) ||
    strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== $role
) {
    adminClearSession();
    $_SESSION['admin_login_error'] = 'Sesi administrator tidak valid. Silakan masuk kembali.';
    header('Location: ' . adminLoginUrl());
    exit;
}

if (!isset($_SESSION['admin_permissions']) || !is_array($_SESSION['admin_permissions'])) {
    $_SESSION['admin_permissions'] = adminPermissionsForRole($role);
}

adminRequirePermission(adminPermissionForRequest());