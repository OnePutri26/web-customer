<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/service_notifications.php';
requireRole('customer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Metode tidak diizinkan.']);
    exit;
}

$csrf = (string) ($_SERVER['HTTP_X_YESNET_CSRF'] ?? '');
$sessionCsrf = (string) ($_SESSION['csrf_notification'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesi notifikasi tidak valid. Muat ulang halaman.']);
    exit;
}

if (!yesnetNotificationTablesAvailable($conn)) {
    http_response_code(503);
    echo json_encode(['error' => 'Notifikasi belum tersedia.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'read_all') {
        yesnetNotificationMarkAllRead($conn, $userId);
    } elseif ($action === 'read') {
        $notificationId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Notifikasi tidak valid.']);
            exit;
        }
        yesnetNotificationMarkRead($conn, $userId, $notificationId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Aksi notifikasi tidak dikenal.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'unread' => yesnetNotificationUnreadCount($conn, $userId),
    ]);
} catch (Throwable $e) {
    error_log('Customer notification action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Status notifikasi gagal diperbarui.']);
}
