<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$complaintId || $complaintId < 1) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare('SELECT mime_type, contents FROM complaint_attachments WHERE complaint_id = ? LIMIT 1');
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$attachment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attachment || !in_array($attachment['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . strlen($attachment['contents']));
header('Content-Disposition: inline; filename="complaint-evidence"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $attachment['contents'];
