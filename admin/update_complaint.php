<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

$sessionToken = $_SESSION['complaint_admin_csrf'] ?? '';
$postToken = $_POST['csrf'] ?? '';
if (
    !is_string($sessionToken)
    || !is_string($postToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $postToken)
) {
    http_response_code(403);
    exit('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$status = (string) ($_POST['status'] ?? '');
$allowed = [
    'open',
    'verified',
    'processing',
    'assigned',
    'waiting_customer',
    'resolved',
    'closed',
];

if (!$id || $id < 1 || !in_array($status, $allowed, true)) {
    http_response_code(400);
    exit('Status atau pengaduan tidak valid.');
}

try {
    $stmt = $conn->prepare("
        UPDATE complaints
        SET status = ?
        WHERE id = ?
    ");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('Admin complaint update failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Status pengaduan belum dapat diperbarui. Silakan coba kembali.');
}

header('Location: complaints.php', true, 303);
exit;
