<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
requireRole('customer');

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$complaintId || $complaintId < 1) {
    http_response_code(404);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT a.mime_type, a.contents
    FROM complaint_attachments a
    JOIN complaint c ON c.id = a.complaint_id
    JOIN customers cu ON cu.id = c.id_customer
    WHERE a.complaint_id = ? AND cu.user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $complaintId, $userId);
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
